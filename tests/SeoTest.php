<?php

declare(strict_types=1);

namespace Starlite\Tests;

final class SeoTest extends FrameworkTestCase
{
    public function testPageGetsTitleCanonicalOpenGraphAndTwitterTags(): void
    {
        $html = self::body($this->request($this->kernel(), '/about'));

        self::assertStringContainsString('<title>Fixture</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Fixture site">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.test/about">', $html);
        self::assertStringContainsString('<meta property="og:url" content="https://example.test/about">', $html);
        self::assertStringContainsString('<meta property="og:locale" content="en_US">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
    }

    public function testAbsoluteUrlsNeverComeFromTheHostHeader(): void
    {
        // Pages are publicly cacheable: a forged Host must not end up in a cached page.
        $app = $this->kernel();
        $html = self::body($this->request($app, '/about', 'GET', ['Host' => 'evil.example']));

        self::assertStringNotContainsString('evil.example', $html);
        self::assertStringContainsString('href="https://example.test/about"', $html);
        self::assertStringNotContainsString('evil.example', self::body($this->request($app, '/sitemap.xml', 'GET', ['Host' => 'evil.example'])));
    }

    public function testHreflangListsEveryLanguageForSharedPages(): void
    {
        $html = self::body($this->request($this->kernel(), '/it/about'));

        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://example.test/about">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="it" href="https://example.test/it/about">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://example.test/about">', $html);
        self::assertStringContainsString('<meta property="og:locale" content="it_IT">', $html);
    }

    public function testPostPagesGetArticleTagsAndEscapedJsonLd(): void
    {
        $html = self::body($this->request($this->kernel(), '/blog/alpha'));

        self::assertStringContainsString('<title>Alpha &quot;quoted&quot; &amp; &lt;b&gt;bold&lt;/b&gt; · Fixture</title>', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<meta property="article:published_time" content="2026-09-10">', $html);
        self::assertStringContainsString('<meta property="article:modified_time" content="2026-09-12">', $html);
        self::assertStringContainsString('<meta property="article:tag" content="php">', $html);
        self::assertStringContainsString('<meta property="og:image" content="https://example.test/media/blog/alpha/cover.png">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);

        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match));
        $json = $match[1] ?? '';
        self::assertStringNotContainsString('<b>', $json, 'tags inside JSON-LD are escaped');
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('BlogPosting', $data['@type']);
        self::assertSame('Alpha "quoted" & <b>bold</b>', $data['headline']);
        self::assertSame('Ada', $data['author']['name']);
        self::assertSame('https://example.test/blog/alpha', $data['url']);
    }

    public function testHreflangOnlyListsExistingTranslations(): void
    {
        $html = self::body($this->request($this->kernel(), '/blog/beta'));

        self::assertStringContainsString('hreflang="en" href="https://example.test/blog/beta"', $html);
        self::assertStringNotContainsString('hreflang="it"', $html);
    }

    public function testNoindexPagesGetNoHreflang(): void
    {
        $html = self::body($this->request($this->kernel(), '/nope'));

        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringNotContainsString('hreflang', $html);
    }
}
