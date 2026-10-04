<?php

declare(strict_types=1);

namespace Starlite;

use Starlite\Blog\Blog;
use Starlite\Seo\Seo;
use starfederation\datastar\Consts;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\SameOriginCsrfTokenManager;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class Kernel
{
    public readonly Environment $twig;
    public readonly Datastar $datastar;
    public readonly Router $router;
    public readonly Blog $blog;
    public readonly Vite $vite;
    public readonly PublicConfig $publicConfig;
    public readonly Seo $seo;
    public readonly Site $site;
    public readonly Translations $translations;
    public readonly Container $container;
    public readonly string $cacheDir;

    private readonly RequestStack $requests;
    private readonly SameOriginCsrfTokenManager $csrf;

    /**
     * Builds the kernel from the app's config/app.php, config/bootstrap.php and config/routes.php.
     *
     * @param bool|null            $debug     overrides APP_DEBUG
     * @param array<string, mixed> $overrides merged over config/app.php, e.g. in tests:
     *                                        ['content_dir' => …, 'cache_dir' => …, 'blog' => ['per_page' => 1]]
     */
    public static function boot(string $root, ?bool $debug = null, array $overrides = []): self
    {
        self::loadEnv($root);
        $config = array_replace_recursive(require $root . '/config/app.php', $overrides);
        $debug ??= $config['debug'];

        // Behind a reverse proxy / load balancer, trust its X-Forwarded-* headers (needed for the CSRF origin check).
        if ($config['trusted_proxies'] !== []) {
            Request::setTrustedProxies(
                $config['trusted_proxies'],
                Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $site = new Site(
            $config['url'],
            $config['site']['name'],
            $config['site']['description'],
            $config['site']['image'],
            $config['site']['author'],
            $config['language'],
            $config['languages'],
        );
        $app = new self(
            $root,
            $config['secret'],
            $debug,
            $site,
            $config['blog']['per_page'],
            $config['content_dir'] ?? null,
            $config['cache_dir'] ?? null,
            $config['public'] ?? [],
        );

        // The app's extension point: services, Twig extensions and globals, deploy steps.
        // Runs before the routes, so route handlers can rely on everything registered here.
        if (is_file($root . '/config/bootstrap.php')) {
            (require $root . '/config/bootstrap.php')($app);
        }
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

    /** @param array<string, mixed> $public config values the browser may read (config/app.php `public`) */
    public function __construct(
        public readonly string $root,
        string $secret,
        public readonly bool $debug = false,
        ?Site $site = null,
        int $postsPerPage = 20,
        ?string $contentDir = null,
        ?string $cacheDir = null,
        array $public = [],
    ) {
        $this->cacheDir = $cacheDir ?? $root . '/var/cache';
        $contentDir ??= $root . '/content';
        $this->site = $site ?? new Site('http://localhost', 'Starlite');
        $this->container = new Container($this);
        $this->translations = new Translations($root . '/translations', $this->site, $debug ? null : $this->cacheDir . '/translations', $debug);
        $this->router = new Router($this->cacheDir, $debug);
        $this->blog = new Blog($contentDir . '/blog', $this->cacheDir . '/blog.php', $debug, $this->site, $postsPerPage);
        $this->vite = new Vite($root, $this->cacheDir, $debug);
        $this->publicConfig = new PublicConfig($public, $secret);
        $this->datastar = new Datastar($secret, $this->site);
        $this->seo = new Seo($this->site);
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
        $this->twig->addExtension($this->publicConfig);
        $this->twig->addExtension($this->seo);
        $this->twig->addExtension($this->site);
        $this->twig->addExtension($this->translations);
        $this->twig->addExtension(new IntlExtension()); // format_date / format_number, localized with site.locale
        $this->twig->addFunction(new TwigFunction('path', $this->path(...)));
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

    /**
     * @param \Closure|array{class-string, string}|class-string $handler
     * @param array<string, string> $requirements
     */
    public function get(string $path, \Closure|array|string $handler, ?string $name = null, array $requirements = []): void
    {
        $this->route('GET', $path, $handler, $name, $requirements);
    }

    /**
     * @param \Closure|array{class-string, string}|class-string $handler
     * @param array<string, string> $requirements
     */
    public function post(string $path, \Closure|array|string $handler, ?string $name = null, array $requirements = []): void
    {
        $this->route('POST', $path, $handler, $name, $requirements);
    }

    /** Handles the current HTTP request (public/index.php). */
    public function run(): void
    {
        // Debug: Symfony's exception page, and PHP warnings throw.
        // Production: uncaught exceptions are logged and rendered by handle(); PHP warnings are only logged.
        // Registered here rather than in boot(), so the console and tests keep their own error handling.
        $this->debug ? Debug::enable() : ErrorHandler::register()->throwAt(0, true);

        $request = Request::createFromGlobals();
        $this->handle($request)->prepare($request)->send();
    }

    public function handle(Request $request): Response
    {
        // /it/blog → Italian, routed as /blog. The default language never has a prefix: /en/blog → /blog.
        [$language, $path, $redirect] = $this->site->resolve($request->getPathInfo());
        if ($redirect) {
            $query = $request->getQueryString();

            return new RedirectResponse($path . ($query !== null ? '?' . $query : ''), Response::HTTP_MOVED_PERMANENTLY);
        }
        $this->site->enter($language, $path);

        $this->requests->push($request);
        $this->seo->reset($this->site->localize($path)); // canonical: /it and /it/ are the same page
        try {
            $response = $this->dispatch($request, $path);
        } catch (\Throwable $e) {
            if ($this->debug) {
                throw $e;
            }
            error_log((string) $e);
            $response = $this->error(500, $this->t('Something went wrong.'));
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
        // Streamed and file responses have no body in memory (getContent() is false): SSE is never
        // cached, and files bring their own ETag and Last-Modified.
        if ($request->isMethodCacheable() && $response->isOk()) {
            if ($response->getContent() !== false) {
                $response->setEtag(hash('xxh128', $response->getContent()));
                $response->setPublic();
                $response->headers->addCacheControlDirective('no-cache');
            }
            $response->isNotModified($request);
        }

        return $response;
    }

    private function dispatch(Request $request, string $path): Response
    {
        try {
            [$handler, $params, $csrf] = $this->router->match($request->getMethod(), $path);
        } catch (ResourceNotFoundException) {
            return $this->error(404, $this->t('Not found.'));
        } catch (MethodNotAllowedException $e) {
            return $this->error(405, $this->t('Method not allowed.'), ['Allow' => implode(', ', $e->getAllowedMethods())]);
        }

        // Stateless CSRF protection: the browser's Sec-Fetch-Site / Origin headers must show the
        // request comes from this site. No token, cookie or session needed.
        if ($csrf && !$request->isMethodSafe() && !$this->csrf->isTokenValid(new CsrfToken('starlite', 'csrf-token'))) {
            return $this->error(403, 'Cross-site request blocked.');
        }

        $result = $this->call($handler, $params);

        return $result instanceof Response ? $result : new Response((string) $result, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Calls a route handler; controller classes get the kernel as their only constructor argument.
     *
     * @param \Closure|array{class-string, string}|class-string $handler
     * @param array<string, string> $params
     */
    private function call(\Closure|array|string $handler, array $params): mixed
    {
        if ($handler instanceof \Closure) {
            return $handler(...$params);
        }
        [$class, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];

        return (new $class($this))->{$method}(...$params);
    }

    // --- Deploy steps -----------------------------------------------------------

    /** @var list<array{name: string, step: \Closure|string, description: string, before: ?string, after: ?string}> */
    private array $deploySteps = [];

    /**
     * Adds a step to `bin/console deploy` (call it from config/bootstrap.php).
     *
     * The step is a closure `fn (Kernel $app, SymfonyStyle $io): ?bool` (return false to stop the
     * deploy) or the name of a console command to run (e.g. 'app:build-audio'). It goes before or after
     * a built-in step (`bin/console deploy --list-steps`); by default just before Opcache is refreshed,
     * so its output is included in the warm-up.
     */
    public function addDeployStep(
        string $name,
        \Closure|string $step,
        string $description = '',
        ?string $before = null,
        ?string $after = null,
    ): void {
        if ($before !== null && $after !== null) {
            throw new \InvalidArgumentException("Deploy step \"{$name}\": give either before or after, not both.");
        }
        $this->deploySteps[] = ['name' => $name, 'step' => $step, 'description' => $description, 'before' => $before, 'after' => $after];
    }

    /** @return list<array{name: string, step: \Closure|string, description: string, before: ?string, after: ?string}> */
    public function deploySteps(): array
    {
        return $this->deploySteps;
    }

    // --- Languages ------------------------------------------------------------

    /**
     * URL path of a named route in the current language (or the given one): path('blog') → "/it/blog".
     *
     * @param array<string, mixed> $params
     */
    public function path(string $name, array $params = [], ?string $language = null): string
    {
        return $this->site->localize($this->router->generate($name, $params), $language);
    }

    /**
     * Translates a UI text into the current language (see Translations).
     *
     * @param array<string, mixed> $params
     */
    public function t(string $message, array $params = [], ?string $language = null): string
    {
        return $this->translations->t($message, $params, $language);
    }

    // --- Responses ------------------------------------------------------------

    public function request(): Request
    {
        return $this->requests->getCurrentRequest() ?? throw new \LogicException('No request is being handled.');
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        return $this->twig->render($template, $vars);
    }

    /**
     * Renders a template (with the request's Datastar signals) and returns it as an SSE response.
     *
     * @param array<string, mixed> $vars
     */
    public function stream(string $template, array $vars = []): StreamedResponse
    {
        $vars['signals'] = $this->signals();

        return $this->datastar->response($this->twig->render($template, $vars));
    }

    /**
     * An error page, or plain text for Datastar requests, which expect SSE rather than HTML.
     *
     * @param array<string, string> $headers
     */
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

    /**
     * Signals sent by Datastar: in the `datastar` query parameter for GET/DELETE, else in the JSON body.
     *
     * @return array<string, mixed>
     */
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
