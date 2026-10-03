<?php

declare(strict_types=1);

namespace Starlite\Tests\Fixtures\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand('fixture:plain', 'Plain Symfony command')]
final class PlainCommand extends Command
{
}
