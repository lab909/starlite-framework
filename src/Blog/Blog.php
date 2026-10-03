<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Cache;

/**
 * File-based blog. Each post is a folder named after its slug, holding index.md and its files
 * (images, downloads):
 *
 *   content/blog/2026/09/hello-starlite/index.md   published, filed by publication month
 *   content/blog/2026/09/hello-starlite/cover.jpg  served as /media/blog/hello-starlite/cover.jpg
 *   content/blog/drafts/next-post/index.md         draft: only visible with APP_DEBUG=1
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

    /** @var array<string, Post>|null slug => post, newest first */
    private ?array $posts = null;

    public function __construct(
        private readonly string $contentDir,
        private readonly string $cacheFile,
        private readonly bool $debug,
        public readonly int $perPage = 20,
    ) {
    }

    /** @return list<Post> newest first */
    public function all(): array
    {
        return array_values($this->posts());
    }

    /** @return Post|null */
    public function find(string $slug): ?array
    {
        return $this->posts()[$slug] ?? null;
    }

    /** Absolute path of a post's published file, or null if the post or file doesn't exist (or is a hidden draft). */
    public function asset(string $slug, string $file): ?string
    {
        $post = $this->find($slug);
        if ($post === null || !in_array($file, $post['assets'], true)) {
            return null;
        }

        return $this->contentDir . '/' . dirname($post['source']) . '/' . $file;
    }

    /**
     * Copies every published post's files to public/media/blog/<slug>/, where the web server serves
     * them directly. Drafts are never copied. Returns the number of files.
     */
    public function publishAssets(string $publicDir): int
    {
        $target = $publicDir . self::ASSET_URL;
        Cache::clear($target);
        $count = 0;
        foreach ($this->posts() as $post) {
            if ($post['draft']) {
                continue;
            }
            foreach ($post['assets'] as $file) {
                $to = "{$target}/{$post['slug']}/{$file}";
                if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
                    throw new \RuntimeException('Cannot create ' . dirname($to) . '.');
                }
                copy($this->contentDir . '/' . dirname($post['source']) . '/' . $file, $to);
                ++$count;
            }
        }

        return $count;
    }

    /** @return array<string, int> tag => number of posts, most used first */
    public function tags(): array
    {
        $tags = [];
        foreach ($this->posts() as $post) {
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
    public function page(int|string $page, string $query = '', string $tag = ''): array
    {
        $page = max(1, (int) $page);
        $posts = $query === '' && $tag === '' ? $this->all() : $this->search($query, $tag);
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
    public function search(string $query = '', string $tag = ''): array
    {
        $query = mb_strtolower(trim($query));

        return array_values(array_filter($this->posts(), static function (array $post) use ($query, $tag): bool {
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

    /** Parses every Markdown file and writes the cache. */
    public function warmup(): int
    {
        $posts = $this->compile();
        Cache::writeData($this->cacheFile, $posts);
        $this->posts = null;

        return count($posts);
    }

    /** @return array<string, Post> */
    private function posts(): array
    {
        if ($this->posts !== null) {
            return $this->posts;
        }

        return $this->posts = $this->debug ? $this->compile() : Cache::remember($this->cacheFile, $this->compile(...));
    }

    /** @return array<string, Post> */
    private function compile(): array
    {
        $parser = new MarkdownParser();
        $posts = [];
        foreach ($this->postFiles() as $source => [$slug, $draft, $month]) {
            if ($draft && !$this->debug) {
                continue;
            }
            $post = $parser->parseFile($this->contentDir . '/' . $source, $slug, $draft, $source, self::ASSET_URL . '/' . $slug);
            // The folder is the publication month: 2026/09/<slug>/ must hold a post dated 2026-09-xx.
            if ($month !== null && !str_starts_with($post['date'], $month)) {
                throw new \RuntimeException("{$source}: date {$post['date']} does not match its YYYY/MM folder.");
            }
            if (isset($posts[$slug])) {
                throw new \RuntimeException("Duplicate slug \"{$slug}\": {$source} and {$posts[$slug]['source']}.");
            }
            $post['assets'] = $this->assets(dirname($this->contentDir . '/' . $source));
            $posts[$slug] = $post;
        }
        uasort($posts, static fn (array $a, array $b) => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);

        return $posts;
    }

    /**
     * Every post's index.md, as "2026/09/hello-starlite/index.md" => [slug, is draft, "2026-09" or null].
     * Markdown files anywhere else are an error rather than silently skipped, so a misplaced post
     * can't go unnoticed.
     *
     * @return array<string, array{string, bool, ?string}>
     */
    private function postFiles(): array
    {
        if (!is_dir($this->contentDir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->contentDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'md' || str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            $source = substr($file->getPathname(), strlen($this->contentDir) + 1);
            if (preg_match('#^(\d{4})/(0[1-9]|1[0-2])/(' . self::SLUG . ')/index\.md$#', $source, $m)) {
                $files[$source] = [$m[3], false, "{$m[1]}-{$m[2]}"];
            } elseif (preg_match('#^drafts/(' . self::SLUG . ')/index\.md$#', $source, $m)) {
                $files[$source] = [$m[1], true, null];
            } else {
                throw new \RuntimeException(
                    "{$source}: posts must be content/blog/YYYY/MM/<slug>/index.md or content/blog/drafts/<slug>/index.md, "
                    . 'with a slug of lowercase letters, digits and dashes.',
                );
            }
        }
        ksort($files);

        return $files;
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
