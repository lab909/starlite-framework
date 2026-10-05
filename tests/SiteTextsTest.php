<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Site;

final class SiteTextsTest extends FrameworkTestCase
{
    private const LANGUAGES = [
        'en' => ['name' => 'English', 'locale' => 'en_US'],
        'it' => ['name' => 'Italiano', 'locale' => 'it_IT'],
        'de' => ['name' => 'Deutsch', 'locale' => 'de_DE'],
    ];

    /** @param array<string, mixed> $texts */
    private static function site(array $texts): Site
    {
        return new Site('https://example.test', ...$texts, languages: self::LANGUAGES);
    }

    public function testOneValueForEveryLanguage(): void
    {
        $site = self::site(['name' => 'Starlite', 'description' => 'Fast']);

        self::assertSame(['Starlite', 'Starlite', 'Fast'], [$site->name(), $site->name('it'), $site->description('de')]);
        self::assertNull($site->image());
        self::assertNull($site->author('it'));
    }

    public function testAValuePerLanguageFollowsTheCurrentLanguage(): void
    {
        $site = self::site([
            'name' => 'Starlite',
            'description' => ['en' => 'Fast sites', 'it' => 'Siti veloci'],
            'image' => ['en' => '/og.en.png', 'it' => '/og.it.png'],
        ]);

        self::assertSame(['Fast sites', '/og.en.png'], [$site->description(), $site->image()]);
        $site->enter('it', '/');
        self::assertSame(['Siti veloci', '/og.it.png', 'Fast sites'], [$site->description(), $site->image(), $site->description('en')]);
        $site->enter('de', '/');
        self::assertSame(['Fast sites', '/og.en.png'], [$site->description(), $site->image()], 'no German value: the default language\'s');

        $none = self::site(['name' => 'S', 'image' => ['en' => '/og.png', 'it' => null]]);
        self::assertSame(['/og.png', null, '/og.png'], [$none->image('en'), $none->image('it'), $none->image('de')], 'an explicit null is "none in this language"');
    }

    public function testMapsAreChecked(): void
    {
        foreach ([
            [['name' => ['it' => 'Starlite']], 'site.name: needs a value for the default language (en)'],
            [['name' => 'S', 'description' => ['en' => 'x', 'fr' => 'y']], 'site.description: language "fr" is not configured'],
            [['name' => ['en' => ['nested']]], 'site.name.en must be text'],
        ] as [$texts, $message]) {
            try {
                self::site($texts);
                self::fail("Must be refused: {$message}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testPagesAndFeedsUseTheTextsOfTheirLanguage(): void
    {
        $app = $this->kernel(overrides: ['site' => [
            'name' => ['en' => 'Fixture', 'it' => 'Prova'],
            'description' => ['en' => 'Fixture site', 'it' => 'Sito di prova'],
            'image' => ['en' => '/og.en.png', 'it' => '/og.it.png'],
        ]]);

        $html = $this->body($this->request($app, '/it'));
        self::assertStringContainsString('<title>Prova</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Sito di prova">', $html);
        self::assertStringContainsString('<meta property="og:site_name" content="Prova">', $html);
        self::assertStringContainsString('<meta property="og:image" content="https://example.test/og.it.png">', $html);
        self::assertStringContainsString('<meta property="og:site_name" content="Fixture">', $this->body($this->request($app, '/')));

        $feed = $this->body($this->request($app, '/it/blog/feed.xml'));
        self::assertStringContainsString('<title>Prova</title><subtitle>Sito di prova</subtitle>', $feed);
        self::assertStringContainsString('<subtitle>Fixture site</subtitle>', $this->body($this->request($app, '/blog/feed.xml')));
    }
}
