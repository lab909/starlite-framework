<?php

declare(strict_types=1);

namespace Starlite\Blog;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Turns a Markdown file with YAML front matter into a post array.
 *
 *   ---
 *   title: Hello world        (required)
 *   date: 2026-10-01          (required)
 *   summary: Optional teaser  (defaults to the first paragraph)
 *   tags: [php, datastar]
 *   slug: custom-slug         (defaults to the file name without a leading date)
 *   draft: true               (drafts are only listed when APP_DEBUG=1)
 *   ---
 *
 * This only runs when the blog cache is built, never on a cached production request.
 *
 * @phpstan-type Post array{slug: string, title: string, date: string, summary: string, tags: list<string>, draft: bool, reading_minutes: int, html: string, source: string}
 */
final class MarkdownParser
{
    private readonly MarkdownConverter $converter;

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

        $this->converter = new MarkdownConverter($environment);
    }

    /** @return Post */
    public function parseFile(string $path): array
    {
        $markdown = file_get_contents($path);
        if ($markdown === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        return $this->parse($markdown, basename($path, '.md'), $path);
    }

    /** @return Post */
    public function parse(string $markdown, string $defaultSlug, string $source = '(string)'): array
    {
        $result = $this->converter->convert($markdown);
        $meta = $result instanceof RenderedContentWithFrontMatter ? $result->getFrontMatter() : null;
        if (!is_array($meta)) {
            throw new \RuntimeException("{$source}: missing YAML front matter.");
        }

        $title = $meta['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new \RuntimeException("{$source}: front matter needs a \"title\".");
        }

        $slug = (string) ($meta['slug'] ?? preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $defaultSlug));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \RuntimeException("{$source}: slug \"{$slug}\" must be lowercase letters, digits and dashes.");
        }

        $html = $result->getContent();
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $words = str_word_count($text);

        return [
            'slug' => $slug,
            'title' => trim($title),
            'date' => self::date($meta['date'] ?? null, $source),
            'summary' => is_string($meta['summary'] ?? null) ? trim($meta['summary']) : self::firstParagraph($html),
            'tags' => array_values(array_unique(array_map(
                static fn ($tag) => strtolower(trim((string) $tag)),
                (array) ($meta['tags'] ?? []),
            ))),
            'draft' => (bool) ($meta['draft'] ?? false),
            'reading_minutes' => max(1, (int) ceil($words / 220)),
            'html' => $html,
            'source' => $source,
        ];
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

    private static function firstParagraph(string $html): string
    {
        if (!preg_match('#<p>(.*?)</p>#s', $html, $match)) {
            return '';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > 200 ? rtrim(mb_substr($text, 0, 199)) . '…' : $text;
    }
}
