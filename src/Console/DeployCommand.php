<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Cache;
use Starlite\Kernel;
use Starlite\Opcache;
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
 *   4. Write the list of PHP files to precompile
 *   5. Ask PHP-FPM (signed request) to recompile those files into its Opcache
 */
#[AsCommand('deploy', 'Optimizes the autoloader, rebuilds all caches and warms the PHP-FPM Opcache.')]
final class DeployCommand extends Command
{
    /** Vendor folders that never need to be in Opcache (tests, docs, binaries). */
    private const SKIP_DIRS = '#/(?:tests?|Tests?|docs?|bin|\.github)/#';

    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Base URL that reaches PHP-FPM on this server', getenv('APP_WARM_URL') ?: 'http://127.0.0.1')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host header for the warm-up request, if the vhost needs one')
            ->addOption('assets', null, InputOption::VALUE_NONE, 'Run `npm run build` first')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Composer binary', 'composer')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Exclude require-dev packages from the autoloader')
            ->addOption('skip-composer', null, InputOption::VALUE_NONE, 'Do not optimize the Composer autoloader')
            ->addOption('skip-opcache', null, InputOption::VALUE_NONE, 'Do not send the Opcache warm-up request');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Starlite deploy');

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

        $files = $this->opcacheFiles($app->cacheDir);
        Cache::writeData(Opcache::listFile($app->cacheDir), $files);
        $io->writeln(sprintf(' ✔ %d PHP files listed for Opcache', count($files)));

        if (!$input->getOption('skip-opcache')) {
            $io->section('Warming PHP-FPM Opcache');
            if (!$this->warmOpcache((string) $input->getOption('url'), $input->getOption('host'), $io)) {
                return Command::FAILURE;
            }
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

    /** @return list<string> absolute paths of every PHP file a request can load */
    private function opcacheFiles(string $cacheDir): array
    {
        // The framework itself (lib/src, symlinked into vendor/ by Composer, which the iterator does not follow).
        $dirs = [dirname(__DIR__), $this->root . '/src', $this->root . '/config', $this->root . '/vendor', $cacheDir];
        $files = [$this->root . '/public/index.php'];
        foreach ($dirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if ($file->getExtension() === 'php' && !preg_match(self::SKIP_DIRS, substr($path, strlen($dir)))) {
                    $files[] = $path;
                }
            }
        }
        // Opcache keys files by real path, so symlinked duplicates collapse into one entry.
        $files = array_unique(array_map(static fn ($path) => realpath($path) ?: $path, $files));
        sort($files);

        return $files;
    }

    private function warmOpcache(string $url, ?string $host, SymfonyStyle $io): bool
    {
        $secret = (require $this->root . '/config/app.php')['secret'];
        $timestamp = time();
        $headers = [
            'Content-Type: application/json',
            Opcache::TIMESTAMP_HEADER . ': ' . $timestamp,
            Opcache::SIGNATURE_HEADER . ': ' . Opcache::sign($secret, $timestamp),
        ];
        if ($host !== null) {
            $headers[] = 'Host: ' . $host;
        }

        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => $headers, 'content' => '{}', 'timeout' => 120, 'ignore_errors' => true],
        ]);
        $body = @file_get_contents(rtrim($url, '/') . Opcache::PATH, false, $context);
        $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
        $result = json_decode((string) $body, true);

        if ($status !== 200 || !is_array($result)) {
            $io->error(sprintf(
                'Opcache warm-up failed (%s): %s. Check --url/--host, that APP_SECRET matches PHP-FPM, and that Opcache is enabled.',
                $status ?: 'no response',
                is_array($result) ? ($result['error'] ?? 'unknown error') : (trim(strip_tags((string) $body)) ?: 'connection failed'),
            ));

            return false;
        }
        $io->writeln(sprintf(' ✔ %d files compiled in PHP-FPM, %d skipped', $result['compiled'], $result['failed']));
        if ($result['memory_used'] !== null) {
            $io->writeln(sprintf(' ✔ Opcache memory used: %.1f MB', $result['memory_used'] / 1048576));
        }

        return true;
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
