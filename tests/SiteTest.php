<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\TestCase;
use Starlite\Site;

final class SiteTest extends TestCase
{
    private function site(string $default = 'en'): Site
    {
        return new Site('https://example.test', 'S', defaultLanguage: $default, languages: [
            'en' => ['name' => 'English', 'locale' => 'en_US'],
            'it' => ['name' => 'Italiano', 'locale' => 'it_IT'],
        ]);
    }

    public function testResolve(): void
    {
        $site = $this->site();

        self::assertSame(['en', '/blog', false], $site->resolve('/blog'));
        self::assertSame(['it', '/blog', false], $site->resolve('/it/blog'));
        self::assertSame(['it', '/', false], $site->resolve('/it'));
        self::assertSame(['en', '/blog', true], $site->resolve('/en/blog'));
        self::assertSame(['en', '/italy', false], $site->resolve('/italy'));
    }

    public function testLocalizeAndUrl(): void
    {
        $site = $this->site();

        self::assertSame('/blog', $site->localize('/blog', 'en'));
        self::assertSame('/it/blog', $site->localize('/blog', 'it'));
        self::assertSame('/it', $site->localize('/', 'it'));
        self::assertSame('https://example.test/it', $site->url('/it'));
        self::assertSame('https://cdn.example/x.png', $site->url('https://cdn.example/x.png'));
    }

    public function testSwitcherFollowsAlternatesAndFallbacks(): void
    {
        $site = $this->site();
        $site->enter('en', '/blog/beta');
        self::assertSame(['/blog/beta', '/it/blog/beta'], array_column($site->switcher(), 'url'));

        $site->setAlternates(['en' => '/blog/beta'], ['it' => '/it/blog']);
        $links = $site->switcher();
        self::assertSame(['/blog/beta', '/it/blog'], array_column($links, 'url'));
        self::assertSame([true, false], array_column($links, 'available'));
        self::assertSame(['en' => '/blog/beta'], $site->alternates());

        $site->enter('it', '/');
        self::assertSame(['/', '/it'], array_column($site->switcher(), 'url'), 'entering a new page resets alternates');
    }

    public function testValidatesLanguageConfiguration(): void
    {
        $this->expectExceptionMessage('The default language "fr" must be listed in languages.');
        $this->site('fr');
    }

    public function testRejectsMalformedLanguageCodes(): void
    {
        $this->expectExceptionMessage('Language code "EN" must look like');
        new Site('https://example.test', 'S', defaultLanguage: 'EN', languages: ['EN' => ['name' => 'E', 'locale' => 'en_US']]);
    }
}
