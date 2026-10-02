<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Cache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('cache:clear', 'Deletes compiled routes, templates, blog posts and the Vite manifest from var/cache.')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Cache::clear($this->root . '/var/cache');
        $output->writeln('<info>var/cache cleared.</info>');

        return Command::SUCCESS;
    }
}
