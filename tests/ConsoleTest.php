<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Console\Console;
use Starlite\Tests\Fixtures\Command\GreetCommand;
use Starlite\Tests\Fixtures\Command\PlainCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleTest extends FrameworkTestCase
{
    private const COMMANDS = __DIR__ . '/Fixtures/Command';
    private const NAMESPACE = 'Starlite\\Tests\\Fixtures\\Command\\';

    // --- Command discovery --------------------------------------------------------

    public function testDiscoversConcreteCommandsOnly(): void
    {
        self::assertSame([GreetCommand::class, PlainCommand::class], Console::discover(self::COMMANDS, self::NAMESPACE));
        self::assertSame([], Console::discover('/does/not/exist', self::NAMESPACE));
    }

    public function testCommandsNeedingConstructorArgumentsAreRejected(): void
    {
        $this->expectExceptionMessage('commands in src/Command/ are created without arguments; extend AppCommand');
        Console::discover(__DIR__ . '/Fixtures/BadCommand', 'Starlite\\Tests\\Fixtures\\BadCommand\\');
    }

    public function testClassNamesMustMatchTheirFiles(): void
    {
        $dir = $this->tempDir('commands');
        self::write($dir, ['Stray.php' => '<?php']);

        $this->expectExceptionMessage('must declare class Nowhere\\Stray');
        Console::discover($dir, 'Nowhere\\');
    }

    public function testListingCommandsDoesNotBootTheApp(): void
    {
        // No config at all: listing and plain commands still work; AppCommands boot the kernel lazily.
        $console = Console::application($this->tempDir('empty'), self::COMMANDS, self::NAMESPACE);

        self::assertTrue($console->has('fixture:greet'));
        self::assertTrue($console->has('fixture:plain'));
        self::assertTrue($console->has('deploy'));
        self::assertTrue($console->has('cache:clear'));
    }

    public function testAppCommandsGetTheKernel(): void
    {
        $tester = new CommandTester($this->console($this->project())->find('fixture:greet'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('greet from Fixture debug=false', $tester->getDisplay());
    }

    // --- Deploy ---------------------------------------------------------------------

    public function testDeployStepsIncludeTheAppsStepsWhereTheyAsked(): void
    {
        $tester = $this->deploy($this->project(), ['--list-steps' => true, '--skip' => 'vite']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        preg_match_all('/^\s{2}(\S+)/m', $tester->getDisplay(), $rows);
        self::assertSame(
            ['Step', 'composer', 'cache', 'fixture-closure', 'routes', 'blog', 'pages', 'collections', 'translations', 'templates', 'vite', 'fixture-command', 'opcache'],
            $rows[1],
        );
        self::assertStringContainsString('vite (skipped)', $tester->getDisplay());
    }

    public function testDeployBuildsEverythingAndRunsAppSteps(): void
    {
        $root = $this->project();
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('3 blog posts compiled (4 language versions)', $tester->getDisplay());
        self::assertStringContainsString('greet from Fixture debug=false', $tester->getDisplay(), 'command steps share the production kernel');

        foreach (['routes.matcher.php', 'routes.generator.php', 'blog.php', 'twig'] as $file) {
            self::assertFileExists("{$root}/var/cache/{$file}");
        }
        self::assertNotEmpty(glob("{$root}/var/cache/translations/catalogue.it.*.php"));
        self::assertSame('ran:false', file_get_contents("{$root}/var/cache/fixture-step.txt"));
        self::assertFileExists("{$root}/var/cache/fixture-command.txt");
        self::assertFileExists("{$root}/public/media/blog/alpha/cover.png");
        self::assertDirectoryDoesNotExist("{$root}/public/media/blog/delta");
    }

    public function testDeployStopsAtAFailingStep(): void
    {
        $root = $this->project();
        self::write($root, ['config/bootstrap.php' => '<?php return static function (Starlite\Kernel $app): void {
            $app->addDeployStep("fails", static fn () => false, after: "cache");
        };']);
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Deploy stopped at step "fails"', $tester->getDisplay());
        self::assertFileDoesNotExist("{$root}/var/cache/routes.matcher.php", 'later steps did not run');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidDeploys(): iterable
    {
        yield 'unknown skipped step' => [['--skip' => 'nope'], 'Unknown step(s) in --skip: nope'];
        yield 'unknown opcache mode' => [['--opcache' => 'magic'], 'Unknown --opcache mode "magic"'];
        yield 'reload without a command' => [['--opcache' => 'reload'], '--opcache=reload needs --reload-cmd'];
    }

    /** @param array<string, string> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDeploys')]
    public function testInvalidDeployOptionsChangeNothing(array $options, string $message): void
    {
        $root = $this->project();
        $tester = $this->deploy($root, $options);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertDirectoryDoesNotExist("{$root}/var/cache/twig");
    }

    public function testAppStepWithUnknownAnchorIsRejected(): void
    {
        $root = $this->project();
        self::write($root, ['config/bootstrap.php' => '<?php return static function (Starlite\Kernel $app): void {
            $app->addDeployStep("lost", static fn () => true, before: "nope");
        };']);
        $tester = $this->deploy($root, ['--list-steps' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('refers to unknown step "nope"', $tester->getDisplay());
    }

    public function testCacheClearKeepsGitkeep(): void
    {
        $root = $this->project();
        self::write($root, ['var/cache/.gitkeep' => '', 'var/cache/x/y.php' => '<?php', 'public/media/blog/a/b.png' => 'x', 'public/media/pages/about/c.png' => 'x']);
        $tester = new CommandTester($this->console($root)->find('cache:clear'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['.gitkeep'], array_values(array_diff((array) scandir("{$root}/var/cache"), ['.', '..'])));
        self::assertSame([], array_values(array_diff((array) scandir("{$root}/public/media/blog"), ['.', '..'])));
        self::assertSame([], array_values(array_diff((array) scandir("{$root}/public/media/pages"), ['.', '..'])));
    }

    public function testCacheClearRestoresTheDevelopmentAutoloaderAfterADeploy(): void
    {
        $root = $this->project();
        // A stand-in for Composer that records how it was called.
        self::write($root, ['bin/fake-composer' => "#!/bin/sh\necho \"\$@\" >> composer.log\nexit \${FAKE_EXIT:-0}\n"]);
        chmod("{$root}/bin/fake-composer", 0755);
        $run = fn (): int => (new CommandTester($this->console($root)->find('cache:clear')))->execute(['--composer' => "{$root}/bin/fake-composer"]);

        // Normal autoloader: Composer isn't called.
        self::write($root, ['vendor/composer/autoload_real.php' => '<?php // $loader->register(true);']);
        self::assertSame(Command::SUCCESS, $run());
        self::assertFileDoesNotExist("{$root}/composer.log");

        // deploy's authoritative class map: rebuilt without it.
        self::write($root, ['vendor/composer/autoload_real.php' => '<?php $loader->setClassMapAuthoritative(true);']);
        self::assertSame(Command::SUCCESS, $run());
        self::assertSame("dump-autoload --no-interaction --quiet\n", file_get_contents("{$root}/composer.log"));

        putenv('FAKE_EXIT=1');
        try {
            self::assertSame(Command::FAILURE, $run());
        } finally {
            putenv('FAKE_EXIT');
        }
    }

    /** A writable copy of the fixture project, with the fixture content inside it. */
    private function project(): string
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        rename($this->copyToTemp(self::CONTENT, 'content'), $root . '/content');

        return $root;
    }

    private function console(string $root): Application
    {
        return Console::application($root, self::COMMANDS, self::NAMESPACE);
    }

    /** @param array<string, mixed> $options */
    private function deploy(string $root, array $options): CommandTester
    {
        $tester = new CommandTester($this->console($root)->find('deploy'));
        $tester->execute($options);

        return $tester;
    }
}
