<?php

declare(strict_types=1);

namespace Starlite\Blog;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Turns a post folder's index.md (YAML front matter + Markdown) into a post array.
 *
 *   ---
 *   title: Hello world        (required)
 *   date: 2026-10-01          (required for published posts; optional for drafts and translations)
 *   updated: 2026-10-05       (optional, last significant change)
 *   image: cover.jpg          (optional share image: a file in the post folder, a /path in public/ or an https URL)
 *   summary: Optional teaser  (defaults to the first paragraph)
 *   tags: [php, datastar]
 *   ---
 *
 * The slug is the post's folder name and drafts are the posts in content/blog/drafts/ (see Blog).
 * index.md is the default language; index.<code>.md a translation, whose omitted date, updated,
 * image and tags are inherited from index.md.
 * Relative links and images (`![Cover](cover.jpg)`) point at files in the post folder: they are
 * rewritten to the post's public asset URL, and a missing file is an error.
 *
 * This only runs when the blog cache is built, never on a cached production request.
 *
 * @phpstan-type Post array{slug: string, language: string, title: string, date: string, updated: ?string, image: ?string, summary: string, tags: list<string>, draft: bool, reading_minutes: int, html: string, source: string, assets: list<string>}
 */
final class MarkdownParser
{
    private readonly MarkdownConverter $converter;

    /** Post being converted, used by the link rewriter: [folder on disk, public asset URL, source]. */
    private ?array $current = null;

    public function __construct()
    {
        $environment = new Environment([
            // Raw HTML in Markdown is escaped and javascript:/data: links are dropped,
            // so a content file cannot inject scripts into the page.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'heading_permalink' => [
                'id_prefix' => '',
                'fragment_prefix' => '',
                'symbol' => '#',
                'insert' => 'after',
                'html_class' => 'heading-permalink',
                'aria_hidden' => true,
            ],
            'external_link' => [
                'open_in_new_window' => true,
                'nofollow' => '',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new FrontMatterExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new ExternalLinkExtension());
        $environment->addEventListener(DocumentParsedEvent::class, $this->rewriteRelativeUrls(...));

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * @param string $path     the post's index.md
     * @param string $source   path shown in error messages, e.g. "2026/09/hello-starlite/index.md"
     * @param string    $assetUrl public URL of the post folder's files, e.g. "/media/blog/hello-starlite"
     * @param Post|null $original the default-language version, for a translation: date, updated,
     *                            image and tags it omits are inherited from there
     *
     * @return Post
     */
    public function parseFile(string $path, string $slug, string $language, bool $draft, string $source, string $assetUrl, ?array $original = null): array
    {
        $markdown = file_get_contents($path);
        if ($markdown === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        $this->current = [dirname($path), $assetUrl, $source];
        try {
            $result = $this->converter->convert($markdown);
        } finally {
            $this->current = null;
        }

        $meta = $result instanceof RenderedContentWithFrontMatter ? $result->getFrontMatter() : null;
        if (!is_array($meta)) {
            throw new \RuntimeException("{$source}: missing YAML front matter.");
        }
        foreach (['slug' => 'rename the post folder instead', 'draft' => 'move the post folder to content/blog/drafts/ instead'] as $key => $hint) {
            if (array_key_exists($key, $meta)) {
                throw new \RuntimeException("{$source}: \"{$key}\" is no longer a front matter field: {$hint}.");
            }
        }

        $title = $meta['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new \RuntimeException("{$source}: front matter needs a \"title\".");
        }

        $html = $result->getContent();
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text)); // str_word_count() splits accented words

        return [
            'slug' => $slug,
            'language' => $language,
            'title' => trim($title),
            'date' => match (true) {
                isset($meta['date']) => self::date($meta['date'], $source),
                $original !== null => $original['date'],
                $draft => gmdate('Y-m-d'), // a draft without a date sorts as if published today
                default => self::date(null, $source),
            },
            'updated' => isset($meta['updated']) ? self::date($meta['updated'], $source) : $original['updated'] ?? null,
            'image' => array_key_exists('image', $meta)
                ? self::image($meta['image'], dirname($path), $assetUrl, $source)
                : $original['image'] ?? null,
            'summary' => is_string($meta['summary'] ?? null) ? trim($meta['summary']) : self::firstParagraph($html),
            'tags' => array_key_exists('tags', $meta)
                ? array_values(array_unique(array_map(static fn ($tag) => strtolower(trim((string) $tag)), (array) $meta['tags'])))
                : $original['tags'] ?? [],
            'draft' => $draft,
            'reading_minutes' => max(1, (int) ceil($words / 220)),
            'html' => $html,
            'source' => $source,
            'assets' => [],
        ];
    }

    /** `![x](cover.jpg)` / `[pdf](files/report.pdf)` → the post's asset URL; the file must exist. */
    private function rewriteRelativeUrls(DocumentParsedEvent $event): void
    {
        if ($this->current === null) {
            return;
        }
        [$dir, $assetUrl, $source] = $this->current;
        foreach ($event->getDocument()->iterator() as $node) {
            if (!$node instanceof AbstractWebResource) {
                continue;
            }
            $url = $node->getUrl();
            try {
                $path = self::relativePath($url);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("{$source}: {$e->getMessage()}");
            }
            if ($path === null) {
                continue;
            }
            if (!is_file($dir . '/' . $path)) {
                throw new \RuntimeException("{$source}: \"{$url}\" not found in the post folder.");
            }
            if (!isset(Blog::ASSET_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
                throw new \RuntimeException("{$source}: \"{$url}\" is not a publishable file type (" . implode(', ', array_keys(Blog::ASSET_TYPES)) . ').');
            }
            $node->setUrl($assetUrl . '/' . $path);
        }
    }

    /** "cover.jpg" or "./img/a.png" → "img/a.png"; null for absolute paths, URLs, anchors and queries. */
    private static function relativePath(string $url): ?string
    {
        if ($url === '' || preg_match('#^([a-z][a-z0-9+.-]*:|/|\#|\?)#i', $url)) {
            return null;
        }
        $path = rawurldecode((string) preg_replace('#^(\./)+#', '', explode('#', explode('?', $url)[0])[0]));
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new \InvalidArgumentException("\"{$url}\": links to post files must stay inside the post folder (no \"..\").");
        }

        return $path !== '' ? $path : null;
    }

    /** YAML turns an unquoted `2026-10-01` into a UTC timestamp; a quoted one stays a string. */
    private static function date(mixed $value, string $source): string
    {
        if (is_int($value)) {
            return gmdate('Y-m-d', $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) !== false) {
            return $date->format('Y-m-d');
        }

        throw new \RuntimeException("{$source}: front matter needs a \"date\" in YYYY-MM-DD format.");
    }

    private static function image(mixed $value, string $dir, string $assetUrl, string $source): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \RuntimeException("{$source}: \"image\" must be a file name, a /path or an https:// URL.");
        }
        if (str_starts_with($value, '/') || preg_match('#^https://\S+$#i', $value)) {
            return $value;
        }
        try {
            $path = self::relativePath($value);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("{$source}: {$e->getMessage()}");
        }
        if ($path === null || !is_file($dir . '/' . $path)) {
            throw new \RuntimeException("{$source}: image \"{$value}\" not found in the post folder.");
        }
        if (!isset(Blog::ASSET_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            throw new \RuntimeException("{$source}: image \"{$value}\" is not a publishable file type.");
        }

        return $assetUrl . '/' . $path;
    }

    private static function firstParagraph(string $html): string
    {
        // The first paragraph with text: a post may open with an image-only paragraph.
        preg_match_all('#<p>(.*?)</p>#s', $html, $matches);
        foreach ($matches[1] as $paragraph) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text !== '') {
                return mb_strlen($text) > 200 ? rtrim(mb_substr($text, 0, 199)) . '…' : $text;
            }
        }

        return '';
    }
}
