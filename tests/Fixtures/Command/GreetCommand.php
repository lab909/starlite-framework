<?php

declare(strict_types=1);

namespace Starlite\Tests\Fixtures\Command;

use Starlite\Console\AppCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('fixture:greet', 'Fixture app command')]
final class GreetCommand extends AppCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('greet from ' . $this->app()->site->name . ' debug=' . var_export($this->app()->debug, true));
        is_dir($this->app()->cacheDir) || mkdir($this->app()->cacheDir, 0777, true);
        file_put_contents($this->app()->cacheDir . '/fixture-command.txt', 'ran');

        return Command::SUCCESS;
    }
}
