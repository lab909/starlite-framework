<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Vite;

final class ViteTest extends FrameworkTestCase
{
    private function root(): string
    {
        $root = $this->tempDir('vite');
        self::write($root, ['public/build/.vite/manifest.json' => json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-123.js', 'isEntry' => true, 'imports' => ['_shared.js'], 'css' => ['assets/app-123.css']],
            '_shared.js' => ['file' => 'assets/shared-456.js', 'css' => ['assets/shared-456.css']],
            'resources/css/print.css' => ['file' => 'assets/print-789.css', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR)]);

        return $root;
    }

    public function testProductionTagsComeFromTheManifest(): void
    {
        $tags = (new Vite($this->root(), $this->tempDir('cache'), false))->tags('resources/js/app.js', 'resources/css/print.css');

        self::assertSame(implode("\n", [
            '<link rel="stylesheet" href="/build/assets/shared-456.css">',
            '<link rel="stylesheet" href="/build/assets/app-123.css">',
            '<link rel="stylesheet" href="/build/assets/print-789.css">',
            '<link rel="modulepreload" href="/build/assets/shared-456.js">',
            '<script type="module" src="/build/assets/app-123.js"></script>',
        ]), $tags);
    }

    public function testDevServerIsUsedOnlyInDebugMode(): void
    {
        $root = $this->root();
        self::write($root, ['var/vite.hot' => "https://site.test:5173\n"]);

        self::assertSame(
            "<script type=\"module\" src=\"https://site.test:5173/@vite/client\"></script>\n<script type=\"module\" src=\"https://site.test:5173/resources/js/app.js\"></script>",
            (new Vite($root, $this->tempDir('cache'), true))->tags('resources/js/app.js'),
        );
        self::assertStringContainsString('/build/assets/app-123.js', (new Vite($root, $this->tempDir('cache'), false))->tags('resources/js/app.js'));
    }

    public function testMalformedHotFileIsIgnored(): void
    {
        $root = $this->root();
        self::write($root, ['var/vite.hot' => 'javascript:alert(1)']);

        self::assertStringContainsString('/build/assets/app-123.js', (new Vite($root, $this->tempDir('cache'), true))->tags('resources/js/app.js'));
    }

    public function testUnknownEntryFailsClearly(): void
    {
        $this->expectExceptionMessage('Vite entry "resources/js/nope.js" is not in the manifest');
        (new Vite($this->root(), $this->tempDir('cache'), false))->tags('resources/js/nope.js');
    }

    public function testWarmupReportsAMissingBuild(): void
    {
        self::assertFalse((new Vite($this->tempDir('empty'), $this->tempDir('cache'), false))->warmup());
        self::assertTrue((new Vite($this->root(), $cache = $this->tempDir('cache'), false))->warmup());
        self::assertFileExists($cache . '/vite.php');
    }
}
