<?php

declare(strict_types=1);

namespace Starlite\Tests;

final class MediaUrlTest extends FrameworkTestCase
{
    public function testFilesComeFromTheCdn(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        self::write($root, ['public/media/embeds/youtube-aqz-KE-bpKQ.json' => '{"title":"T","poster":"youtube-aqz-KE-bpKQ.jpg"}']);
        $app = $this->kernel(overrides: ['media_url' => 'https://cdn.example.test/site/'], root: $root);

        $post = $app->posts()->slug('alpha')->one();
        self::assertNotNull($post);
        self::assertStringContainsString('<img src="https://cdn.example.test/site/media/blog/alpha/cover.png"', $post['html'], 'links inside posts');
        self::assertStringContainsString('href="https://cdn.example.test/site/media/blog/alpha/files/doc.pdf"', $post['html']);
        self::assertSame('https://cdn.example.test/site/media/pages/legal/privacy/shield.png', $app->pages()->where('path', 'legal/privacy')->one()['image'] ?? null, 'share images');
        self::assertSame('https://cdn.example.test/site/media/embeds/youtube-aqz-KE-bpKQ.jpg', $app->embeds->video('youtube', 'aqz-KE-bpKQ')['poster']);

        $html = $this->body($this->request($app, '/legal/privacy'));
        self::assertStringContainsString('<meta property="og:image" content="https://cdn.example.test/site/media/pages/legal/privacy/shield.png">', $html, 'already absolute: kept');
        $csp = (string) $this->request($app, '/')->headers->get('Content-Security-Policy');
        self::assertStringContainsString("img-src 'self' data: https://cdn.example.test;", $csp);
        self::assertStringContainsString("media-src 'self' https://cdn.example.test;", $csp);
    }

    public function testWithoutItEverythingStaysOnTheSite(): void
    {
        $app = $this->kernel();

        self::assertSame('', $app->mediaUrl);
        self::assertStringContainsString('<img src="/media/blog/alpha/cover.png"', $app->posts()->slug('alpha')->one()['html'] ?? '');
        self::assertStringContainsString("img-src 'self' data:;", (string) $this->request($app, '/')->headers->get('Content-Security-Policy'));
    }

    public function testOnlyHttpsUrls(): void
    {
        foreach (['http://cdn.example.test', 'cdn.example.test', 'https://cdn.example.test/a b', 'https://cdn.example.test/?x=1'] as $url) {
            try {
                $this->kernel(overrides: ['media_url' => $url]);
                self::fail("{$url} must be refused.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('MEDIA_URL must be an https:// URL', $e->getMessage());
            }
        }
    }
}
