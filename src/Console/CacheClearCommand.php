<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Blog\Blog;
use Starlite\Cache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('cache:clear', 'Deletes var/cache and the blog files published to public/media/blog (run it when going back to development).')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Cache::clear($this->root . '/var/cache');
        // Published copies would shadow the post folders: nginx serves public/ before PHP is reached.
        Cache::clear($this->root . '/public' . Blog::ASSET_URL);
        $output->writeln('<info>var/cache and public' . Blog::ASSET_URL . ' cleared.</info>');

        return Command::SUCCESS;
    }
}
