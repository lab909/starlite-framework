<?php

declare(strict_types=1);

namespace Starlite\Console;

use Starlite\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops pages from the CDN's cache, so it fetches them again (config/app.php `cdn.purge`):
 *
 *   bin/console cdn:purge blog/my-post /it/chi-siamo        paths on this site (APP_URL)
 *   bin/console cdn:purge https://example.com/blog/my-post  full URLs, e.g. from a development machine
 *   bin/console cdn:purge --all                             everything (deploy does this by itself)
 */
#[AsCommand('cdn:purge', 'Drops pages from the CDN\'s cache: paths or URLs, or --all.')]
final class CdnPurgeCommand extends Command
{
    /** @param \Closure(?bool): Kernel $boot shared kernel factory from Starlite\Console\Console */
    public function __construct(private readonly \Closure $boot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('urls', InputArgument::IS_ARRAY, 'Paths on this site (blog/my-post) or full URLs')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Purge everything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $arguments */
        $arguments = $input->getArgument('urls');
        $all = (bool) $input->getOption('all');
        if ($all === ($arguments !== [])) {
            $io->error($all ? 'Give either URLs or --all, not both.' : 'Which pages? Give paths or URLs (cdn:purge blog/my-post), or --all for everything.');

            return Command::INVALID;
        }

        $cdn = ($this->boot)(null)->cdn;
        try {
            $urls = array_map($cdn->url(...), $arguments);
            $purger = $cdn->purger();
            $all ? $purger->purgeAll() : $purger->purge($urls);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($all) {
            $io->success("Everything purged from {$purger->name()}.");
        } else {
            $io->listing($urls);
            $io->success(sprintf('%d URL%s purged from %s.', count($urls), count($urls) === 1 ? '' : 's', $purger->name()));
        }

        return Command::SUCCESS;
    }
}
