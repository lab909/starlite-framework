<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Blog\Blog;
use Starlite\Cache;
use Starlite\Pages\Pages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('cache:clear', 'Deletes var/cache and the post and page files published to public/media/ (run it when going back to development).')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Composer binary', 'composer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Cache::clear($this->root . '/var/cache');
        // Published copies would shadow the post folders: nginx serves public/ before PHP is reached.
        Cache::clear($this->root . '/public' . Blog::ASSET_URL);
        Cache::clear($this->root . '/public' . Pages::ASSET_URL);
        $output->writeln('<info>var/cache, public' . Blog::ASSET_URL . ' and public' . Pages::ASSET_URL . ' cleared.</info>');

        // deploy's authoritative class map only knows the classes that existed then: in development a
        // new controller or command would be "not found". Back to the normal autoloader.
        if (self::hasAuthoritativeClassMap($this->root)) {
            $command = 'cd ' . escapeshellarg($this->root) . ' && ' . escapeshellarg((string) $input->getOption('composer')) . ' dump-autoload --no-interaction --quiet';
            passthru($command, $code);
            if ($code !== 0) {
                $output->writeln("<error>composer dump-autoload failed (exit code {$code}): run it yourself.</error>");

                return Command::FAILURE;
            }
            $output->writeln('<info>Composer autoloader rebuilt for development.</info>');
        }

        return Command::SUCCESS;
    }

    public static function hasAuthoritativeClassMap(string $root): bool
    {
        $file = $root . '/vendor/composer/autoload_real.php';

        return is_file($file) && str_contains((string) file_get_contents($file), 'setClassMapAuthoritative(true)');
    }
}
