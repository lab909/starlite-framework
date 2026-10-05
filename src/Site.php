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
 * Name, description, share image and author are each one value for every language, or a map
 * language => value (config/app.php `site`); a language missing from a map uses the default one's.
 * site.name and $site->name() are in the current language.
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

    /** @var array<string, string>|null language => path where the current page exists; null = every language */
    private ?array $alternates = null;

    /** @var array<string, array<string, ?string>> name|description|image|author => language => value, the default language always set */
    private readonly array $texts;

    /** @var array<string, string> language => path to offer instead, where the page doesn't exist */
    private array $fallbacks = [];

    /**
     * @param string                                             $baseUrl     public URL (APP_URL), no trailing slash
     * @param string|array<string, string>                       $name        one for every language, or language => name
     * @param string|array<string, string>                       $description default meta description and feed subtitle
     * @param string|array<string, ?string>|null                 $image       default share image (/path or URL)
     * @param string|array<string, ?string>|null                 $author      posts and the feed; null: the site name
     * @param array<string, array{name: string, locale: string}> $languages   code => name and Open Graph locale
     */
    public function __construct(
        public readonly string $baseUrl,
        string|array $name,
        string|array $description = '',
        string|array|null $image = null,
        string|array|null $author = null,
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
        $this->texts = [
            'name' => $this->perLanguage('name', $name),
            'description' => $this->perLanguage('description', $description),
            'image' => $this->perLanguage('image', $image),
            'author' => $this->perLanguage('author', $author),
        ];
    }

    /** The site's name, in the current language (or the given one). */
    public function name(?string $language = null): string
    {
        return (string) $this->text('name', $language);
    }

    /** The default meta description and feed subtitle, in the current language (or the given one). */
    public function description(?string $language = null): string
    {
        return (string) $this->text('description', $language);
    }

    /** The default share image (/path or URL), or null, for the current language (or the given one). */
    public function image(?string $language = null): ?string
    {
        return $this->text('image', $language);
    }

    /** The author of posts and the feed, or null (then the site name), for the current language (or the given one). */
    public function author(?string $language = null): ?string
    {
        return $this->text('author', $language);
    }

    /** A language's own value (null included: "none in this language"), else the default language's. */
    private function text(string $key, ?string $language): ?string
    {
        $values = $this->texts[$key];
        $language ??= $this->language;

        return array_key_exists($language, $values) ? $values[$language] : $values[$this->defaultLanguage];
    }

    /**
     * One value for every language, or a map that must name the default language and only configured ones.
     *
     * @param string|array<mixed>|null $value
     *
     * @return array<string, ?string>
     */
    private function perLanguage(string $key, string|array|null $value): array
    {
        if (!is_array($value)) {
            return [$this->defaultLanguage => $value];
        }
        $unknown = array_diff(array_keys($value), array_keys($this->languages));
        if ($unknown !== []) {
            throw new \InvalidArgumentException("config/app.php site.{$key}: language \"" . implode('", "', $unknown) . '" is not configured in languages.');
        }
        if (!array_key_exists($this->defaultLanguage, $value)) {
            throw new \InvalidArgumentException("config/app.php site.{$key}: needs a value for the default language ({$this->defaultLanguage}), used where another language has none.");
        }
        foreach ($value as $language => $text) {
            if ($text !== null && !is_string($text)) {
                throw new \InvalidArgumentException("config/app.php site.{$key}.{$language} must be text.");
            }
        }
        return $value;
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
        $this->alternates = null;
        $this->fallbacks = [];
    }

    /**
     * Declares that the current page exists only in some languages (e.g. a post with one translation).
     * The switcher sends the other languages to their fallback path (or their home page), and hreflang
     * links list only the existing versions.
     *
     * @param array<string, string> $alternates language => path of this page in that language
     * @param array<string, string> $fallbacks  language => path to offer where it doesn't exist
     */
    public function setAlternates(array $alternates, array $fallbacks = []): void
    {
        $this->alternates = $alternates;
        $this->fallbacks = $fallbacks;
    }

    /** @return array<string, string> language => path of the current page, for every language it exists in */
    public function alternates(): array
    {
        if ($this->alternates !== null) {
            return $this->alternates;
        }
        $paths = [];
        foreach (array_keys($this->languages) as $code) {
            $paths[$code] = $this->localize($this->path, $code);
        }

        return $paths;
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
     * `available` is false where the page doesn't exist in that language; `url` then points to the
     * fallback (e.g. that language's blog) or its home page.
     *
     * @return list<array{code: string, name: string, url: string, active: bool, available: bool}>
     */
    public function switcher(): array
    {
        $alternates = $this->alternates();
        $links = [];
        foreach ($this->languages as $code => $language) {
            $links[] = [
                'code' => $code,
                'name' => $language['name'],
                'url' => $alternates[$code] ?? $this->fallbacks[$code] ?? $this->localize('/', $code),
                'active' => $code === $this->language,
                'available' => isset($alternates[$code]),
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
