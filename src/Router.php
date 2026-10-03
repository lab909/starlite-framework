<?php

declare(strict_types=1);

namespace Starlite;

use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Thin wrapper around the Symfony Routing component.
 *
 * Routes are declared in config/routes.php with a closure, a [Controller::class, 'method'] pair
 * or an invokable controller class. Controllers are only instantiated when their route matches.
 * The matcher and URL generator are compiled to plain PHP arrays in var/cache (when not in debug),
 * so matching a request is a single Opcache-backed regex lookup.
 */
final class Router
{
    private readonly RouteCollection $routes;

    /** @var array<string, \Closure|array{class-string, string}|class-string> */
    private array $handlers = [];

    private ?CompiledUrlMatcher $matcher = null;
    private ?CompiledUrlGenerator $generator = null;

    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $debug,
        private readonly RequestContext $context = new RequestContext(),
    ) {
        $this->routes = new RouteCollection();
    }

    /**
     * @param list<string>                                         $methods
     * @param \Closure|array{class-string, string}|class-string    $handler
     * @param array<string, string>                                $requirements e.g. ['slug' => '[a-z0-9-]+']
     */
    public function add(
        array $methods,
        string $path,
        \Closure|array|string $handler,
        ?string $name = null,
        array $requirements = [],
        bool $csrf = true,
    ): void {
        $methods = array_map('strtoupper', $methods);
        $name ??= strtolower(implode('_', $methods)) . '_' . trim((string) preg_replace('/\W+/', '_', $path), '_');
        $this->routes->add($name, new Route($path, ['_csrf' => $csrf], $requirements, methods: $methods));
        $this->handlers[$name] = $handler;
    }

    /**
     * @return array{\Closure|array{class-string, string}|class-string, array<string, string>, bool} handler, route parameters, CSRF required
     *
     * @throws \Symfony\Component\Routing\Exception\ResourceNotFoundException
     * @throws \Symfony\Component\Routing\Exception\MethodNotAllowedException
     */
    public function match(string $method, string $path): array
    {
        $this->context->setMethod($method);
        $params = $this->matcher()->match($path);
        $handler = $this->handlers[$params['_route']]
            ?? throw new \LogicException("Route \"{$params['_route']}\" is cached but no longer defined. Run bin/console cache:clear.");
        $csrf = (bool) ($params['_csrf'] ?? true);

        $args = array_filter($params, static fn ($key) => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

        return [$handler, $args, $csrf];
    }

    /** @param array<string, mixed> $params */
    public function generate(string $name, array $params = []): string
    {
        return $this->generator()->generate($name, $params);
    }

    public function routes(): RouteCollection
    {
        return $this->routes;
    }

    /** Writes the compiled matcher and generator to the cache directory. */
    public function warmup(): void
    {
        Cache::writeCode($this->cacheDir . '/routes.matcher.php', (new CompiledUrlMatcherDumper($this->routes))->dump());
        Cache::writeCode($this->cacheDir . '/routes.generator.php', (new CompiledUrlGeneratorDumper($this->routes))->dump());
    }

    private function matcher(): CompiledUrlMatcher
    {
        return $this->matcher ??= new CompiledUrlMatcher(
            $this->debug
                ? (new CompiledUrlMatcherDumper($this->routes))->getCompiledRoutes()
                : $this->cached('routes.matcher.php'),
            $this->context,
        );
    }

    private function generator(): CompiledUrlGenerator
    {
        return $this->generator ??= new CompiledUrlGenerator(
            $this->debug
                ? (new CompiledUrlGeneratorDumper($this->routes))->getCompiledRoutes()
                : $this->cached('routes.generator.php'),
            $this->context,
        );
    }

    /** @return array<mixed> */
    private function cached(string $file): array
    {
        $path = $this->cacheDir . '/' . $file;
        if (!is_file($path)) {
            $this->warmup();
        }

        return require $path;
    }
}
