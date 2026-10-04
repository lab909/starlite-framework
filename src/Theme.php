<?php

declare(strict_types=1);

namespace Starlite;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Light / dark / system theme without a flash of the wrong colours.
 *
 * `{{ theme_script() }}` goes at the top of `<head>`: a tiny inline script that sets
 * `<html data-theme="light|dark">` before the page is painted, from the visitor's saved choice or
 * else their system setting. In the browser, `theme()` from the 'starlite' module keeps it in step
 * with the `_theme` signal ('light', 'dark' or 'system'), saves the choice and follows system changes.
 *
 * The script is the same on every page and for every visitor (pages stay cacheable), so a Content
 * Security Policy allows it by its hash ({@see Csp} adds it).
 */
final class Theme extends AbstractExtension
{
    /** localStorage key and signal; resources/js/starlite.js uses the same two. */
    public const STORAGE_KEY = 'starlite-theme';
    public const SIGNAL = '_theme';

    // The saved value is persist()'s format: {"_theme": "dark"}. Anything else means "system".
    public const SCRIPT = '(function(){var t;try{t=JSON.parse(localStorage.getItem("' . self::STORAGE_KEY . '")||"{}").' . self::SIGNAL . '}catch(e){}'
        . 'document.documentElement.dataset.theme=t==="light"||t==="dark"?t:matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"})()';

    public function getFunctions(): array
    {
        return [new TwigFunction('theme_script', $this->tag(...), ['is_safe' => ['html']])];
    }

    public function tag(): string
    {
        return '<script>' . self::SCRIPT . '</script>';
    }

    /** CSP source for the script, e.g. "'sha256-…'" in `script-src`. */
    public static function hash(): string
    {
        return Csp::hash(self::SCRIPT);
    }
}
