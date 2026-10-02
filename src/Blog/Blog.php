<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Cache;

/**
 * File-based blog: every content/blog/*.md file is a post.
 *
 * Without APP_DEBUG the parsed posts (HTML included) are compiled once into var/cache/blog.php,
 * so a request only reads an Opcache-resident array. Run `bin/console deploy` (or cache:clear)
 * after publishing new content.
 *
 * @phpstan-import-type Post from MarkdownParser
 */
final class Blog
{
    /** @var array<string, Post>|null slug => post, newest first */
    private ?array $posts = null;

    public function __construct(
        private readonly string $contentDir,
        private readonly string $cacheFile,
        private readonly bool $debug,
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
        foreach (glob($this->contentDir . '/*.md') ?: [] as $file) {
            $post = $parser->parseFile($file);
            if ($post['draft'] && !$this->debug) {
                continue;
            }
            if (isset($posts[$post['slug']])) {
                throw new \RuntimeException("Duplicate slug \"{$post['slug']}\" in {$file} and {$posts[$post['slug']]['source']}.");
            }
            $post['source'] = basename($file);
            $posts[$post['slug']] = $post;
        }
        uasort($posts, static fn (array $a, array $b) => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);

        return $posts;
    }
}
