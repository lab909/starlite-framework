<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Cdn\BunnyPurger;
use Starlite\Cdn\Cdn;
use Starlite\Cdn\CloudflarePurger;
use Starlite\Cdn\CommandPurger;
use Starlite\Cdn\Purger;
use Starlite\Console\CdnPurgeCommand;
use Starlite\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

final class CdnTest extends FrameworkTestCase
{
    private const CACHED = 'max-age=0, public, s-maxage=%d, stale-if-error=86400, stale-while-revalidate=60'; // in Symfony's order

    /** @var list<array{string, string, array<string, string>, ?string}> requests the fake CDN APIs received */
    private array $requests = [];

    // --- Caching ----------------------------------------------------------------------

    public function testOffByDefault(): void
    {
        self::assertSame('no-cache, public', $this->request($this->kernel(), '/about')->headers->get('Cache-Control'));
    }

    public function testPagesAreKeptAtTheCdnAndServedWhileTheSiteIsDown(): void
    {
        $response = $this->request($this->cdn(), '/about');

        self::assertSame(sprintf(self::CACHED, 300), $response->headers->get('Cache-Control'), 'no purge set up: 5 minutes');
        self::assertNotNull($response->getEtag(), 'the CDN revalidates cheaply');
    }

    public function testAnHourWhenDeployPurgesTheCdn(): void
    {
        self::assertSame(sprintf(self::CACHED, 3600), $this->request($this->cdn(['purge' => 'command', 'command' => 'true']), '/about')->headers->get('Cache-Control'));

        $app = $this->cdn();
        $app->cdn->usePurger($this->recorder());
        self::assertSame(sprintf(self::CACHED, 3600), $this->request($app, '/about')->headers->get('Cache-Control'), 'a package\'s purger counts too');

        self::assertSame(
            'max-age=0, public, s-maxage=600, stale-if-error=3600, stale-while-revalidate=0',
            $this->request($this->cdn(['ttl' => 600, 'stale_while_revalidate' => 0, 'stale_if_error' => 3600]), '/about')->headers->get('Cache-Control'),
        );
    }

    public function testExcludedRoutesAreNotKept(): void
    {
        $app = $this->cdn(['exclude' => ['about']]);

        self::assertSame('no-cache, public', $this->request($app, '/about')->headers->get('Cache-Control'));
        self::assertSame(sprintf(self::CACHED, 300), $this->request($app, '/')->headers->get('Cache-Control'));
        self::assertContains('datastar', $app->cdn->exclude, 'the Datastar endpoint, always');
    }

    public function testContentPagesCanOptOut(): void
    {
        $app = $this->cdn();

        self::assertSame('no-cache, public', $this->request($app, '/legal')->headers->get('Cache-Control'), 'cdn: false');
        self::assertSame('no-cache, public', $this->request($app, '/it/legal')->headers->get('Cache-Control'), 'translations inherit it');
        self::assertSame(sprintf(self::CACHED, 300), $this->request($app, '/legal/privacy')->headers->get('Cache-Control'), 'not its children');
        self::assertSame(sprintf(self::CACHED, 300), $this->request($app, '/about')->headers->get('Cache-Control'), 'the next request starts over');
    }

    public function testWhatIsNeverKept(): void
    {
        $app = $this->cdn(['forms' => ['contact' => ['fields' => ['message' => 'textarea'], 'to' => 'o@example.test', 'spam' => ['timing']]]]);
        $app->get('/contact', fn () => $app->twig->createTemplate("<form>{{ form_spam('contact') }}</form>")->render());

        self::assertSame('no-cache, public', $this->request($app, '/about', headers: ['Datastar-Request' => 'true'])->headers->get('Cache-Control'), 'asked for by Datastar');
        self::assertStringNotContainsString('s-maxage', (string) $this->request($app, '/nope')->headers->get('Cache-Control'), 'errors');
        self::assertStringNotContainsString('s-maxage', (string) $this->request($app, '/boom')->headers->get('Cache-Control'));
        self::assertSame('no-store, private', $this->request($app, '/contact')->headers->get('Cache-Control'), 'pages with a form');
    }

    public function testAHandlersOwnCacheControlWins(): void
    {
        $app = $this->cdn();
        $app->get('/mine', static fn () => (new Response('Hello Ada'))->setPrivate());
        $app->get('/brief', static fn () => (new Response('News'))->setPublic()->setMaxAge(60));

        self::assertSame('private', $this->request($app, '/mine')->headers->get('Cache-Control'));
        self::assertSame('max-age=60, public', $this->request($app, '/brief')->headers->get('Cache-Control'));
        self::assertNotNull($this->request($app, '/mine')->getEtag(), 'still revalidated with an ETag');
    }

    public function testConfigurationErrorsAreClear(): void
    {
        foreach ([
            [['purge' => 'akamai'], '"purge" (CDN_PURGE) is one of cloudflare, bunny, command'],
            [['ttl' => '1h'], '"ttl" is a number of seconds'],
            [['exclude' => 'clock'], '"exclude" is a list of route names'],
            [['enable' => true], 'unknown option "enable"'],
        ] as [$config, $message]) {
            try {
                new Cdn($config, self::PROJECT, 'https://example.test');
                self::fail("No error for {$message}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    // --- Purging ----------------------------------------------------------------------

    public function testCloudflare(): void
    {
        $purger = new CloudflarePurger(str_repeat('a', 32), 'cf-secret-token', $this->api(200, '{"success":true}'));
        $purger->purgeAll();
        $purger->purge(array_map(static fn (int $i) => "https://example.test/p{$i}", range(1, 31)));

        self::assertCount(3, $this->requests, 'everything, then the URLs in batches of 30');
        [$method, $url, $headers, $body] = $this->requests[0];
        self::assertSame(['POST', 'https://api.cloudflare.com/client/v4/zones/' . str_repeat('a', 32) . '/purge_cache'], [$method, $url]);
        self::assertSame('Bearer cf-secret-token', $headers['Authorization']);
        self::assertSame('{"purge_everything":true}', $body);
        self::assertCount(30, json_decode((string) $this->requests[1][3], true)['files']);
        self::assertSame(['files' => ['https://example.test/p31']], json_decode((string) $this->requests[2][3], true));
    }

    public function testBunny(): void
    {
        $purger = new BunnyPurger('12345', 'bunny-secret-key', $this->api(204, ''));
        $purger->purgeAll();
        $purger->purge(['https://example.test/blog/my-post?x=1']);

        self::assertSame('https://api.bunny.net/pullzone/12345/purgeCache', $this->requests[0][1]);
        self::assertSame('https://api.bunny.net/purge?url=https%3A%2F%2Fexample.test%2Fblog%2Fmy-post%3Fx%3D1&async=false', $this->requests[1][1]);
        self::assertSame('bunny-secret-key', $this->requests[1][2]['AccessKey']);
    }

    public function testRefusalsSayWhyWithoutTheCredentials(): void
    {
        foreach ([
            [new CloudflarePurger(str_repeat('a', 32), 'cf-secret-token', $this->api(403, '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}')), 'Cloudflare refused the purge: Authentication error.', 'cf-secret-token'],
            [new BunnyPurger('12345', 'bunny-secret-key', $this->api(401, '{"Message":"Unauthorized"}')), 'Bunny refused the purge: Unauthorized.', 'bunny-secret-key'],
            [new BunnyPurger('12345', 'bunny-secret-key', $this->api(0, '')), 'Bunny refused the purge: the API could not be reached.', 'bunny-secret-key'],
        ] as [$purger, $message, $secret]) {
            try {
                $purger->purgeAll();
                self::fail("No error for {$message}");
            } catch (\RuntimeException $e) {
                self::assertSame($message, $e->getMessage());
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function testMissingCredentialsAreExplained(): void
    {
        $this->expectExceptionMessage('Cloudflare purging needs CLOUDFLARE_ZONE_ID');
        $this->cdn(['purge' => 'cloudflare'])->cdn->purger();
    }

    public function testACommandGetsTheUrls(): void
    {
        $dir = $this->tempDir('purge');
        file_put_contents("{$dir}/purge.sh", "#!/bin/sh\necho \"\$#:\$*\" >> {$dir}/calls.txt\n");
        chmod("{$dir}/purge.sh", 0755);
        $purger = new CommandPurger("{$dir}/purge.sh", $dir);
        $purger->purgeAll();
        $purger->purge(['https://example.test/a b', "https://example.test/it/x';rm -rf /"]);

        self::assertSame("0:\n2:https://example.test/a b https://example.test/it/x';rm -rf /\n", file_get_contents("{$dir}/calls.txt"), 'each URL one argument, quoted');

        $this->expectExceptionMessage('The purge command failed (exit code 3): nope');
        (new CommandPurger('echo nope; exit 3', $dir))->purgeAll();
    }

    public function testThePurgeCommandTakesPathsOrUrls(): void
    {
        $recorder = $this->recorder();
        $tester = $this->purgeCommand($recorder);
        $tester->execute(['urls' => ['blog/my-post', '/it/chi-siamo', 'https://example.com/about']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([['https://example.test/blog/my-post', 'https://example.test/it/chi-siamo', 'https://example.com/about']], $recorder->purged);
        self::assertStringContainsString('3 URLs purged from Recorder.', $tester->getDisplay());

        $tester->execute(['--all' => true]);
        self::assertSame(1, $recorder->all);
        self::assertStringContainsString('Everything purged from Recorder.', $tester->getDisplay());
    }

    public function testThePurgeCommandNeedsToBeToldWhat(): void
    {
        $tester = $this->purgeCommand($this->recorder());
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('Which pages?', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['urls' => ['x'], '--all' => true]));

        $tester = new CommandTester(new CdnPurgeCommand(fn () => $this->kernel()));
        self::assertSame(Command::FAILURE, $tester->execute(['--all' => true]));
        self::assertStringContainsString('No CDN purge set up', $tester->getDisplay());
    }

    /** @param array<string, mixed> $cdn */
    private function cdn(array $cdn = []): Kernel
    {
        $forms = $cdn['forms'] ?? null;
        unset($cdn['forms']);

        return $this->kernel(debug: false, overrides: ['cdn' => ['enabled' => true] + $cdn] + ($forms !== null ? ['forms' => $forms] : []));
    }

    /** A fake CDN API answering every request with $status and $body, recording the requests. */
    private function api(int $status, string $body): \Closure
    {
        return function (string $method, string $url, array $headers, ?string $payload) use ($status, $body): array {
            $this->requests[] = [$method, $url, $headers, $payload];

            return [$status, $body];
        };
    }

    private function recorder(): RecordingPurger
    {
        return new RecordingPurger();
    }

    private function purgeCommand(Purger $purger): CommandTester
    {
        $app = $this->kernel();
        $app->cdn->usePurger($purger);

        return new CommandTester(new CdnPurgeCommand(static fn () => $app));
    }
}

/** A purger that remembers what it was asked to purge. */
final class RecordingPurger implements Purger
{
    public int $all = 0;

    /** @var list<list<string>> */
    public array $purged = [];

    public function name(): string
    {
        return 'Recorder';
    }

    public function purgeAll(): void
    {
        ++$this->all;
    }

    public function purge(array $urls): void
    {
        $this->purged[] = $urls;
    }
}
