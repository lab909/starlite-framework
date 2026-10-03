<?php

declare(strict_types=1);

namespace Starlite\Tests;

final class FeedSitemapTest extends KernelTestCase
{
    public function testFeedListsPublishedPostsOfTheCurrentLanguage(): void
    {
        $app = $this->kernel(debug: true);

        $en = $this->xml($this->request($app, '/blog/feed.xml'), 'application/atom+xml');
        self::assertSame('en', $en->documentElement?->getAttribute('xml:lang'));
        self::assertSame(['https://example.test/blog/beta', 'https://example.test/blog/alpha'], $this->values($en, '//a:entry/a:id'), 'the draft is never in the feed');
        self::assertSame(['https://example.test/blog/feed.xml'], $this->values($en, '//a:feed/a:link[@rel="self"]/@href'));

        $it = $this->xml($this->request($app, '/it/blog/feed.xml'), 'application/atom+xml');
        self::assertSame('it', $it->documentElement?->getAttribute('xml:lang'));
        self::assertSame(['https://example.test/it/blog/alpha', 'https://example.test/it/blog/gamma'], $this->values($it, '//a:entry/a:id'));
        self::assertSame(['Alfa', 'Gamma solo italiano'], $this->values($it, '//a:entry/a:title'));
    }

    public function testSitemapListsEveryLanguageVersionButNoDrafts(): void
    {
        $sitemap = $this->xml($this->request($this->kernel(debug: true), '/sitemap.xml'), 'application/xml');
        $urls = $this->values($sitemap, '//s:loc');

        foreach (['/', '/about', '/blog', '/blog/alpha', '/blog/beta', '/it', '/it/about', '/it/blog', '/it/blog/alpha', '/it/blog/gamma'] as $path) {
            self::assertContains('https://example.test' . ($path === '/' ? '/' : $path), $urls);
        }
        self::assertNotContains('https://example.test/blog/delta', $urls);
        self::assertNotContains('https://example.test/it/blog/beta', $urls, 'untranslated posts are not listed in that language');
        foreach ($urls as $url) {
            self::assertDoesNotMatchRegularExpression('#\{|\.xml$|\.txt$|/datastar|/hello/#', $url, 'only static GET pages and posts');
        }
        self::assertContains('2026-09-12', $this->values($sitemap, '//s:url[s:loc="https://example.test/blog/alpha"]/s:lastmod'));
    }

    public function testRobotsPointsAtTheSitemap(): void
    {
        $response = $this->request($this->kernel(), '/robots.txt');

        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString("Sitemap: https://example.test/sitemap.xml\n", self::body($response));
    }

    /** @param non-empty-string $type */
    private function xml(\Symfony\Component\HttpFoundation\Response $response, string $type): \DOMDocument
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith($type, (string) $response->headers->get('Content-Type'));
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML(self::body($response)), 'well-formed XML');

        return $document;
    }

    /** @return list<string> */
    private function values(\DOMDocument $document, string $query): array
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $values = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $values[] = trim((string) $node->nodeValue);
        }

        return $values;
    }
}
