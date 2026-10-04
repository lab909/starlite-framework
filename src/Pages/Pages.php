<?php

declare(strict_types=1);

namespace Starlite\Pages;

use Starlite\Blog\Blog;
use Starlite\Blog\MarkdownParser;
use Starlite\Cache;
use Starlite\Query;
use Starlite\Site;

/**
 * Content pages: one-offs like About, Privacy or Contact (Craft's "singles"), nested like a structure.
 * Each page is a folder in content/pages/, its path is the URL:
 *
 *   content/pages/privacy/index.md            /privacy
 *   content/pages/privacy/index.it.md         /it/privacy
 *   content/pages/about/index.md              /about
 *   content/pages/about/credits/index.md      /about/credits (a child of about)
 *   content/pages/about/team.jpg              /media/pages/about/team.jpg
 *
 *   ---
 *   title: About us             (required)
 *   summary: Who we are         (optional: meta description; defaults to the first paragraph)
 *   image: team.jpg             (optional share image: a file in the folder, a /path or an https URL)
 *   template: pages/about.twig  (optional: rendered with its own template instead of page.twig)
 *   order: 2                    (optional: for menus, `pages().orderBy('order, title')`)
 *   updated: 2026-10-01         (optional: last significant change, for the sitemap)
 *   data: {form_title: Write us} (optional: anything else the template needs, translatable)
 *   ---
 *
 * A translation (index.<code>.md) keeps the image, template, order, updated and data it omits.
 * Query pages with `pages()` (see Starlite\Query); a page without a version in a language doesn't
 * exist there. Compiled into var/cache/pages.php without APP_DEBUG, like the blog.
 *
 * @phpstan-type Page array{slug: string, path: string, parent: string, depth: int, language: string, title: string, summary: string, image: ?string, template: ?string, order: ?int, updated: ?string, data: array<mixed>, html: string, source: string, assets: list<string>}
 */
final class Pages
{
    /** Public URL prefix of page files; `deploy` copies them to public/media/pages/<path>/. */
    public const ASSET_URL = '/media/pages';

    /** A page path, for the route requirement: segments of lowercase letters, digits and dashes. */
    public const PATH = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*';

    public const QUERY_FIELDS = ['slug', 'path', 'parent', 'depth', 'language', 'title', 'summary', 'image', 'template', 'order', 'updated'];
    public const SEARCH_FIELDS = ['title', 'summary'];

    private const FRONT_MATTER = ['title', 'summary', 'image', 'template', 'order', 'updated', 'data'];

    /** @var array<string, array<string, Page>>|null language => path => page */
    private ?array $pages = null;

    public function __construct(
        private readonly string $contentDir,
        private readonly string $cacheFile,
        private readonly bool $debug,
        private readonly Site $site,
    ) {
    }

    /**
     * A query over the pages, sorted by path (each page before its children).
     *
     * @return Query<Page>
     */
    public function query(): Query
    {
        return new Query('pages', $this->items(...), self::QUERY_FIELDS, self::SEARCH_FIELDS, $this->site);
    }

    /** @return array<string, Page> path => page in a language */
    public function items(?string $language = null): array
    {
        return $this->compiled()[$language ?? $this->site->language()] ?? [];
    }

    /** @return list<string> the languages a page exists in */
    public function translations(string $path): array
    {
        return array_values(array_filter(
            array_keys($this->site->languages),
            fn (string $language) => isset($this->items($language)[$path]),
        ));
    }

    /** Absolute path of a page's published file ("about/team.jpg"), or null if there's no such page or file. */
    public function asset(string $file): ?string
    {
        [$path, $name] = [dirname($file), basename($file)];
        foreach (array_keys($this->site->languages) as $language) {
            $page = $this->items($language)[$path] ?? null;
            if ($page !== null) {
                return in_array($name, $page['assets'], true) ? "{$this->contentDir}/{$path}/{$name}" : null;
            }
        }

        return null;
    }

    /** Copies every page's files to public/media/pages/<path>/. Returns the number of files. */
    public function publishAssets(string $publicDir): int
    {
        $target = $publicDir . self::ASSET_URL;
        Cache::clear($target);
        $count = 0;
        $done = [];
        foreach ($this->compiled() as $pages) {
            foreach ($pages as $path => $page) {
                if (isset($done[$path])) {
                    continue;
                }
                $done[$path] = true;
                foreach ($page['assets'] as $file) {
                    if (!is_dir("{$target}/{$path}") && !mkdir("{$target}/{$path}", 0775, true) && !is_dir("{$target}/{$path}")) {
                        throw new \RuntimeException("Cannot create {$target}/{$path}.");
                    }
                    copy("{$this->contentDir}/{$path}/{$file}", "{$target}/{$path}/{$file}");
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * Parses every page and writes the cache.
     *
     * @return array{int, int} pages, language versions
     */
    public function warmup(): array
    {
        $this->pages = null;
        $pages = $this->compile();
        Cache::writeData($this->cacheFile, $pages);
        $this->pages = $pages;

        $paths = [];
        foreach ($pages as $versions) {
            $paths += $versions;
        }

        return [count($paths), array_sum(array_map('count', $pages))];
    }

    /** @return array<string, array<string, Page>> */
    private function compiled(): array
    {
        return $this->pages ??= $this->debug ? $this->compile() : Cache::remember($this->cacheFile, $this->compile(...));
    }

    /** @return array<string, array<string, Page>> language => path => page, sorted by path */
    private function compile(): array
    {
        $parser = new MarkdownParser();
        $pages = array_fill_keys(array_keys($this->site->languages), []);
        foreach ($this->folders() as $path => $files) {
            $assets = $this->assets("{$this->contentDir}/{$path}");
            $original = null;
            foreach ($files as $language => $file) {
                $source = "pages/{$path}/{$file}";
                $page = $this->parse("{$this->contentDir}/{$path}/{$file}", $path, $language, $source, $parser, $original);
                $page['assets'] = $assets;
                $pages[$language][$path] = $page;
                $original ??= $language === $this->site->defaultLanguage ? $page : null;
            }
        }
        foreach ($pages as &$versions) {
            ksort($versions, SORT_STRING);
        }
        unset($versions);

        return $pages;
    }

    /**
     * Every page folder, "about/credits" => language => file, default language first. Every folder
     * under content/pages/ must be a page (have an index.md), so nothing is silently skipped.
     *
     * @return array<string, array<string, string>>
     */
    private function folders(): array
    {
        if (!is_dir($this->contentDir)) {
            return [];
        }
        $folders = [];
        $pending = [''];
        while ($pending !== []) {
            $relative = array_shift($pending);
            $dir = rtrim("{$this->contentDir}/{$relative}", '/');
            $files = [];
            foreach (scandir($dir) ?: [] as $name) {
                if (str_starts_with($name, '.')) {
                    continue;
                }
                $child = ltrim("{$relative}/{$name}", '/');
                if (is_dir("{$dir}/{$name}")) {
                    if (!preg_match('#^' . self::PATH . '$#', $child)) {
                        throw new \RuntimeException("pages/{$child}/: page folders use lowercase letters, digits and dashes (the folder names are the URL).");
                    }
                    $pending[] = $child;
                    continue;
                }
                if (!preg_match('/^index(?:\.([a-z]{2}(?:-[a-z]{2})?))?\.md$/', $name, $m)) {
                    continue; // images and other files: published if their type is allowed (see assets())
                }
                $code = $m[1] ?? '';
                if ($relative === '') {
                    throw new \RuntimeException("pages/{$name}: the home page is a route (config/routes.php), not a content page.");
                }
                if ($code === $this->site->defaultLanguage) {
                    throw new \RuntimeException("pages/{$child}: the default language ({$code}) is index.md, without a language code.");
                }
                $language = $code === '' ? $this->site->defaultLanguage : $code;
                if (!isset($this->site->languages[$language])) {
                    throw new \RuntimeException("pages/{$child}: language \"{$code}\" is not configured in config/app.php.");
                }
                $files[$language] = $name;
            }
            if ($relative === '') {
                continue;
            }
            if ($files === []) {
                throw new \RuntimeException("pages/{$relative}/: every folder in content/pages/ is a page and needs an index.md (images go next to it, not in a subfolder).");
            }
            $order = array_flip(array_keys($this->site->languages));
            $order[$this->site->defaultLanguage] = -1;
            uksort($files, static fn (string $a, string $b) => $order[$a] <=> $order[$b]);
            $folders[$relative] = $files;
        }

        return $folders;
    }

    /**
     * @param Page|null $original the default-language version, for a translation
     *
     * @return Page
     */
    private function parse(string $file, string $path, string $language, string $source, MarkdownParser $parser, ?array $original): array
    {
        [$meta, $html] = $parser->convertFile($file, self::ASSET_URL . '/' . $path, $source);
        if (!is_array($meta)) {
            throw new \RuntimeException("{$source}: missing YAML front matter (at least a title).");
        }
        $unknown = array_diff(array_keys($meta), self::FRONT_MATTER);
        if ($unknown !== []) {
            throw new \RuntimeException("{$source}: unknown front matter \"" . implode('", "', $unknown) . '" (' . implode(', ', self::FRONT_MATTER) . '; put anything else under "data").');
        }
        $title = $meta['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new \RuntimeException("{$source}: front matter needs a \"title\".");
        }
        $template = array_key_exists('template', $meta) ? $meta['template'] : $original['template'] ?? null;
        if ($template !== null && (!is_string($template) || !preg_match('#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.twig$#', $template))) {
            throw new \RuntimeException("{$source}: \"template\" must be a template path such as pages/contact.twig.");
        }
        $order = array_key_exists('order', $meta) ? $meta['order'] : $original['order'] ?? null;
        if ($order !== null && !is_int($order)) {
            throw new \RuntimeException("{$source}: \"order\" must be a whole number.");
        }
        $data = array_key_exists('data', $meta) ? $meta['data'] : $original['data'] ?? [];
        if (!is_array($data)) {
            throw new \RuntimeException("{$source}: \"data\" must be a mapping (key: value).");
        }
        $dir = dirname($file);
        $parent = dirname($path);

        return [
            'slug' => basename($path),
            'path' => $path,
            'parent' => $parent === '.' ? '' : $parent,
            'depth' => substr_count($path, '/') + 1,
            'language' => $language,
            'title' => trim($title),
            'summary' => is_string($meta['summary'] ?? null) ? trim($meta['summary']) : MarkdownParser::firstParagraph($html),
            'image' => array_key_exists('image', $meta)
                ? MarkdownParser::image($meta['image'], $dir, self::ASSET_URL . '/' . $path, $source)
                : $original['image'] ?? null,
            'template' => $template,
            'order' => $order,
            'updated' => isset($meta['updated']) ? MarkdownParser::date($meta['updated'], $source, 'updated') : $original['updated'] ?? null,
            'data' => $data,
            'html' => $html,
            'source' => $source,
            'assets' => [],
        ];
    }

    /** @return list<string> publishable files directly in a page folder (subfolders are child pages) */
    private function assets(string $dir): array
    {
        $assets = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (str_starts_with($name, '.') || !is_file("{$dir}/{$name}") || !isset(Blog::ASSET_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $name)) {
                throw new \RuntimeException("{$dir}/{$name}: file names may only use letters, digits, dots, dashes and underscores.");
            }
            $assets[] = $name;
        }
        sort($assets);

        return $assets;
    }
}
