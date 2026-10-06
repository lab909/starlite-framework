<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Kernel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * The console behind bin/console: the framework's commands plus every command found in the
 * app's src/Command/ (namespace App\Command), so a clone adds commands without touching lib/.
 */
final class Console
{
    public static function run(string $root): int
    {
        return self::application($root)->run();
    }

    /**
     * The console application, with the app's commands from $commandDir (namespace $namespace).
     */
    public static function application(string $root, ?string $commandDir = null, string $namespace = 'App\\Command\\'): Application
    {
        Kernel::loadEnv($root);

        // One kernel per console run, booted on first use. `deploy` boots it first in production mode,
        // so app commands it runs as deploy steps see the same (production) kernel.
        $kernel = null;
        $boot = static function (?bool $debug = null) use (&$kernel, $root): Kernel {
            return $kernel ??= Kernel::boot($root, $debug);
        };

        $console = new Application('Starlite');
        $console->setAutoExit(false);
        $console->addCommand(new DeployCommand($root, $boot));
        $console->addCommand(new CacheClearCommand($root));
        $console->addCommand(new CdnPurgeCommand($boot));
        foreach (self::discover($commandDir ?? $root . '/src/Command', $namespace) as $class) {
            $command = new $class();
            if ($command instanceof AppCommand) {
                $command->setKernelFactory($boot);
            }
            $console->addCommand($command);
        }

        return $console;
    }

    /**
     * Concrete Command classes in a PSR-4 directory.
     *
     * @return list<class-string<Command>>
     */
    public static function discover(string $dir, string $namespace): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1, -4);
            $class = $namespace . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                throw new \RuntimeException("{$file->getPathname()} must declare class {$class}.");
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Command::class)) {
                continue;
            }
            if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("{$class}: commands in src/Command/ are created without arguments; extend AppCommand and use \$this->app() instead.");
            }
            /** @var class-string<Command> $class */
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }
}
