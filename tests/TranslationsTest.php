<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Symfony\Component\HttpFoundation\Request;

final class TranslationsTest extends KernelTestCase
{
    public function testTranslatesIntoTheCurrentLanguage(): void
    {
        $app = $this->kernel();
        $app->handle(Request::create('/it'));

        self::assertSame('Benvenuto', $app->t('Welcome'));
        self::assertSame('Welcome', $app->t('Welcome', [], 'en'));
    }

    public function testIcuPluralsAndPlaceholders(): void
    {
        $app = $this->kernel();

        self::assertSame('1 post', $app->t('{count} posts', ['count' => 1], 'en'));
        self::assertSame('3 posts', $app->t('{count} posts', ['count' => 3], 'en'));
        self::assertSame('1 articolo', $app->t('{count} posts', ['count' => 1], 'it'));
        self::assertSame('3 articoli', $app->t('{count} posts', ['count' => 3], 'it'));
        self::assertSame('Ciao Ada', $app->t('Hello {name}', ['name' => 'Ada'], 'it'));
    }

    public function testMissingTranslationShowsTheSourceTextWithPlaceholdersFilled(): void
    {
        self::assertSame('Not translated yet', $this->kernel()->t('Not translated {x}', ['x' => 'yet'], 'it'));
    }

    public function testNeverFallsBackToAnotherLanguage(): void
    {
        // Italian default, English templates: a text missing from en.php must stay English.
        $app = $this->kernel(overrides: ['language' => 'it']);

        self::assertSame('Hello Ada', $app->t('Hello {name}', ['name' => 'Ada'], 'en'));
        self::assertSame('Ciao Ada', $app->t('Hello {name}', ['name' => 'Ada'], 'it'));
    }

    public function testTwigFilterAndFunction(): void
    {
        $app = $this->kernel();
        $app->handle(Request::create('/it'));

        self::assertSame('3 articoli|Benvenuto', $app->twig->createTemplate("{{ '{count} posts'|t({count: 3}) }}|{{ t('Welcome') }}")->render());
    }

    public function testProductionCompilesCatalogues(): void
    {
        $cache = $this->tempDir('cache');
        $app = $this->kernel(debug: false, overrides: ['cache_dir' => $cache]);

        self::assertSame(2, $app->translations->warmup());
        self::assertNotEmpty(glob($cache . '/translations/catalogue.it.*.php'));
        self::assertSame('Benvenuto', $app->t('Welcome', [], 'it'));
    }
}
