<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Monolog\Logger;
use Starlite\Log\Log;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class LogTest extends FrameworkTestCase
{
    /** @var \ArrayObject<int, Email> what the test transport received */
    private \ArrayObject $sent;

    protected function setUp(): void
    {
        $this->sent = new \ArrayObject();
    }

    public function testEntriesSayWhichRequestButNotWho(): void
    {
        $app = $this->kernel(debug: false);
        $app->handle(Request::create('/boom?email=ada@example.test', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'SecretBrowser/1.0',
            'HTTP_REFERER' => 'https://elsewhere.test/private',
        ]));

        $log = $this->logged();
        self::assertStringContainsString('app.ERROR: secret failure detail', $log);
        self::assertStringContainsString('{"method":"GET","path":"/boom","route":"boom"}', $log);
        foreach (['203.0.113.9', 'SecretBrowser', 'elsewhere.test', 'ada@example.test'] as $personal) {
            self::assertStringNotContainsString($personal, $log);
        }
    }

    public function testOneFilePerDay(): void
    {
        $dir = $this->tempDir('logs');
        $app = $this->kernel(overrides: ['log' => ['path' => $dir . '/app.log']]);
        $app->logger->info('Hello');

        self::assertSame(['app-' . date('Y-m-d') . '.log'], array_map(basename(...), glob($dir . '/*') ?: []));
    }

    public function testNotFoundIsNotLogged(): void
    {
        $app = $this->kernel(debug: false);

        self::assertSame(404, $this->request($app, '/wp-login.php')->getStatusCode());
        self::assertSame('', $this->logged(), 'what visitors typed in the address bar is nobody\'s business');
    }

    public function testTracesHaveNoArgumentsInProduction(): void
    {
        // PHP's development defaults: arguments in traces, strings up to 15 characters.
        $previous = [ini_set('zend.exception_ignore_args', '0'), ini_set('zend.exception_string_param_max_len', '15')];
        try {
            $app = $this->kernel(debug: false);
            $check = static fn (string $password) => throw new \RuntimeException('Check failed');
            $app->get('/check', static fn () => $check('hunter2'));
            $this->request($app, '/check');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous[0]);
            ini_set('zend.exception_string_param_max_len', (string) $previous[1]);
        }

        self::assertStringContainsString('[stacktrace]', $this->logged());
        self::assertStringNotContainsString('hunter2', $this->logged());
    }

    public function testTheLevelSetsWhatIsWritten(): void
    {
        $app = $this->kernel(overrides: ['log' => ['level' => 'warning']]);
        $app->logger->info('Just so you know');
        $app->logger->warning('Look at this');

        self::assertStringNotContainsString('Just so you know', $this->logged());
        self::assertStringContainsString('app.WARNING: Look at this', $this->logged());
    }

    public function testDebugLevelByDefaultInDevelopmentInfoInProduction(): void
    {
        $this->kernel(debug: true)->logger->debug('dev detail');
        $this->kernel(debug: false)->logger->debug('prod detail');

        self::assertStringContainsString('dev detail', $this->logged());
        self::assertStringNotContainsString('prod detail', $this->logged());
    }

    public function testOldFilesAreDeleted(): void
    {
        $dir = $this->tempDir('logs');
        for ($day = 1; $day <= 20; ++$day) {
            touch(sprintf('%s/app-2026-01-%02d.log', $dir, $day));
        }
        $logger = Log::create(self::PROJECT, ['path' => $dir . '/app.log', 'days' => 14], false);
        $logger->info('Today');
        $logger->close();

        $files = array_map(basename(...), glob($dir . '/*.log') ?: []);
        self::assertCount(14, $files);
        self::assertContains('app-' . date('Y-m-d') . '.log', $files);
        self::assertNotContains('app-2026-01-07.log', $files, 'the oldest go first');
        self::assertContains('app-2026-01-08.log', $files);
    }

    public function testAStreamIsWrittenAsIs(): void
    {
        $file = $this->tempDir('logs') . '/all.log';
        Log::create(self::PROJECT, ['path' => 'file://' . $file], false)->warning('Streamed');

        self::assertStringContainsString('app.WARNING: Streamed', (string) file_get_contents($file));
    }

    public function testConfigurationErrorsAreClear(): void
    {
        foreach ([
            [['level' => 'loud'], '"level" (LOG_LEVEL) is one of debug, info'],
            [['days' => 0], '"days" is how many days'],
            [['file' => 'x.log'], 'unknown option "file"'],
            [['alert_to' => 'me@example.test'], 'set MAILER_DSN and MAILER_FROM too'],
        ] as [$config, $message]) {
            try {
                Log::create(self::PROJECT, $config, false);
                self::fail("No error for {$message}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testAnErrorIsEmailedWithWhatLedToIt(): void
    {
        $logger = $this->alerting();
        $logger->info('Nothing to see');
        $logger->reset();
        self::assertCount(0, $this->sent, 'no error, no email');

        $logger->info('Loading the price list');
        $logger->error('Price list unreadable');
        $logger->reset(); // end of the request

        self::assertCount(1, $this->sent);
        [$email] = $this->sent->getArrayCopy();
        self::assertSame('[Fixture] ERROR: Price list unreadable', $email->getSubject());
        self::assertSame('me@example.test', $email->getTo()[0]->getAddress());
        self::assertSame('site@example.test', $email->getFrom()[0]->getAddress());
        self::assertStringContainsString('app.INFO: Loading the price list', (string) $email->getTextBody());
        self::assertStringContainsString('app.ERROR: Price list unreadable', (string) $email->getTextBody());
        self::assertStringNotContainsString('Nothing to see', (string) $email->getTextBody(), 'only this request\'s lines');
    }

    public function testTheSameErrorIsEmailedOncePerHour(): void
    {
        $logger = $this->alerting();
        foreach (['Disk full', 'Disk full', 'Mailer down', 'Disk full'] as $error) {
            $logger->error($error);
            $logger->reset();
        }

        self::assertSame(['[Fixture] ERROR: Disk full', '[Fixture] ERROR: Mailer down'], array_map(static fn (Email $e) => $e->getSubject(), $this->sent->getArrayCopy()));
    }

    public function testAnAlertThatCantBeSentIsLogged(): void
    {
        $dir = $this->tempDir('logs');
        $broken = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new \RuntimeException('SMTP said no');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        };
        $logger = Log::create(self::PROJECT, ['path' => $dir . '/app.log', 'alert_to' => 'me@example.test'], false, ['from' => 'site@example.test'], 'Fixture', $broken);
        $logger->error('Something broke');
        $logger->reset();

        $log = (string) file_get_contents($dir . '/app-' . date('Y-m-d') . '.log');
        self::assertStringContainsString('app.ERROR: Something broke', $log);
        self::assertStringContainsString('The alert email could not be sent: SMTP said no', $log);
    }

    public function testTheKernelSendsAlertsThroughTheSiteMailer(): void
    {
        $app = $this->kernel(debug: false, overrides: ['log' => ['alert_to' => 'me@example.test'], 'mailer' => ['dsn' => 'null://null', 'from' => 'site@example.test']]);

        self::assertSame(500, $this->request($app, '/boom')->getStatusCode());
        $app->logger->reset();
        self::assertStringContainsString('secret failure detail', $this->logged());
    }

    public function testFormsLogWhatHappenedNotWhatWasSent(): void
    {
        $app = $this->kernel(overrides: ['forms' => ['contact' => ['fields' => ['message' => 'textarea'], 'to' => 'owner@example.test', 'spam' => []]], 'mailer' => ['dsn' => 'null://null', 'from' => 's@example.test']]);
        $app->forms->submit('contact', Request::create('/contact', 'POST', ['message' => 'My private story']));

        self::assertStringContainsString('app.INFO: Form "contact" sent.', $this->logged());
        self::assertStringNotContainsString('private story', $this->logged());
    }

    public function testPhpWarningsInProductionAreLoggedButNotDeprecations(): void
    {
        $log = $this->tempDir('logs') . '/run.log';
        // Deprecations are left to PHP's own settings (production php.ini ignores them): off here.
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __DIR__ . '/data/run.php', '/warning', $this->tempDir('cache'), 'file://' . $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertSame('still rendered', $output, 'a warning doesn\'t stop the page');
        self::assertStringContainsString('app.ERROR: Warning: Undefined array key "missing"', (string) file_get_contents($log));
        self::assertStringNotContainsString('An old way of doing things', (string) file_get_contents($log));
    }

    /** A logger that emails errors to me@example.test, into $this->sent. */
    private function alerting(): Logger
    {
        $transport = new class ($this->sent) implements TransportInterface {
            /** @param \ArrayObject<int, Email> $sent */
            public function __construct(private readonly \ArrayObject $sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                \assert($message instanceof Email);
                $this->sent->append($message);

                return null;
            }

            public function __toString(): string
            {
                return 'test://';
            }
        };

        return Log::create(self::PROJECT, ['path' => $this->tempDir('logs') . '/app.log', 'alert_to' => 'me@example.test'], false, ['from' => 'site@example.test'], 'Fixture', $transport);
    }
}
