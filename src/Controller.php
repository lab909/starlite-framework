<?php

declare(strict_types=1);

namespace Starlite;

/**
 * Optional base class for app controllers. Routes can point at a method
 * ([BlogController::class, 'show']) or at an invokable class (ClockController::class).
 */
abstract class Controller
{
    public function __construct(protected readonly Kernel $app)
    {
    }

    protected function render(string $template, array $vars = []): string
    {
        return $this->app->render($template, $vars);
    }

    /** Renders a template and streams it to the browser as Datastar events. */
    protected function stream(string $template, array $vars = []): void
    {
        $this->app->stream($template, $vars);
    }

    protected function notFound(string $message = 'Not found.'): null
    {
        $this->app->abort(404, $message);

        return null;
    }
}
