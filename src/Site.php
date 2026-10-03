<?php

declare(strict_types=1);

namespace Starlite;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * The site: name, public URL, languages, and the language of the current request.
 * Available in Twig as `site` (site.name, site.language, site.locale, …).
 *
 * The default language has no URL prefix; every other language is prefixed with its code:
 *
 *   default 'en', languages en + it:   /blog (English)   /it/blog (Italian)
 *   default 'it', languages it + en:   /blog (Italian)   /en/blog (English)
 */
final class Site extends AbstractExtension implements GlobalsInterface
{
    private string $language;

    /** Current path without the language prefix, e.g. "/blog" for /it/blog. */
    private string $path = '/';

    /**
     * @param string                                             $baseUrl   public URL (APP_URL), no trailing slash
     * @param array<string, array{name: string, locale: string}> $languages code => name and Open Graph locale
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $name,
        public readonly string $description = '',
        public readonly ?string $image = null,
        public readonly ?string $author = null,
        public readonly string $defaultLanguage = 'en',
        public readonly array $languages = ['en' => ['name' => 'English', 'locale' => 'en_US']],
    ) {
        if (!isset($languages[$defaultLanguage])) {
            throw new \InvalidArgumentException("The default language \"{$defaultLanguage}\" must be listed in languages.");
        }
        foreach (array_keys($languages) as $code) {
            if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code)) {
                throw new \InvalidArgumentException("Language code \"{$code}\" must look like \"en\" or \"pt-br\".");
            }
        }
        $this->language = $defaultLanguage;
    }

    // --- Current request -----------------------------------------------------

    /** Current language code, e.g. "it". */
    public function language(): string
    {
        return $this->language;
    }

    /** Current Open Graph / ICU locale, e.g. "it_IT". */
    public function locale(): string
    {
        return $this->languages[$this->language]['locale'];
    }

    /** Called by the kernel for each request. */
    public function enter(string $language, string $path): void
    {
        if (!isset($this->languages[$language])) {
            throw new \InvalidArgumentException("Unknown language \"{$language}\".");
        }
        $this->language = $language;
        $this->path = $path;
    }

    /**
     * Splits a request path into its language and the path to route.
     *
     * @return array{string, string, bool} language, path without prefix, whether to redirect
     *                                     (the default language was given as a prefix: /en/blog → /blog)
     */
    public function resolve(string $path): array
    {
        $segment = explode('/', ltrim($path, '/'), 2)[0];
        if ($segment === '' || !isset($this->languages[$segment])) {
            return [$this->defaultLanguage, $path, false];
        }
        $rest = '/' . (explode('/', ltrim($path, '/'), 2)[1] ?? '');

        return [$segment, $rest, $segment === $this->defaultLanguage];
    }

    // --- URLs ------------------------------------------------------------------

    /** URL prefix for a language: "" for the default language, "/it" for Italian. */
    public function prefix(?string $language = null): string
    {
        $language ??= $this->language;

        return $language === $this->defaultLanguage ? '' : '/' . $language;
    }

    /** A path in a language: localize('/blog', 'it') → "/it/blog", localize('/', 'it') → "/it". */
    public function localize(string $path, ?string $language = null): string
    {
        $prefix = $this->prefix($language);

        return $prefix !== '' && $path === '/' ? $prefix : $prefix . $path;
    }

    /** Absolute URL from APP_URL; absolute http(s) URLs are returned unchanged. */
    public function url(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return $pathOrUrl;
        }

        return $this->baseUrl . '/' . ltrim($pathOrUrl, '/');
    }

    // --- Language switcher -----------------------------------------------------

    /**
     * The current page in every language, for a language switcher.
     *
     * @return list<array{code: string, name: string, url: string, active: bool}>
     */
    public function switcher(): array
    {
        $links = [];
        foreach ($this->languages as $code => $language) {
            $links[] = [
                'code' => $code,
                'name' => $language['name'],
                'url' => $this->localize($this->path, $code),
                'active' => $code === $this->language,
            ];
        }

        return $links;
    }

    // --- Twig -------------------------------------------------------------------

    public function getGlobals(): array
    {
        return ['site' => $this];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('language_switcher', $this->switcher(...))];
    }
}
