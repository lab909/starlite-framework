<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\TestCase;
use Starlite\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Boots the fixture project (lib/tests/data/project) with the fixture content
 * (lib/tests/data/content) and a throw-away cache directory, and drives it through
 * Kernel::handle(), so no web server is needed.
 */
abstract class KernelTestCase extends TestCase
{
    protected const PROJECT = __DIR__ . '/data/project';
    protected const CONTENT = __DIR__ . '/data/content';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::remove($dir);
        }
        $this->tempDirs = [];
    }

    /** @param array<string, mixed> $overrides merged over the fixture's config/app.php */
    protected function kernel(bool $debug = true, array $overrides = [], string $root = self::PROJECT): Kernel
    {
        return Kernel::boot($root, $debug, array_replace_recursive([
            'content_dir' => self::CONTENT,
            'cache_dir' => $this->tempDir('cache'),
        ], $overrides));
    }

    /** @param array<string, string> $headers e.g. ['Sec-Fetch-Site' => 'same-origin'] */
    protected function request(Kernel $app, string $uri, string $method = 'GET', array $headers = [], ?string $body = null): Response
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $app->handle(Request::create($uri, $method, [], [], [], $server, $body));
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
