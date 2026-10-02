<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Cache;
use Starlite\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Production build. Run it after every code, template or content change:
 *
 *   1. (optional) npm run build
 *   2. composer dump-autoload --optimize --classmap-authoritative
 *   3. Rebuild var/cache: compiled routes, Twig templates, blog posts, Vite manifest
 *   4. Refresh the web server's Opcache (the CLI has its own, so it cannot do this by itself),
 *      depending on --opcache / APP_OPCACHE:
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

    public function __construct(private readonly string $root)
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
            ->addOption('skip-composer', null, InputOption::VALUE_NONE, 'Do not optimize the Composer autoloader')
            ->addOption('skip-opcache', null, InputOption::VALUE_NONE, 'Same as --opcache=none');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Starlite deploy');

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

        if ($input->getOption('assets')) {
            $io->section('Building assets');
            if (!$this->exec('npm run build', $io)) {
                return Command::FAILURE;
            }
        }

        if (!$input->getOption('skip-composer')) {
            $io->section('Optimizing Composer autoloader');
            $command = escapeshellarg((string) $input->getOption('composer'))
                . ' dump-autoload --optimize --classmap-authoritative --no-interaction'
                . ($input->getOption('no-dev') ? ' --no-dev' : '');
            if (!$this->exec($command, $io)) {
                return Command::FAILURE;
            }
        }

        $io->section('Rebuilding var/cache');
        $app = Kernel::boot($this->root, debug: false);
        Cache::clear($app->cacheDir);

        $app->router->warmup();
        $io->writeln(sprintf(' ✔ %d routes compiled', count($app->router->routes())));

        $io->writeln(sprintf(' ✔ %d blog posts compiled', $app->blog->warmup()));

        $templates = $this->compileTemplates($app);
        $io->writeln(sprintf(' ✔ %d Twig templates compiled', $templates));

        if ($app->vite->warmup()) {
            $io->writeln(' ✔ Vite manifest cached');
        } else {
            $io->warning('public/build/.vite/manifest.json is missing: run `npm run build` (or pass --assets).');
        }

        $ok = match ($opcache) {
            'cachetool' => $this->refreshWithCachetool($input, $app->cacheDir, $io),
            'reload' => $this->refreshWithReload($reloadCommand, $io),
            'none' => true,
        };
        if (!$ok) {
            return Command::FAILURE;
        }
        if ($opcache === 'none') {
            $io->note('Opcache was not refreshed. With opcache.validate_timestamps=0, reload PHP yourself.');
        }

        $io->success('Deployed.');

        return Command::SUCCESS;
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
