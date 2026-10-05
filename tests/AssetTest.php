<?php

declare(strict_types=1);

namespace Starlite\Tests;

final class AssetTest extends FrameworkTestCase
{
    public function testServesPostFilesWithTheirContentType(): void
    {
        $response = $this->request($this->kernel(), '/media/blog/alpha/cover.png');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        // Images are served as published: re-saved without metadata, so not byte for byte.
        self::assertSame([4, 4, IMAGETYPE_PNG], array_slice((array) getimagesizefromstring(self::body($response)), 0, 3));
        self::assertSame('application/pdf', $this->request($this->kernel(), '/media/blog/alpha/files/doc.pdf')->headers->get('Content-Type'));
    }

    public function testFilesRevalidateWithTheirOwnEtag(): void
    {
        $app = $this->kernel();
        $png = $this->request($app, '/media/blog/alpha/cover.png');
        $pdf = $this->request($app, '/media/blog/alpha/files/doc.pdf');

        self::assertNotNull($png->getEtag());
        self::assertNotSame($png->getEtag(), $pdf->getEtag(), 'each file has its own ETag');
        self::assertSame(304, $this->request($app, '/media/blog/alpha/cover.png', 'GET', ['If-None-Match' => (string) $png->getEtag()])->getStatusCode());
    }

    public function testOnlyWhitelistedFilesAreServed(): void
    {
        $app = $this->kernel();

        foreach (['/media/blog/alpha/notes.txt', '/media/blog/alpha/index.md', '/media/blog/alpha/../beta/index.md', '/media/blog/alpha/%2e%2e/beta/index.md', '/media/blog/nope/cover.png'] as $path) {
            self::assertSame(404, $this->request($app, $path)->getStatusCode(), $path);
        }
    }

    public function testDraftFilesAreOnlyServedInDebugMode(): void
    {
        self::assertSame(200, $this->request($this->kernel(debug: true), '/media/blog/delta/draft.png')->getStatusCode());
        self::assertSame(404, $this->request($this->kernel(debug: false), '/media/blog/delta/draft.png')->getStatusCode());
    }

    public function testSvgIsSandboxed(): void
    {
        $response = $this->request($this->kernel(), '/media/blog/alpha/icon.svg');

        self::assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }
}
