<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Cache;
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
 * Every method works in the current language (Site::language()) unless one is given. A post
 * without a version in a language doesn't exist there: not listed, not searchable, 404.
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
    ) {
    }

    /** @return list<Post> newest first */
    public function all(?string $language = null): array
    {
        return array_values($this->posts($language));
    }

    /** @return Post|null */
    public function find(string $slug, ?string $language = null): ?array
    {
        return $this->posts($language)[$slug] ?? null;
    }

    /** @return list<string> the languages a post exists in, in configured order */
    public function translations(string $slug): array
    {
        return array_values(array_filter(
            array_keys($this->site->languages),
            fn (string $language) => isset($this->posts($language)[$slug]),
        ));
    }

    /** Absolute path of a post's published file, or null if the post or file doesn't exist (or is a hidden draft). */
    public function asset(string $slug, string $file): ?string
    {
        foreach (array_keys($this->site->languages) as $language) {
            $post = $this->find($slug, $language);
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

    /** @return array<string, int> tag => number of posts, most used first */
    public function tags(?string $language = null): array
    {
        $tags = [];
        foreach ($this->posts($language) as $post) {
            foreach ($post['tags'] as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }
        arsort($tags);

        return $tags;
    }

    /**
     * One page of posts, newest first, optionally filtered like search(). A page past the end has
     * no posts (the controller turns that into a 404).
     *
     * @return array{posts: list<Post>, page: int, pages: int, total: int, has_more: bool}
     */
    public function page(int|string $page, string $query = '', string $tag = '', ?string $language = null): array
    {
        $page = max(1, (int) $page);
        $posts = $query === '' && $tag === '' ? $this->all($language) : $this->search($query, $tag, $language);
        $total = count($posts);
        $pages = max(1, (int) ceil($total / $this->perPage));

        return [
            'posts' => array_slice($posts, ($page - 1) * $this->perPage, $this->perPage),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'has_more' => $page < $pages,
        ];
    }

    /** @return list<Post> posts whose title, summary or tags contain $query, optionally limited to one tag */
    public function search(string $query = '', string $tag = '', ?string $language = null): array
    {
        $query = mb_strtolower(trim($query));

        return array_values(array_filter($this->posts($language), static function (array $post) use ($query, $tag): bool {
            if ($tag !== '' && !in_array($tag, $post['tags'], true)) {
                return false;
            }
            if ($query === '') {
                return true;
            }
            $haystack = mb_strtolower($post['title'] . ' ' . $post['summary'] . ' ' . implode(' ', $post['tags']));

            return str_contains($haystack, $query);
        }));
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

    /** @return array<string, Post> slug => post in one language */
    private function posts(?string $language): array
    {
        return $this->compiled()[$language ?? $this->site->language()] ?? [];
    }

    /** @return array<string, array<string, Post>> */
    private function compiled(): array
    {
        return $this->posts ??= $this->debug ? $this->compile() : Cache::remember($this->cacheFile, $this->compile(...));
    }

    /** @return array<string, array<string, Post>> language => slug => post, newest first */
    private function compile(): array
    {
        $parser = new MarkdownParser();
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
                $post = $parser->parseFile("{$this->contentDir}/{$source}", $slug, $language, $draft, $source, self::ASSET_URL . '/' . $slug, $original);
                // The folder is the publication month: 2026/09/<slug>/ must hold a post dated 2026-09-xx.
                if ($month !== null && !str_starts_with($post['date'], $month)) {
                    throw new \RuntimeException("{$source}: date {$post['date']} does not match its YYYY/MM folder.");
                }
                $post['assets'] = $assets;
                $posts[$language][$slug] = $post;
                $original ??= $language === $this->site->defaultLanguage ? $post : null;
            }
        }
        foreach ($posts as &$versions) {
            uasort($versions, static fn (array $a, array $b) => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);
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
