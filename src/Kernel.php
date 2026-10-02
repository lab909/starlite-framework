<?php

declare(strict_types=1);

namespace Starlite;

use Starlite\Blog\Blog;
use starfederation\datastar\ServerSentEventGenerator;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
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
    public readonly string $cacheDir;

    /** Builds the kernel from the app's config/app.php and config/routes.php. `$debug` overrides APP_DEBUG. */
    public static function boot(string $root, ?bool $debug = null): self
    {
        self::loadEnv($root);
        $config = require $root . '/config/app.php';
        $app = new self($root, $config['secret'], $debug ?? $config['debug']);
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
        private readonly string $secret,
        public readonly bool $debug = false,
    ) {
        $this->cacheDir = $root . '/var/cache';
        $this->router = new Router($this->cacheDir, $debug);
        $this->blog = new Blog($root . '/content/blog', $this->cacheDir . '/blog.php', $debug);
        $this->vite = new Vite($root, $this->cacheDir, $debug);
        $this->datastar = new Datastar($secret);

        $this->twig = new Environment(new FilesystemLoader($root . '/templates'), [
            'cache' => $debug ? false : $this->cacheDir . '/twig',
            'auto_reload' => $debug,
            'debug' => $debug,
            'strict_variables' => $debug,
        ]);
        $this->twig->addExtension($this->datastar);
        $this->twig->addExtension($this->vite);
        $this->twig->addFunction(new TwigFunction('path', $this->router->generate(...)));
        $this->twig->addGlobal('blog', $this->blog);

        // Endpoint used by datastar.get() / post() / put() / patch() / delete().
        $this->route(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/datastar', $this->renderDatastarTemplate(...), 'datastar');

        // Signed by `bin/console deploy`; not session based, so no CSRF token.
        $this->route(['POST'], Opcache::PATH, $this->warmOpcache(...), 'opcache_warm', csrf: false);
    }

    // --- Routing --------------------------------------------------------------

    /**
     * The handler is a closure, a [Controller::class, 'method'] pair or an invokable controller class.
     * Route placeholders are passed to it as named arguments.
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
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');

        try {
            [$handler, $params, $csrf] = $this->router->match($method, $path);
        } catch (ResourceNotFoundException) {
            $this->abort(404, 'Not found.');

            return;
        } catch (MethodNotAllowedException $e) {
            header('Allow: ' . implode(', ', $e->getAllowedMethods()));
            $this->abort(405, 'Method not allowed.');

            return;
        }

        if ($csrf && !in_array($method, ['GET', 'HEAD'], true) && !Csrf::isValid()) {
            $this->abort(403, 'Invalid CSRF token.');

            return;
        }

        $result = $this->dispatch($handler, $params);
        if (is_string($result)) {
            $this->send($result, $method);
        }
    }

    /** Calls a route handler; controller classes get the kernel as their only constructor argument. */
    private function dispatch(\Closure|array|string $handler, array $params): mixed
    {
        if ($handler instanceof \Closure) {
            return $handler(...$params);
        }
        [$class, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];

        return (new $class($this))->{$method}(...$params);
    }

    // --- Responses ------------------------------------------------------------

    public function render(string $template, array $vars = []): string
    {
        return $this->twig->render($template, $vars);
    }

    /** Renders a template and streams it to the browser as Datastar events. */
    public function stream(string $template, array $vars = []): void
    {
        $vars['signals'] = ServerSentEventGenerator::readSignals();
        $this->datastar->send($this->twig->render($template, $vars));
    }

    /** Sends an error page (or plain text to Datastar requests, which expect SSE, not HTML). */
    public function abort(int $status, string $message): void
    {
        http_response_code($status);
        if (!isset($_SERVER['HTTP_DATASTAR_REQUEST']) && $this->twig->getLoader()->exists('_error.twig')) {
            header('Content-Type: text/html; charset=utf-8');
            echo $this->render('_error.twig', ['status' => $status, 'message' => $message]);

            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }

    /** Pages are static-like, so a strong ETag lets browsers revalidate with a cheap 304. */
    private function send(string $body, string $method): void
    {
        if ($method === 'GET' || $method === 'HEAD') {
            $etag = '"' . hash('xxh128', $body) . '"';
            header('ETag: ' . $etag);
            header('Cache-Control: no-cache');
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
                http_response_code(304);

                return;
            }
        }
        echo $body;
    }

    private function renderDatastarTemplate(): void
    {
        $config = $this->datastar->decode((string) ($_GET['config'] ?? ''));
        if ($config === null) {
            $this->abort(400, 'Invalid Datastar config.');

            return;
        }
        [$template, $vars] = $config;
        $this->stream(str_ends_with($template, '.twig') ? $template : $template . '.twig', $vars);
    }

    private function warmOpcache(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if (!Opcache::verify(
            $this->secret,
            (string) ($_SERVER['HTTP_X_OPCACHE_TIMESTAMP'] ?? ''),
            (string) ($_SERVER['HTTP_X_OPCACHE_SIGNATURE'] ?? ''),
        )) {
            http_response_code(403);
            echo '{"error":"Invalid signature."}';

            return;
        }
        if (!Opcache::isEnabled()) {
            http_response_code(503);
            echo '{"error":"Opcache is not enabled in PHP-FPM."}';

            return;
        }
        $listFile = Opcache::listFile($this->cacheDir);
        if (!is_file($listFile)) {
            http_response_code(409);
            echo '{"error":"No file list. Run bin/console deploy."}';

            return;
        }
        opcache_invalidate($listFile, true);
        $result = Opcache::compile(require $listFile);
        echo json_encode([
            'compiled' => $result['compiled'],
            'failed' => count($result['failed']),
            'memory_used' => opcache_get_status(false)['memory_usage']['used_memory'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }
}
