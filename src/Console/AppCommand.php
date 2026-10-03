<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Kernel;
use Symfony\Component\Console\Command\Command;

/**
 * Base class for app commands that need the app (routes, Twig, blog, services, config).
 * Put the command in src/Command/ and it is registered automatically:
 *
 *   #[AsCommand('app:hello', 'Says hello.')]
 *   final class HelloCommand extends AppCommand
 *   {
 *       protected function execute(InputInterface $input, OutputInterface $output): int
 *       {
 *           $output->writeln($this->app()->t('Hello'));
 *           return Command::SUCCESS;
 *       }
 *   }
 *
 * The kernel is booted only when app() is first called, so `bin/console list` works even before
 * the app is configured. Plain Symfony commands (no constructor arguments) work in src/Command/ too.
 */
abstract class AppCommand extends Command
{
    /** @var (\Closure(): Kernel)|null */
    private ?\Closure $kernel = null;

    /** @internal called by Starlite\Console\Console when the command is registered */
    final public function setKernelFactory(\Closure $kernel): void
    {
        $this->kernel = $kernel;
    }

    protected function app(): Kernel
    {
        return ($this->kernel ?? throw new \LogicException(static::class . ' was not registered through bin/console.'))();
    }

    /** The project root (where composer.json, config/ and src/ live). */
    protected function root(): string
    {
        return $this->app()->root;
    }
}
