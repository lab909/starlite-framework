<?php

declare(strict_types=1);

namespace Starlite;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * A minimal service container (PSR-11) for the app's shared objects, registered in config/bootstrap.php:
 *
 *   $app->container->set(Mailer::class, fn (Kernel $app) => new Mailer(getenv('MAILER_DSN')));
 *   $app->container->set('clock', new SystemClock());            // a ready-made object
 *
 *   $this->get(Mailer::class)                                    // in a controller
 *   $app->container->get('clock')                                // anywhere with the kernel
 *
 * A closure is a factory: it runs once, on first use, and receives the kernel; its result is shared.
 * No autowiring and no configuration language: just names and factories.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, \Closure(Kernel): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $services = [];

    public function __construct(private readonly Kernel $app)
    {
    }

    /** Registers a service: a factory closure (lazy, shared) or a ready-made value. Replaces any earlier one. */
    public function set(string $id, mixed $service): void
    {
        unset($this->services[$id], $this->factories[$id]);
        if ($service instanceof \Closure) {
            $this->factories[$id] = $service;
        } else {
            $this->services[$id] = $service;
        }
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new class("Service \"{$id}\" is not registered. Add it in config/bootstrap.php.") extends \RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->services[$id] = ($this->factories[$id])($this->app);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services) || isset($this->factories[$id]);
    }
}
