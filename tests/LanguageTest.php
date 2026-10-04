<?php

declare(strict_types=1);

namespace Starlite\Tests;

final class LanguageTest extends FrameworkTestCase
{
    public function testSecondaryLanguageIsServedUnderItsPrefix(): void
    {
        $app = $this->kernel();
        $html = self::body($this->request($app, '/it'));

        self::assertStringContainsString('<html lang="it">', $html);
        self::assertStringContainsString('<h1>Benvenuto</h1>', $html);
        self::assertStringContainsString('<p id="link">/it/about</p>', $html, 'path() keeps the current language');
        self::assertSame('hello ada in it', self::body($this->request($app, '/it/hello/ada')));
    }

    public function testDefaultLanguagePrefixRedirectsToTheUnprefixedUrl(): void
    {
        $app = $this->kernel();

        $response = $this->request($app, '/en/about?x=1');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/about?x=1', $response->headers->get('Location'));

        self::assertSame('/', $this->request($app, '/en')->headers->get('Location'));
    }

    public function testPrefixMustBeAWholeSegment(): void
    {
        self::assertSame(404, $this->request($this->kernel(), '/italy')->getStatusCode());
    }

    public function testTrailingSlashVariantHasTheSameCanonicalUrl(): void
    {
        $app = $this->kernel();
        $canonical = '<link rel="canonical" href="https://example.test/it">';

        self::assertStringContainsString($canonical, self::body($this->request($app, '/it')));
        self::assertStringContainsString($canonical, self::body($this->request($app, '/it/')));
    }

    public function testErrorPagesAreTranslated(): void
    {
        $response = $this->request($this->kernel(), '/it/nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Pagina non trovata.', self::body($response));
    }

    public function testSwitcherLinksTheSamePageInEveryLanguage(): void
    {
        $html = self::body($this->request($this->kernel(), '/it/about'));

        self::assertStringContainsString('href="/about" data-available="yes">en</a>', $html);
        self::assertStringContainsString('class="lang active" href="/it/about"', $html);
    }

    public function testDefaultLanguageIsConfigurable(): void
    {
        // An Italian-first site that added English later: Italian has no prefix, English gets /en/.
        $app = $this->kernel(overrides: ['language' => 'it']);

        self::assertStringContainsString('<h1>Benvenuto</h1>', self::body($this->request($app, '/')));
        self::assertStringContainsString('<h1>Welcome</h1>', self::body($this->request($app, '/en')));
        self::assertSame('/about', $this->request($app, '/it/about')->headers->get('Location'));
    }
}
