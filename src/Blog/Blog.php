<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Cache;
use Starlite\Query;
use Starlite\Site;

/**
 * File-based blog. Each post is a folder named after its slug, holding one Markdown file per
 * language and the files (images, downloads) they share:
 *
 *   content/blog/2026/09/hello-starlite/index.md     default language, filed by publication month
 *   content/blog/2026/09/hello-starlite/index.it.md  Italian version → /it/blog/hello-starlite
 *   content/blog/2026/09/hello-starlite/cover.jpg    served as /media/blog/hello-starlite/cover.jpg
 *   content/blog/drafts/next-post/index.md           draft: only visible with APP_DEBUG=1
 *
 * Query posts with `posts()` (Twig) or `$app->posts()` (PHP): see Starlite\Query. A post without
 * a version in a language doesn't exist there: not listed, not searchable, 404.
 *
 * Without APP_DEBUG the parsed posts (HTML included) are compiled once into var/cache/blog.php,
 * so a request only reads an Opcache-resident array. Run `bin/console deploy` (or cache:clear)
 * after publishing new content.
 *
 * @phpstan-import-type Post from MarkdownParser
 */
final class Blog
{
    /** Public URL prefix of post files; `deploy` copies them to public/media/blog/<slug>/. */
    public const ASSET_URL = '/media/blog';

    /** File types a post folder may publish, with their Content-Type. Anything else in the folder stays private. */
    public const ASSET_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    private const SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** @var array<string, array<string, Post>>|null language => slug => post, newest first */
    private ?array $posts = null;

    public function __construct(
        private readonly string $contentDir,
        private readonly string $cacheFile,
        private readonly bool $debug,
        private readonly Site $site,
        public readonly int $perPage = 20,
        /** @var (\Closure(string, array<string, string|int|float|bool>): ?string)|null checks a component (see MarkdownParser) */
        private readonly ?\Closure $componentCheck = null,
        /** Base URL of the files' public URLs: '' (this site) or a CDN, MEDIA_URL */
        private readonly string $mediaUrl = '',
    ) {
    }

    /** Keys of a post that queries can filter, sort and count by (`posts().where('draft', false)`). */
    public const QUERY_FIELDS = ['slug', 'uri', 'language', 'title', 'date', 'updated', 'image', 'summary', 'tags', 'draft', 'reading_minutes'];

    /** Keys `posts().search()` looks in. */
    public const SEARCH_FIELDS = ['title', 'summary', 'tags'];

    /**
     * A query over the posts, newest first: what `posts()` and `$app->posts()` return.
     *
     * @return Query<Post>
     */
    public function query(): Query
    {
        return new Query('posts', $this->items(...), self::QUERY_FIELDS, self::SEARCH_FIELDS, $this->site, $this->perPage);
    }

    /**
     * Every post in a language, newest first: the source of query().
     *
     * @return array<string, Post> slug => post
     */
    public function items(?string $language = null): array
    {
        return $this->compiled()[$language ?? $this->site->language()] ?? [];
    }

    /** A post's URL segment in a language (its `uri`: a translated slug, or the folder name), or null if it doesn't exist there. */
    public function uri(string $slug, string $language): ?string
    {
        return $this->items($language)[$slug]['uri'] ?? null;
    }

    /** @return list<string> the languages a post exists in, in configured order */
    public function translations(string $slug): array
    {
        return array_values(array_filter(
            array_keys($this->site->languages),
            fn (string $language) => isset($this->items($language)[$slug]),
        ));
    }

    /** Absolute path of a post's published file, or null if the post or file doesn't exist (or is a hidden draft). */
    public function asset(string $slug, string $file): ?string
    {
        foreach (array_keys($this->site->languages) as $language) {
            $post = $this->items($language)[$slug] ?? null;
            if ($post !== null) {
                return in_array($file, $post['assets'], true) ? $this->contentDir . '/' . dirname($post['source']) . '/' . $file : null;
            }
        }

        return null;
    }

    /**
     * Copies every published post's files to public/media/blog/<slug>/, where the web server serves
     * them directly. Drafts are never copied. Returns the number of files.
     */
    public function publishAssets(string $publicDir): int
    {
        $target = $publicDir . self::ASSET_URL;
        Cache::clear($target);
        $folders = [];
        foreach ($this->compiled() as $posts) {
            foreach ($posts as $post) {
                if (!$post['draft']) {
                    $folders[$post['slug']] = [dirname($post['source']), $post['assets']];
                }
            }
        }
        $count = 0;
        foreach ($folders as $slug => [$folder, $assets]) {
            foreach ($assets as $file) {
                $to = "{$target}/{$slug}/{$file}";
                if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
                    throw new \RuntimeException('Cannot create ' . dirname($to) . '.');
                }
                copy("{$this->contentDir}/{$folder}/{$file}", $to);
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Parses every Markdown file and writes the cache.
     *
     * @return array{int, int} posts, language versions
     */
    public function warmup(): array
    {
        $this->posts = null;
        $posts = $this->compile();
        Cache::writeData($this->cacheFile, $posts);
        $this->posts = $posts;

        $slugs = [];
        foreach ($posts as $versions) {
            $slugs += array_flip(array_keys($versions));
        }

        return [count($slugs), array_sum(array_map('count', $posts))];
    }

    /** @return array<string, array<string, Post>> */
    private function compiled(): array
    {
        return $this->posts ??= $this->debug ? $this->compile() : Cache::remember($this->cacheFile, $this->compile(...));
    }

    /** @return array<string, array<string, Post>> language => slug => post, newest first */
    private function compile(): array
    {
        $parser = new MarkdownParser($this->componentCheck);
        $posts = array_fill_keys(array_keys($this->site->languages), []);
        $folders = [];
        foreach ($this->postFolders() as $folder => [$slug, $draft, $month, $files]) {
            if ($draft && !$this->debug) {
                continue;
            }
            if (isset($folders[$slug])) {
                throw new \RuntimeException("Duplicate slug \"{$slug}\": {$folder}/ and {$folders[$slug]}/.");
            }
            $folders[$slug] = $folder;
            $assets = $this->assets("{$this->contentDir}/{$folder}");

            // The default-language version first: translations inherit what they omit from it.
            $original = null;
            foreach ($files as $language => $file) {
                $source = "{$folder}/{$file}";
                $post = $parser->parseFile("{$this->contentDir}/{$source}", $slug, $language, $draft, $source, $this->mediaUrl . self::ASSET_URL . '/' . $slug, $original);
                if ($language === $this->site->defaultLanguage && $post['uri'] !== $slug) {
                    throw new \RuntimeException("{$source}: \"slug\" is only for translations: in the default language the folder name is the slug, rename the folder instead.");
                }
                // The folder is the publication month: 2026/09/<slug>/ must hold a post dated 2026-09-xx.
                if ($month !== null && !str_starts_with($post['date'], $month)) {
                    throw new \RuntimeException("{$source}: date {$post['date']} does not match its YYYY/MM folder.");
                }
                $post['assets'] = $assets;
                $posts[$language][$slug] = $post;
                $original ??= $language === $this->site->defaultLanguage ? $post : null;
            }
        }
        foreach ($posts as $language => &$versions) {
            uasort($versions, static fn (array $a, array $b) => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);
            $uris = [];
            foreach ($versions as $slug => $post) {
                if (isset($uris[$post['uri']])) {
                    throw new \RuntimeException("{$post['source']}: /blog/{$post['uri']} is already the URL of \"{$uris[$post['uri']]}\" in this language ({$language}): change a \"slug\".");
                }
                $uris[$post['uri']] = $slug;
            }
        }
        unset($versions);

        return $posts;
    }

    /**
     * Every post folder, as "2026/09/hello-starlite" => [slug, is draft, "2026-09" or null, language => file],
     * with the default language's file first. Markdown files anywhere else, or for a language that
     * isn't configured, are an error rather than silently skipped, so nothing goes unnoticed.
     *
     * @return array<string, array{string, bool, ?string, array<string, string>}>
     */
    private function postFolders(): array
    {
        if (!is_dir($this->contentDir)) {
            return [];
        }
        $folders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->contentDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'md' || str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            $source = substr($file->getPathname(), strlen($this->contentDir) + 1);
            $index = 'index(?:\.([a-z]{2}(?:-[a-z]{2})?))?\.md';
            if (preg_match('#^((\d{4})/(0[1-9]|1[0-2])/(' . self::SLUG . '))/' . $index . '$#', $source, $m)) {
                [$folder, $slug, $draft, $month, $code] = [$m[1], $m[4], false, "{$m[2]}-{$m[3]}", $m[5] ?? ''];
            } elseif (preg_match('#^(drafts/(' . self::SLUG . '))/' . $index . '$#', $source, $m)) {
                [$folder, $slug, $draft, $month, $code] = [$m[1], $m[2], true, null, $m[3] ?? ''];
            } else {
                throw new \RuntimeException(
                    "{$source}: posts must be content/blog/YYYY/MM/<slug>/index.md or content/blog/drafts/<slug>/index.md "
                    . '(translations: index.<language>.md), with a slug of lowercase letters, digits and dashes.',
                );
            }
            $language = $code === '' ? $this->site->defaultLanguage : $code;
            if ($code === $this->site->defaultLanguage) {
                throw new \RuntimeException("{$source}: the default language ({$code}) is index.md, without a language code.");
            }
            if (!isset($this->site->languages[$language])) {
                throw new \RuntimeException("{$source}: language \"{$code}\" is not configured in config/app.php.");
            }
            $folders[$folder] ??= [$slug, $draft, $month, []];
            $folders[$folder][3][$language] = basename($source);
        }
        ksort($folders);
        foreach ($folders as &$folder) {
            // Default language first, then the others in configured order.
            $order = array_flip(array_keys($this->site->languages));
            $order[$this->site->defaultLanguage] = -1;
            uksort($folder[3], static fn (string $a, string $b) => $order[$a] <=> $order[$b]);
        }
        unset($folder);

        return $folders;
    }

    /** @return list<string> publishable files in a post folder (and its subfolders), relative to it */
    private function assets(string $dir): array
    {
        $assets = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            if (str_starts_with($file->getFilename(), '.') || !isset(self::ASSET_TYPES[strtolower($file->getExtension())])) {
                continue;
            }
            if (!preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $relative)) {
                throw new \RuntimeException("{$dir}/{$relative}: file names may only use letters, digits, dots, dashes and underscores.");
            }
            $assets[] = $relative;
        }
        sort($assets);

        return $assets;
    }
}
