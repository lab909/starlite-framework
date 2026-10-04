<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Theme;

final class ThemeTest extends FrameworkTestCase
{
    public function testTheScriptIsPrintedInTheHeadOfEveryPage(): void
    {
        $html = $this->body($this->request($this->kernel(), '/'));

        self::assertStringContainsString('<head>' . "\n" . '<script>' . Theme::SCRIPT . '</script>', $html);
    }

    public function testTheScriptReadsTheKeyAndSignalThatThemeSaves(): void
    {
        // resources/js/starlite.js: persist(['_theme'], { key: 'starlite-theme' }).
        $js = (string) file_get_contents(dirname(__DIR__) . '/resources/js/starlite.js');

        self::assertStringContainsString("persist(['" . Theme::SIGNAL . "'], { key: '" . Theme::STORAGE_KEY . "' })", $js);
        self::assertStringContainsString('localStorage.getItem("starlite-theme")', Theme::SCRIPT);
        self::assertStringContainsString('._theme}', Theme::SCRIPT);
    }

    public function testHashForAContentSecurityPolicy(): void
    {
        self::assertSame("'sha256-" . base64_encode(hash('sha256', Theme::SCRIPT, true)) . "'", Theme::hash());
        self::assertMatchesRegularExpression("#^'sha256-[A-Za-z0-9+/]{43}='$#", Theme::hash());
    }
}
