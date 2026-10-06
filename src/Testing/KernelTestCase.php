<?php

declare(strict_types=1);

namespace Starlite\Testing;

use PHPUnit\Framework\TestCase;
use Starlite\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base class for tests that drive a Starlite app through Kernel::handle(), so no web server is
 * needed: boot the app, send requests, read any response body (including Datastar streams) and the
 * log, and use throw-away directories.
 *
 *   final class AboutTest extends KernelTestCase
 *   {
 *       public function testAbout(): void
 *       {
 *           $app = $this->bootKernel(dirname(__DIR__), overrides: ['content_dir' => __DIR__ . '/data/content']);
 *           self::assertSame(200, $this->request($app, '/about')->getStatusCode());
 *       }
 *   }
 *
 * Needs phpunit/phpunit (a dev dependency of the app).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::remove($dir);
        }
        $this->tempDirs = [];
        $this->logDir = null;
    }

    private ?string $logDir = null;

    /**
     * Boots the app at $root with throw-away cache, image and log directories.
     *
     * @param array<string, mixed> $overrides merged over the app's config/app.php
     */
    protected function bootKernel(string $root, bool $debug = true, array $overrides = []): Kernel
    {
        $this->logDir ??= $this->tempDir('log'); // one per test, shared by the apps it boots
        $app = Kernel::boot($root, $debug, array_replace_recursive([
            'cache_dir' => $this->tempDir('cache'),
            'images_dir' => $this->tempDir('images'),
            'log' => ['path' => $this->logDir . '/app.log'],
        ], $overrides));
        // Before the first `npm run build`, pages render without their asset tags instead of failing
        // every test: tests that check assets call requireViteBuild().
        $app->vite->allowMissingBuild();

        return $app;
    }

    /**
     * Skips a test that checks built assets (script tags, preloads…) when `npm run build` hasn't run
     * yet, with a message saying so. CI builds before testing, so these tests run there.
     */
    protected function requireViteBuild(Kernel $app): void
    {
        if (!$app->vite->built()) {
            self::markTestSkipped('No Vite build yet: run `npm run build` (public/build/.vite/manifest.json is missing).');
        }
    }

    /**
     * @param array<string, string> $headers    e.g. ['Sec-Fetch-Site' => 'same-origin']
     * @param array<string, mixed>  $parameters form fields of a POST (or the query of a GET)
     */
    protected function request(Kernel $app, string $uri, string $method = 'GET', array $headers = [], ?string $body = null, array $parameters = []): Response
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $app->handle(Request::create($uri, $method, $parameters, [], [], $server, $body));
    }

    /** What the apps booted by this test have logged so far (their log files, oldest first). */
    protected function logged(): string
    {
        if ($this->logDir === null) {
            return '';
        }
        $files = glob($this->logDir . '/*.log') ?: [];
        sort($files);

        return implode('', array_map(static fn (string $file) => (string) file_get_contents($file), $files));
    }

    /** The body of any response, including streamed (SSE) and file responses. */
    protected static function body(Response $response): string
    {
        if ($response instanceof StreamedResponse || $response->getContent() === false) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    /** A fresh directory, deleted after the test. */
    protected function tempDir(string $name = 'tmp'): string
    {
        $dir = sys_get_temp_dir() . '/starlite-test-' . bin2hex(random_bytes(6)) . '-' . $name;
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** Copies a directory tree (fixture content or project) into a fresh temp directory. */
    protected function copyToTemp(string $source, string $name = 'copy'): string
    {
        $target = $this->tempDir($name);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            $to = $target . '/' . substr($item->getPathname(), strlen($source) + 1);
            $item->isDir() ? mkdir($to, 0777, true) : copy($item->getPathname(), $to);
        }

        return $target;
    }

    /** @param array<string, string> $files relative path => content */
    protected static function write(string $dir, array $files): void
    {
        foreach ($files as $path => $content) {
            if (!is_dir(dirname("{$dir}/{$path}"))) {
                mkdir(dirname("{$dir}/{$path}"), 0777, true);
            }
            file_put_contents("{$dir}/{$path}", $content);
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
