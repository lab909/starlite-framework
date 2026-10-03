<?php

declare(strict_types=1);

namespace Starlite;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Optional base class for app controllers. Routes can point at a method
 * ([BlogController::class, 'show']) or at an invokable class (ClockController::class).
 * Actions return an HTML string or any Symfony Response.
 */
abstract class Controller
{
    public function __construct(protected readonly Kernel $app)
    {
    }

    protected function request(): Request
    {
        return $this->app->request();
    }

    /** @param array<string, mixed> $vars */
    protected function render(string $template, array $vars = []): string
    {
        return $this->app->render($template, $vars);
    }

    /**
     * Renders a template and returns it to the browser as Datastar events.
     *
     * @param array<string, mixed> $vars
     */
    protected function stream(string $template, array $vars = []): StreamedResponse
    {
        return $this->app->stream($template, $vars);
    }

    /** A service registered in config/bootstrap.php ($app->container->set(...)). */
    protected function get(string $id): mixed
    {
        return $this->app->container->get($id);
    }

    /** JSON response, e.g. for data a page's JS module loads. */
    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    /**
     * URL path of a named route in the current language.
     *
     * @param array<string, mixed> $params
     */
    protected function path(string $name, array $params = [], ?string $language = null): string
    {
        return $this->app->path($name, $params, $language);
    }

    /**
     * Translates a UI text into the current language.
     *
     * @param array<string, mixed> $params
     */
    protected function t(string $message, array $params = []): string
    {
        return $this->app->t($message, $params);
    }

        protected function notFound(string $message = 'Not found.'): Response
    {
        return $this->app->error(404, $message);
    }
}
