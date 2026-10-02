<?php

declare(strict_types=1);

namespace Starlite;

use Starlite\Blog\Blog;
use Starlite\Seo\Seo;
use starfederation\datastar\Consts;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\SameOriginCsrfTokenManager;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class Kernel
{
    public readonly Environment $twig;
    public readonly Datastar $datastar;
    public readonly Router $router;
    public readonly Blog $blog;
    public readonly Vite $vite;
    public readonly Seo $seo;
    public readonly string $cacheDir;

    private readonly RequestStack $requests;
    private readonly SameOriginCsrfTokenManager $csrf;

    /** Builds the kernel from the app's config/app.php and config/routes.php. `$debug` overrides APP_DEBUG. */
    public static function boot(string $root, ?bool $debug = null): self
    {
        self::loadEnv($root);
        $config = require $root . '/config/app.php';
        $debug ??= $config['debug'];

        // Debug: Symfony's exception page, and PHP warnings throw.
        // Production: uncaught exceptions are logged and rendered by handle(); PHP warnings are only logged.
        $debug ? Debug::enable() : ErrorHandler::register()->throwAt(0, true);

        // Behind a reverse proxy / load balancer, trust its X-Forwarded-* headers (needed for the CSRF origin check).
        if ($config['trusted_proxies'] !== []) {
            Request::setTrustedProxies(
                $config['trusted_proxies'],
                Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $app = new self($root, $config['secret'], $debug, $config['url'], $config['site']);
        (require $root . '/config/routes.php')($app);

        return $app;
    }

    /**
     * Loads `.env` from the project root, if present. Variables already set by the server
     * (DDEV, PHP-FPM `env[...]`, nginx `fastcgi_param`) always win over the file.
     */
    public static function loadEnv(string $root): void
    {
        if (is_file($root . '/.env')) {
            (new Dotenv())->usePutenv()->load($root . '/.env');
        }
    }

    public function __construct(
        public readonly string $root,
        string $secret,
        public readonly bool $debug = false,
        string $url = 'http://localhost',
        array $site = ['name' => 'Starlite', 'description' => '', 'locale' => 'en_US', 'image' => null, 'author' => null],
    ) {
        $this->cacheDir = $root . '/var/cache';
        $this->router = new Router($this->cacheDir, $debug);
        $this->blog = new Blog($root . '/content/blog', $this->cacheDir . '/blog.php', $debug);
        $this->vite = new Vite($root, $this->cacheDir, $debug);
        $this->datastar = new Datastar($secret);
        $this->seo = new Seo(rtrim($url, '/'), $site);
        $this->requests = new RequestStack();
        $this->csrf = new SameOriginCsrfTokenManager($this->requests);

        $this->twig = new Environment(new FilesystemLoader($root . '/templates'), [
            'cache' => $debug ? false : $this->cacheDir . '/twig',
            'auto_reload' => $debug,
            'debug' => $debug,
            'strict_variables' => $debug,
        ]);
        $this->twig->addExtension($this->datastar);
        $this->twig->addExtension($this->vite);
        $this->twig->addExtension($this->seo);
        $this->twig->addFunction(new TwigFunction('path', $this->router->generate(...)));
        $this->twig->addGlobal('blog', $this->blog);

        // Endpoint used by datastar.get() / post() / put() / patch() / delete().
        $this->route(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/datastar', $this->renderDatastarTemplate(...), 'datastar');
    }

    // --- Routing --------------------------------------------------------------

    /**
     * The handler is a closure, a [Controller::class, 'method'] pair or an invokable controller class.
     * Route placeholders are passed to it as named arguments. It returns a string (HTML) or a Response.
     *
     * @param string|list<string>                                  $methods
     * @param \Closure|array{class-string, string}|class-string     $handler
     * @param array<string, string>                                $requirements regex per placeholder, e.g. ['slug' => '[a-z0-9-]+']
     */
    public function route(
        string|array $methods,
        string $path,
        \Closure|array|string $handler,
        ?string $name = null,
        array $requirements = [],
        bool $csrf = true,
    ): void {
        $this->router->add((array) $methods, $path, $handler, $name, $requirements, $csrf);
    }

    public function get(string $path, \Closure|array|string $handler, ?string $name = null, array $requirements = []): void
    {
        $this->route('GET', $path, $handler, $name, $requirements);
    }

    public function post(string $path, \Closure|array|string $handler, ?string $name = null, array $requirements = []): void
    {
        $this->route('POST', $path, $handler, $name, $requirements);
    }

    public function run(): void
    {
        $request = Request::createFromGlobals();
        $this->handle($request)->prepare($request)->send();
    }

    public function handle(Request $request): Response
    {
        $this->requests->push($request);
        $this->seo->reset($request->getPathInfo());
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            if ($this->debug) {
                throw $e;
            }
            error_log((string) $e);
            $response = $this->error(500, 'Something went wrong.');
        } finally {
            $this->requests->pop();
        }

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);

        // Pages are the same for every visitor (no sessions), so caches may store them
        // and revalidate cheaply: an unchanged page is answered with an empty 304.
        if ($request->isMethodCacheable() && $response->isOk() && !$response instanceof StreamedResponse) {
            $response->setEtag(hash('xxh128', (string) $response->getContent()));
            $response->setPublic();
            $response->headers->addCacheControlDirective('no-cache');
            $response->isNotModified($request);
        }

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        try {
            [$handler, $params, $csrf] = $this->router->match($request->getMethod(), $request->getPathInfo());
        } catch (ResourceNotFoundException) {
            return $this->error(404, 'Not found.');
        } catch (MethodNotAllowedException $e) {
            return $this->error(405, 'Method not allowed.', ['Allow' => implode(', ', $e->getAllowedMethods())]);
        }

        // Stateless CSRF protection: the browser's Sec-Fetch-Site / Origin headers must show the
        // request comes from this site. No token, cookie or session needed.
        if ($csrf && !$request->isMethodSafe() && !$this->csrf->isTokenValid(new CsrfToken('starlite', 'csrf-token'))) {
            return $this->error(403, 'Cross-site request blocked.');
        }

        $result = $this->call($handler, $params);

        return $result instanceof Response ? $result : new Response((string) $result, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Calls a route handler; controller classes get the kernel as their only constructor argument. */
    private function call(\Closure|array|string $handler, array $params): mixed
    {
        if ($handler instanceof \Closure) {
            return $handler(...$params);
        }
        [$class, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];

        return (new $class($this))->{$method}(...$params);
    }

    // --- Responses ------------------------------------------------------------

    public function request(): Request
    {
        return $this->requests->getCurrentRequest() ?? throw new \LogicException('No request is being handled.');
    }

    public function render(string $template, array $vars = []): string
    {
        return $this->twig->render($template, $vars);
    }

    /** Renders a template (with the request's Datastar signals) and returns it as an SSE response. */
    public function stream(string $template, array $vars = []): StreamedResponse
    {
        $vars['signals'] = $this->signals();

        return $this->datastar->response($this->twig->render($template, $vars));
    }

    /** An error page, or plain text for Datastar requests, which expect SSE rather than HTML. */
    public function error(int $status, string $message, array $headers = []): Response
    {
        $isDatastar = $this->requests->getCurrentRequest()?->headers->has('Datastar-Request') ?? false;
        if (!$isDatastar && $this->twig->getLoader()->exists('_error.twig')) {
            $this->seo->noindex();
            try {
                $html = $this->render('_error.twig', ['status' => $status, 'message' => $message]);

                return new Response($html, $status, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
            } catch (\Throwable $e) {
                error_log((string) $e);
            }
        }

        return new Response($message, $status, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** Signals sent by Datastar: in the `datastar` query parameter for GET/DELETE, else in the JSON body. */
    private function signals(): array
    {
        $request = $this->request();
        $json = in_array($request->getMethod(), ['GET', 'DELETE'], true)
            ? $request->query->getString(Consts::DATASTAR_KEY)
            : $request->getContent();
        $signals = $json !== '' ? json_decode($json, true) : [];

        return is_array($signals) ? $signals : [];
    }

    private function renderDatastarTemplate(): Response
    {
        $config = $this->datastar->decode($this->request()->query->getString('config'));
        if ($config === null) {
            return $this->error(400, 'Invalid Datastar config.');
        }
        [$template, $vars] = $config;

        return $this->stream(str_ends_with($template, '.twig') ? $template : $template . '.twig', $vars);
    }
}
