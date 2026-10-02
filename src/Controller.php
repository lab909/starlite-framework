<?php

declare(strict_types=1);

namespace Starlite;

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

    protected function render(string $template, array $vars = []): string
    {
        return $this->app->render($template, $vars);
    }

    /** Renders a template and returns it to the browser as Datastar events. */
    protected function stream(string $template, array $vars = []): StreamedResponse
    {
        return $this->app->stream($template, $vars);
    }

    protected function notFound(string $message = 'Not found.'): Response
    {
        return $this->app->error(404, $message);
    }
}
