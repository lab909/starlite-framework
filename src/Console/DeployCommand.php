<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Blog\Blog;
use Starlite\Cache;
use Starlite\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Production build: a list of named steps, run in order. Run it after every code, template or content
 * change. `--list-steps` shows them; `--skip=name,name` leaves some out; the app adds its own with
 * $app->addDeployStep() in config/bootstrap.php.
 *
 *   assets        (only with --assets) npm run build
 *   composer      composer dump-autoload --optimize --classmap-authoritative
 *   cache         empty var/cache
 *   routes        compile the router
 *   blog          compile the posts and copy their files to public/media/blog/
 *   collections   compile the data collections
 *   translations  compile the translation catalogues
 *   templates     compile every Twig template
 *   vite          cache the Vite manifest
 *   opcache       refresh the web server's Opcache (the CLI has its own, so it cannot do this by
 *                 itself), depending on --opcache / APP_OPCACHE:
 *        cachetool  invalidate this project's scripts in PHP-FPM, then precompile them over its
 *                   FastCGI socket (https://github.com/gordalina/cachetool)
 *        reload     run a command that clears Opcache, e.g. `sudo systemctl reload php8.4-fpm`
 *                   or `sudo apachectl graceful` (mod_php); files are recompiled on first use
 *        none       do nothing (shared hosting, or opcache.validate_timestamps=1)
 */
#[AsCommand('deploy', 'Optimizes the autoloader, rebuilds all caches and refreshes the web server\'s Opcache.')]
final class DeployCommand extends Command
{
    private const OPCACHE_MODES = ['cachetool', 'reload', 'none'];

    /** @param (\Closure(?bool): Kernel)|null $boot shared kernel factory from Starlite\Console\Console */
    public function __construct(private readonly string $root, private readonly ?\Closure $boot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('opcache', null, InputOption::VALUE_REQUIRED, 'How to refresh Opcache: cachetool, reload or none', getenv('APP_OPCACHE') ?: 'cachetool')
            ->addOption('fcgi', null, InputOption::VALUE_REQUIRED, '[cachetool] PHP-FPM socket or host:port', getenv('APP_FPM_SOCKET') ?: '/run/php-fpm.sock')
            ->addOption('cachetool', null, InputOption::VALUE_REQUIRED, '[cachetool] cachetool binary', getenv('APP_CACHETOOL') ?: 'cachetool')
            ->addOption('reload-cmd', null, InputOption::VALUE_REQUIRED, '[reload] Shell command that clears Opcache', getenv('APP_OPCACHE_RELOAD_CMD') ?: null)
            ->addOption('assets', null, InputOption::VALUE_NONE, 'Run `npm run build` first')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Composer binary', 'composer')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Exclude require-dev packages from the autoloader')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'Comma-separated steps to leave out (see --list-steps)', '')
            ->addOption('list-steps', null, InputOption::VALUE_NONE, 'Show the steps in order, including the app\'s, and exit')
            ->addOption('skip-composer', null, InputOption::VALUE_NONE, 'Same as --skip=composer')
            ->addOption('skip-opcache', null, InputOption::VALUE_NONE, 'Same as --opcache=none');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validate the Opcache settings before changing anything.
        $opcache = $input->getOption('skip-opcache') ? 'none' : (string) $input->getOption('opcache');
        if (!in_array($opcache, self::OPCACHE_MODES, true)) {
            $io->error(sprintf('Unknown --opcache mode "%s". Use one of: %s.', $opcache, implode(', ', self::OPCACHE_MODES)));

            return Command::INVALID;
        }
        $reloadCommand = trim((string) $input->getOption('reload-cmd'));
        if ($opcache === 'reload' && $reloadCommand === '') {
            $io->error('--opcache=reload needs --reload-cmd (or APP_OPCACHE_RELOAD_CMD), e.g. "sudo systemctl reload php8.4-fpm".');

            return Command::INVALID;
        }

        // Booted first (in production mode) so config/bootstrap.php can add its steps.
        $app = $this->boot !== null ? ($this->boot)(false) : Kernel::boot($this->root, debug: false);
        if ($app->debug) {
            $io->error('The kernel was already booted in debug mode; run deploy on its own.');

            return Command::FAILURE;
        }
        try {
            $steps = $this->steps($app, $input, $output, $opcache, $reloadCommand);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $skip = array_filter(array_map('trim', explode(',', (string) $input->getOption('skip'))));
        if ($input->getOption('skip-composer')) {
            $skip[] = 'composer';
        }
        if ($unknown = array_diff($skip, array_keys($steps))) {
            $io->error(sprintf('Unknown step(s) in --skip: %s. Steps: %s.', implode(', ', $unknown), implode(', ', array_keys($steps))));

            return Command::INVALID;
        }

        if ($input->getOption('list-steps')) {
            $io->table(['Step', 'What it does'], array_map(
                static fn (string $name, array $step) => [$name . (in_array($name, $skip, true) ? ' (skipped)' : ''), $step['description']],
                array_keys($steps),
                $steps,
            ));

            return Command::SUCCESS;
        }

        $io->title('Starlite deploy');
        foreach ($steps as $name => $step) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            if ($step['run']() === false) {
                $io->error("Deploy stopped at step \"{$name}\".");

                return Command::FAILURE;
            }
        }
        if ($opcache === 'none' && !in_array('opcache', $skip, true)) {
            $io->note('Opcache was not refreshed. With opcache.validate_timestamps=0, reload PHP yourself.');
        }

        $io->success('Deployed.');

        return Command::SUCCESS;
    }

    /**
     * The built-in steps, with the app's steps (Kernel::addDeployStep) inserted where they asked.
     *
     * @return array<string, array{description: string, run: \Closure(): mixed}> run() returning false stops the deploy
     */
    private function steps(Kernel $app, InputInterface $input, OutputInterface $output, string $opcache, string $reloadCommand): array
    {
        $io = new SymfonyStyle($input, $output);
        $steps = [];
        if ($input->getOption('assets')) {
            $steps['assets'] = ['description' => 'npm run build', 'run' => function () use ($io) {
                $io->section('Building assets');

                return $this->exec('npm run build', $io);
            }];
        }
        $steps['composer'] = ['description' => 'Optimize the Composer autoloader (authoritative class map)', 'run' => function () use ($io, $input) {
            $io->section('Optimizing Composer autoloader');

            return $this->exec(
                escapeshellarg((string) $input->getOption('composer'))
                . ' dump-autoload --optimize --classmap-authoritative --no-interaction'
                . ($input->getOption('no-dev') ? ' --no-dev' : ''),
                $io,
            );
        }];
        $steps['cache'] = ['description' => 'Empty var/cache', 'run' => static function () use ($io, $app) {
            $io->section('Rebuilding var/cache');
            Cache::clear($app->cacheDir);
            // Later steps (including the app's) may write straight into it.
            if (!is_dir($app->cacheDir) && !mkdir($app->cacheDir, 0775, true) && !is_dir($app->cacheDir)) {
                throw new \RuntimeException("Cannot create {$app->cacheDir}.");
            }
        }];
        $steps['routes'] = ['description' => 'Compile the routes', 'run' => static function () use ($io, $app) {
            $app->router->warmup();
            $io->writeln(sprintf(' ✔ %d routes compiled', count($app->router->routes())));
        }];
        $steps['blog'] = ['description' => 'Compile the blog posts and publish their files to public' . Blog::ASSET_URL . '/', 'run' => function () use ($io, $app) {
            [$posts, $versions] = $app->blog->warmup();
            $io->writeln(sprintf(' ✔ %d blog posts compiled (%d language versions)', $posts, $versions));
            $io->writeln(sprintf(' ✔ %d post files published to public%s/', $app->blog->publishAssets($this->root . '/public'), Blog::ASSET_URL));
        }];
        $steps['collections'] = ['description' => 'Compile the data collections', 'run' => static function () use ($io, $app) {
            $counts = $app->collections->warmup();
            $io->writeln(sprintf(' ✔ %d data collection%s compiled (%d items)', count($counts), count($counts) === 1 ? '' : 's', array_sum($counts)));
        }];
        $steps['translations'] = ['description' => 'Compile the translation catalogues', 'run' => static function () use ($io, $app) {
            $io->writeln(sprintf(' ✔ translations compiled for %d languages', $app->translations->warmup()));
        }];
        $steps['templates'] = ['description' => 'Compile every Twig template', 'run' => function () use ($io, $app) {
            $io->writeln(sprintf(' ✔ %d Twig templates compiled', $this->compileTemplates($app)));
        }];
        $steps['vite'] = ['description' => 'Cache the Vite manifest', 'run' => static function () use ($io, $app) {
            if ($app->vite->warmup()) {
                $io->writeln(' ✔ Vite manifest cached');
            } else {
                $io->warning('public/build/.vite/manifest.json is missing: run `npm run build` (or pass --assets).');
            }
        }];
        $steps['opcache'] = ['description' => "Refresh the web server's Opcache ({$opcache})", 'run' => fn () => match ($opcache) {
            'cachetool' => $this->refreshWithCachetool($input, $app->cacheDir, $io),
            'reload' => $this->refreshWithReload($reloadCommand, $io),
            default => true, // 'none' (the mode was validated before any step ran)
        }];

        foreach ($app->deploySteps() as $custom) {
            $name = $custom['name'];
            if (isset($steps[$name])) {
                throw new \InvalidArgumentException("Deploy step \"{$name}\" already exists.");
            }
            $anchor = $custom['before'] ?? $custom['after'] ?? 'opcache';
            if (!isset($steps[$anchor])) {
                throw new \InvalidArgumentException("Deploy step \"{$name}\" refers to unknown step \"{$anchor}\". Steps: " . implode(', ', array_keys($steps)) . '.');
            }
            $step = $custom['step'];
            $entry = [
                'description' => $custom['description'] !== '' ? $custom['description'] : (is_string($step) ? "bin/console {$step}" : 'App step'),
                'run' => function () use ($step, $name, $app, $io, $output) {
                    $io->section("App step: {$name}");
                    if (is_string($step)) {
                        $console = $this->getApplication() ?? throw new \LogicException('deploy must run inside the console application.');

                        return $console->find($step)->run(new ArrayInput([]), $output) === Command::SUCCESS;
                    }

                    return $step($app, $io);
                },
            ];
            $position = array_search($anchor, array_keys($steps), true) + ($custom['after'] !== null ? 1 : 0);
            $steps = array_slice($steps, 0, $position, true) + [$name => $entry] + array_slice($steps, $position, null, true);
        }

        return $steps;
    }

    private function compileTemplates(Kernel $app): int
    {
        $dir = $this->root . '/templates';
        $count = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $app->twig->load(substr($file->getPathname(), strlen($dir) + 1));
            ++$count;
        }

        return $count;
    }

    /** Invalidates this project's scripts in PHP-FPM's Opcache, then precompiles them all. */
    private function refreshWithCachetool(InputInterface $input, string $cacheDir, SymfonyStyle $io): bool
    {
        $io->section('Refreshing PHP-FPM Opcache (cachetool)');
        $cachetool = escapeshellarg((string) $input->getOption('cachetool'));
        $fcgi = ' --quiet --fcgi=' . escapeshellarg((string) $input->getOption('fcgi'));

        $commands = [
            // Connection check: invalidate/compile exit with 0 even when they cannot reach PHP-FPM.
            "{$cachetool} opcache:status{$fcgi}",
            // Drop stale entries first: with opcache.validate_timestamps=0, FPM never notices changed files.
            "{$cachetool} opcache:invalidate:scripts --force{$fcgi} " . escapeshellarg((string) realpath($this->root)),
        ];
        foreach ($this->opcacheDirs($cacheDir) as $dir) {
            $commands[] = "{$cachetool} opcache:compile:scripts --batch{$fcgi} " . escapeshellarg($dir);
        }
        $commands[] = "{$cachetool} opcache:compile:script{$fcgi} " . escapeshellarg($this->root . '/public/index.php');

        foreach ($commands as $command) {
            if (!$this->exec($command, $io)) {
                $io->note([
                    'Is cachetool installed (see README) and does --fcgi point at the PHP-FPM socket?',
                    'The deploy user also needs access to the socket (usually group www-data).',
                    'Without PHP-FPM, use --opcache=reload or --opcache=none.',
                ]);

                return false;
            }
        }

        return true;
    }

    /** Runs the configured command (a graceful PHP-FPM or Apache reload), which empties Opcache. */
    private function refreshWithReload(string $command, SymfonyStyle $io): bool
    {
        $io->section('Refreshing Opcache (reload)');
        // The command comes from the deploy configuration (option or APP_OPCACHE_RELOAD_CMD), never from a request.
        if (!$this->exec($command, $io)) {
            $io->note('The deploy user may need sudo rights for this command only (see README).');

            return false;
        }

        return true;
    }

    /** @return list<string> directories holding every PHP file a request can load */
    private function opcacheDirs(string $cacheDir): array
    {
        // dirname(__DIR__) is the framework itself (lib/src), symlinked into vendor/ by Composer.
        $dirs = [dirname(__DIR__), $this->root . '/src', $this->root . '/config', $this->root . '/vendor', $cacheDir];

        return array_values(array_unique(array_filter(array_map('realpath', $dirs))));
    }

    private function exec(string $command, SymfonyStyle $io): bool
    {
        $io->writeln(" <comment>$ {$command}</comment>");
        passthru('cd ' . escapeshellarg($this->root) . ' && ' . $command, $code);
        if ($code !== 0) {
            $io->error("Command failed with exit code {$code}.");

            return false;
        }

        return true;
    }
}
