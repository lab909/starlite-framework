<?php

declare(strict_types=1);

namespace Starlite\Seo;

use Spatie\SchemaOrg\Type;
use Starlite\Site;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Per-page SEO metadata: <title>, description, canonical URL, Open Graph, Twitter card and JSON-LD.
 *
 * Set it from a controller ($this->app->seo->title('About')) or a template's `seo` block
 * ({% do seo.title('About').description('…') %}); the layout prints it once with {{ seo_tags() }}.
 *
 * All URLs are built from APP_URL, never from the request's Host header: pages are publicly
 * cacheable, so a forged Host must not end up in a cached canonical or og:url.
 */
final class Seo extends AbstractExtension implements GlobalsInterface
{
    private ?string $title;
    private ?string $description;
    private string $canonical;
    private ?string $image;
    private string $type;
    private bool $noindex;
    /** @var array{published?: string, modified?: string, tags?: list<string>} */
    private array $article;
    /** @var list<Type> */
    private array $schemas;

    public function __construct(public readonly Site $site)
    {
        $this->reset('/');
    }

    /** Clears the page metadata; the kernel calls this at the start of each request. */
    public function reset(string $path): void
    {
        $this->title = null;
        $this->description = null;
        $this->canonical = $this->url($path);
        $this->image = $this->site->image ? $this->url($this->site->image) : null;
        $this->type = 'website';
        $this->noindex = false;
        $this->article = [];
        $this->schemas = [];
    }

    // --- Setters (fluent, usable from Twig) ----------------------------------

    /** Page title; the <title> becomes "Title · Site name". Null means just the site name. */
    public function title(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** A path ("/blog") or an absolute URL. Defaults to the current path, without query string. */
    public function canonical(string $pathOrUrl): self
    {
        $this->canonical = $this->url($pathOrUrl);

        return $this;
    }

    /** Share image: a path in public/ ("/images/post.jpg") or an absolute URL. */
    public function image(?string $pathOrUrl): self
    {
        $this->image = $pathOrUrl !== null && $pathOrUrl !== '' ? $this->url($pathOrUrl) : null;

        return $this;
    }

    /** Open Graph type: "website" (default) or "article". */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** Keeps the page out of search engines (error pages, drafts, private pages). */
    public function noindex(bool $noindex = true): self
    {
        $this->noindex = $noindex;

        return $this;
    }

    /**
     * Marks the page as an article (og:type=article plus article:* tags).
     *
     * @param list<string> $tags
     */
    public function article(string $published, ?string $modified = null, array $tags = []): self
    {
        $this->type = 'article';
        $this->article = array_filter(['published' => $published, 'modified' => $modified, 'tags' => $tags]);

        return $this;
    }

    /** Adds a JSON-LD block, built with spatie/schema-org (e.g. Schema::blogPosting()->headline(…)). */
    public function schema(Type $schema): self
    {
        $this->schemas[] = $schema;

        return $this;
    }

    // --- Output ---------------------------------------------------------------

    /** Absolute URL for a path (from APP_URL); absolute http(s) URLs are returned unchanged. */
    public function url(string $pathOrUrl): string
    {
        return $this->site->url($pathOrUrl);
    }

    public function canonicalUrl(): string
    {
        return $this->canonical;
    }

    public function imageUrl(): ?string
    {
        return $this->image;
    }

    public function pageTitle(): string
    {
        return $this->title !== null && $this->title !== '' ? $this->title : $this->site->name;
    }

    public function documentTitle(): string
    {
        return $this->title !== null && $this->title !== '' ? $this->title . ' · ' . $this->site->name : $this->site->name;
    }

    public function render(): string
    {
        $description = $this->description ?? $this->site->description;

        $tags = ['<title>' . self::e($this->documentTitle()) . '</title>'];
        $tags[] = self::meta('name', 'description', $description);
        $tags[] = '<link rel="canonical" href="' . self::e($this->canonical) . '">';
        if ($this->noindex) {
            $tags[] = self::meta('name', 'robots', 'noindex');
        }

        $tags[] = self::meta('property', 'og:site_name', $this->site->name);
        $tags[] = self::meta('property', 'og:type', $this->type);
        $tags[] = self::meta('property', 'og:title', $this->pageTitle());
        $tags[] = self::meta('property', 'og:description', $description);
        $tags[] = self::meta('property', 'og:url', $this->canonical);
        $tags[] = self::meta('property', 'og:locale', $this->site->locale());
        if ($this->image !== null) {
            $tags[] = self::meta('property', 'og:image', $this->image);
        }
        if (isset($this->article['published'])) {
            $tags[] = self::meta('property', 'article:published_time', $this->article['published']);
        }
        if (isset($this->article['modified'])) {
            $tags[] = self::meta('property', 'article:modified_time', $this->article['modified']);
        }
        foreach ($this->article['tags'] ?? [] as $tag) {
            $tags[] = self::meta('property', 'article:tag', $tag);
        }

        $tags[] = self::meta('name', 'twitter:card', $this->image !== null ? 'summary_large_image' : 'summary');
        $tags[] = self::meta('name', 'twitter:title', $this->pageTitle());
        $tags[] = self::meta('name', 'twitter:description', $description);
        if ($this->image !== null) {
            $tags[] = self::meta('name', 'twitter:image', $this->image);
        }

        foreach ($this->schemas as $schema) {
            // JSON_HEX_TAG turns "<" into <, so a "</script>" in any value cannot end the tag early.
            $json = json_encode(
                $schema->toArray(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $tags[] = '<script type="application/ld+json">' . $json . '</script>';
        }

        return implode("\n", $tags);
    }

    // --- Twig -----------------------------------------------------------------

    public function getGlobals(): array
    {
        return ['seo' => $this];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('seo_tags', $this->render(...), ['is_safe' => ['html']]),
            new TwigFunction('absolute_url', $this->url(...)),
        ];
    }

    private static function meta(string $attribute, string $name, string $content): string
    {
        return '<meta ' . $attribute . '="' . $name . '" content="' . self::e($content) . '">';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
