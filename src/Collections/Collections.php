<?php

declare(strict_types=1);

namespace Starlite\Collections;

use Starlite\Blog\MarkdownParser;
use Starlite\Cache;
use Starlite\Site;
use Symfony\Component\Yaml\Yaml;

/**
 * Data collections: structured content beyond blog posts (FAQs, team, products, a sound catalogue…),
 * defined in config/collections.php (see Schema) and kept in content/<collection>/:
 *
 *   content/faq/what-is-starlite.md       front matter (the fields) + an optional Markdown body (`html`)
 *   content/faq/what-is-starlite.it.md    Italian version: omitted fields keep the default-language value
 *   content/team/ada.yaml                 data only
 *
 * The file name is the item's slug. In Twig: `{% for q in collections.faq %}`, `collections.faq.find('x')`;
 * in PHP: `$app->collections['faq']`. Everything works in the current language.
 *
 * Without APP_DEBUG every collection is compiled once into var/cache/collections.php (Markdown
 * already rendered), like the blog: run `bin/console deploy` after changing content.
 *
 * @implements \ArrayAccess<string, Collection>
 * @implements \IteratorAggregate<string, Collection>
 */
final class Collections implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** content/ folders that belong to Starlite, not to collections. */
    public const RESERVED = ['blog', 'pages'];

    private const SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** @var array<string, Schema> */
    public readonly array $schemas;

    /** @var array<string, array<string, array<string, array<string, mixed>>>>|null language => collection => slug => item */
    private ?array $items = null;

    /** @param array<string, array<mixed>> $config config/collections.php */
    public function __construct(
        array $config,
        private readonly string $contentDir,
        private readonly string $cacheFile,
        private readonly bool $debug,
        private readonly Site $site,
    ) {
        $schemas = [];
        foreach ($config as $name => $definition) {
            $schemas[(string) $name] = new Schema((string) $name, (array) $definition);
        }
        $this->schemas = $schemas;
    }

    public function get(string $name): Collection
    {
        $schema = $this->schemas[$name] ?? throw new \InvalidArgumentException(
            "Unknown collection \"{$name}\". Collections: " . (implode(', ', array_keys($this->schemas)) ?: 'none') . ' (config/collections.php).',
        );

        return new Collection($schema, fn (?string $language) => $this->compiled()[$language ?? $this->site->language()][$name] ?? []);
    }

    /** @return list<string> collections with a JSON export */
    public function exported(): array
    {
        return array_keys(array_filter($this->schemas, static fn (Schema $schema) => $schema->json !== null));
    }

    /**
     * Parses every collection and writes the cache.
     *
     * @return array<string, int> collection => items (in the default language)
     */
    public function warmup(): array
    {
        $this->items = null;
        $items = $this->compile();
        Cache::writeData($this->cacheFile, $items);
        $this->items = $items;

        return array_map('count', $items[$this->site->defaultLanguage] ?? []);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->schemas[$offset]);
    }

    public function offsetGet(mixed $offset): Collection
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('Collections are defined in config/collections.php.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('Collections are defined in config/collections.php.');
    }

    public function getIterator(): \Generator
    {
        foreach (array_keys($this->schemas) as $name) {
            yield $name => $this->get($name);
        }
    }

    public function count(): int
    {
        return count($this->schemas);
    }

    /** @return array<string, array<string, array<string, array<string, mixed>>>> */
    private function compiled(): array
    {
        return $this->items ??= $this->debug ? $this->compile() : Cache::remember($this->cacheFile, $this->compile(...));
    }

    /** @return array<string, array<string, array<string, array<string, mixed>>>> language => collection => slug => item, sorted */
    private function compile(): array
    {
        $this->checkContentFolders();
        $markdown = new MarkdownParser();
        $default = $this->site->defaultLanguage;
        $compiled = array_fill_keys(array_keys($this->site->languages), []);

        foreach ($this->schemas as $name => $schema) {
            $versions = []; // slug => language => item
            foreach ($this->files($name) as $slug => $files) {
                if (!isset($files[$default])) {
                    $other = $files[array_key_first($files)];
                    throw new \RuntimeException("{$name}/{$other}: a translation needs the default-language file {$name}/{$slug}." . pathinfo($other, PATHINFO_EXTENSION) . ' first.');
                }
                $original = null;
                foreach ($files as $language => $file) {
                    $source = "{$name}/{$file}";
                    [$data, $html] = $this->read("{$this->contentDir}/{$source}", $source, $markdown);
                    $item = ['slug' => $slug, 'language' => $language]
                        + $schema->item($data, $source, $markdown, $original)
                        + ['html' => $html !== '' || $original === null ? $html : $original['html'], 'source' => $source];
                    $versions[$slug][$language] = $item;
                    $original ??= $item;
                }
            }
            foreach (array_keys($this->site->languages) as $language) {
                $items = [];
                foreach ($versions as $slug => $byLanguage) {
                    $item = $byLanguage[$language] ?? ($schema->fallback ? $byLanguage[$default] : null);
                    if ($item !== null) {
                        $items[$slug] = $item;
                    }
                }
                $compiled[$language][$name] = self::sort($items, $schema);
            }
        }

        return $compiled;
    }

    /**
     * An item's files, default language first: slug => language => file name.
     *
     * @return array<string, array<string, string>>
     */
    private function files(string $name): array
    {
        $dir = "{$this->contentDir}/{$name}";
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (self::entries($dir) as $filename) {
            if (!preg_match('/^(' . self::SLUG . ')(?:\.([a-z]{2}(?:-[a-z]{2})?))?\.(md|ya?ml)$/', $filename, $m) || !is_file("{$dir}/{$filename}")) {
                throw new \RuntimeException(
                    "{$name}/{$filename}: collections hold <slug>.md or <slug>.yaml files (translations: <slug>.<language>.md), "
                    . 'with a slug of lowercase letters, digits and dashes; images and other files go in public/.',
                );
            }
            [, $slug, $code] = $m;
            if ($code === $this->site->defaultLanguage) {
                throw new \RuntimeException("{$name}/{$filename}: the default language ({$code}) has no language code: {$slug}.{$m[3]}.");
            }
            $language = $code === '' ? $this->site->defaultLanguage : $code;
            if (!isset($this->site->languages[$language])) {
                throw new \RuntimeException("{$name}/{$filename}: language \"{$code}\" is not configured in config/app.php.");
            }
            if (isset($files[$slug][$language])) {
                throw new \RuntimeException("{$name}/{$filename}: \"{$slug}\" already has a file for this language ({$files[$slug][$language]}).");
            }
            $files[$slug][$language] = $filename;
        }
        ksort($files);
        $order = array_flip(array_keys($this->site->languages));
        $order[$this->site->defaultLanguage] = -1;
        foreach ($files as &$languages) {
            uksort($languages, static fn (string $a, string $b) => $order[$a] <=> $order[$b]);
        }
        unset($languages);

        return $files;
    }

    /** @return array{array<mixed>, string} fields, HTML of the Markdown body ('' for YAML files) */
    private function read(string $path, string $source, MarkdownParser $markdown): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }
        try {
            if (str_ends_with($path, '.md')) {
                [$data, $html] = $markdown->convert($contents, $source);
                $data ??= [];
            } else {
                [$data, $html] = [Yaml::parse($contents), ''];
            }
        } catch (\Symfony\Component\Yaml\Exception\ParseException | \League\CommonMark\Exception\CommonMarkException $e) {
            throw new \RuntimeException("{$source}: invalid YAML: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException("{$source}: the fields must be a YAML mapping (field: value).");
        }

        return [$data, trim($html)];
    }

    /** Every folder in content/ is the blog, pages or a collection: a stray one is likely a typo. */
    private function checkContentFolders(): void
    {
        if (!is_dir($this->contentDir)) {
            return;
        }
        foreach (self::entries($this->contentDir) as $name) {
            if (is_dir("{$this->contentDir}/{$name}") && !in_array($name, self::RESERVED, true) && !isset($this->schemas[$name])) {
                throw new \RuntimeException("content/{$name}/ is not a collection: define it in config/collections.php (collections: " . (implode(', ', array_keys($this->schemas)) ?: 'none') . ').');
            }
        }
    }

    /** @return list<string> names in a folder, without dot files (.gitkeep, .DS_Store…) */
    private static function entries(string $dir): array
    {
        return array_values(array_filter(scandir($dir) ?: [], static fn (string $name) => !str_starts_with($name, '.')));
    }

    /**
     * @param array<string, array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private static function sort(array $items, Schema $schema): array
    {
        $field = $schema->sortField;
        if ($field !== null) {
            uasort($items, static function (array $a, array $b) use ($field, $schema): int {
                // Missing values last, whatever the direction; ties by slug.
                if (($a[$field] === null) !== ($b[$field] === null)) {
                    return $a[$field] === null ? 1 : -1;
                }
                $order = $schema->sortDescending ? $b[$field] <=> $a[$field] : $a[$field] <=> $b[$field];

                return $order !== 0 ? $order : $a['slug'] <=> $b['slug'];
            });
        }

        return $items;
    }
}
