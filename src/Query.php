<?php

declare(strict_types=1);

namespace Starlite;

/**
 * A content query, as in Craft's element queries: content isn't handed to templates, they ask for it.
 *
 *   {% set latest = posts().tag('php').limit(5).all() %}
 *   {% set result = posts().search(q).paginate(page) %}
 *   {% set ada = collection('team').slug('ada').one() %}
 *
 *   $this->app->posts()->tag('php')->all();
 *
 * Immutable: every method returns a new query, so a base query can be reused and branched
 * (`{% set q = q.tag(tag) %}`). Nothing is read until an execution method runs: all(), one(),
 * count(), exists(), paginate(), countBy(), or a `for` loop over the query. Results are in the current
 * language unless language() says otherwise; items are arrays (see the blog and collections docs).
 *
 * @template TItem of array<string, mixed>
 *
 * @implements \IteratorAggregate<int, TItem>
 */
final class Query implements \IteratorAggregate, \Countable
{
    private ?string $language = null;

    /** @var list<\Closure(TItem): bool> */
    private array $filters = [];

    /** @var list<array{string, bool}> field, descending */
    private array $order = [];

    private ?int $limit = null;
    private int $offset = 0;

    /**
     * @param string                                                $label        for error messages, e.g. 'posts' or 'collection "faq"'
     * @param \Closure(string): array<string, TItem> $source items by slug in a language, in their default order
     * @param list<string>                                          $fields       keys where(), orderBy() and countBy() accept
     * @param list<string>                                          $searchFields keys search() looks in
     */
    public function __construct(
        private readonly string $label,
        private readonly \Closure $source,
        private readonly array $fields,
        private readonly array $searchFields,
        private readonly Site $site,
        private readonly int $perPage = 20,
    ) {
    }

    // --- Parameters (each returns a new query) ------------------------------------

    /**
     * Items in this language instead of the current one.
     *
     * @return self<TItem>
     */
    public function language(string $language): self
    {
        if (!isset($this->site->languages[$language])) {
            throw new \InvalidArgumentException("Language \"{$language}\" is not configured in config/app.php.");
        }
        $query = clone $this;
        $query->language = $language;

        return $query;
    }

    /**
     * Items whose field equals the value. A list of values matches any of them; on a list field
     * (tags…), an item matches when the list contains the value.
     *
     * @return self<TItem>
     */
    public function where(string $field, mixed $value): self
    {
        $this->assertField($field);
        $values = is_array($value) ? array_values($value) : [$value];

        return $this->filter(static function (array $item) use ($field, $values): bool {
            $actual = $item[$field];

            return is_array($actual)
                ? array_intersect(array_map('strval', $actual), array_map('strval', $values)) !== []
                : in_array($actual, $values, true);
        });
    }

    /**
     * @param string|list<string> $slug
     *
     * @return self<TItem>
     */
    public function slug(string|array $slug): self
    {
        return $this->where('slug', $slug);
    }

    /**
     * Items tagged with $tag. Empty or null: no filter, so a request value can be passed straight in.
     *
     * @return self<TItem>
     */
    public function tag(?string $tag): self
    {
        return $tag === null || $tag === '' ? $this : $this->where('tags', $tag);
    }

    /**
     * Items containing $text (case-insensitive) in their main text fields. Empty or null: no filter.
     *
     * @return self<TItem>
     */
    public function search(?string $text): self
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return $this;
        }
        $fields = $this->searchFields;

        return $this->filter(static function (array $item) use ($fields, $text): bool {
            $haystack = '';
            foreach ($fields as $field) {
                $value = $item[$field] ?? '';
                $haystack .= ' ' . (is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value);
            }

            return str_contains(mb_strtolower($haystack), $text);
        });
    }

    /**
     * Sorting, replacing the default order: 'date desc', 'order', 'name asc, joined desc'. Items
     * without a value come last; text compares naturally and case-insensitively.
     *
     * @return self<TItem>
     */
    public function orderBy(string $order): self
    {
        $parsed = [];
        foreach (array_filter(array_map('trim', explode(',', $order))) as $part) {
            if (!preg_match('/^([a-z_][a-z0-9_]*)(?:\s+(asc|desc))?$/i', $part, $m)) {
                throw new \InvalidArgumentException("{$this->label}: invalid orderBy \"{$part}\", e.g. 'date desc'.");
            }
            $this->assertField($m[1]);
            $parsed[] = [$m[1], strtolower($m[2] ?? '') === 'desc'];
        }
        $query = clone $this;
        $query->order = $parsed;

        return $query;
    }

    /** @return self<TItem> */
    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException("{$this->label}: limit can't be negative.");
        }
        $query = clone $this;
        $query->limit = $limit;

        return $query;
    }

    /** @return self<TItem> */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException("{$this->label}: offset can't be negative.");
        }
        $query = clone $this;
        $query->offset = $offset;

        return $query;
    }

    // --- Execution ----------------------------------------------------------------

    /** @return list<TItem> */
    public function all(): array
    {
        return array_slice($this->matching(), $this->offset, $this->limit);
    }

    /** @return TItem|null the first match */
    public function one(): ?array
    {
        return $this->limit(1)->all()[0] ?? null;
    }

    /** The number of results, after limit and offset (paginate() reports the total). */
    public function count(): int
    {
        return count($this->all());
    }

    public function exists(): bool
    {
        return $this->one() !== null;
    }

    /**
     * One page of the matches (limit and offset are ignored). A page past the end has no items.
     *
     * @return array{items: list<TItem>, page: int, pages: int, per_page: int, total: int, has_more: bool}
     */
    public function paginate(int|string $page = 1, ?int $perPage = null): array
    {
        $page = max(1, (int) $page);
        $perPage = max(1, $perPage ?? $this->perPage);
        $matching = $this->matching();
        $total = count($matching);
        $pages = max(1, (int) ceil($total / $perPage));

        return [
            'items' => array_slice($matching, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $page < $pages,
        ];
    }

    /**
     * How many matches have each value of a field, most frequent first (limit and offset are
     * ignored): `posts().countBy('tags')` → {php: 3, datastar: 1}.
     *
     * @return array<string, int>
     */
    public function countBy(string $field): array
    {
        $this->assertField($field);
        $counts = [];
        foreach ($this->matching() as $item) {
            foreach ((array) $item[$field] as $value) { // (array) null is []: missing values aren't counted
                $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /** @return \ArrayIterator<int, TItem> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    // ---------------------------------------------------------------------------------

    /**
     * @param \Closure(TItem): bool $filter
     *
     * @return self<TItem>
     */
    private function filter(\Closure $filter): self
    {
        $query = clone $this;
        $query->filters[] = $filter;

        return $query;
    }

    /** @return list<TItem> filtered and sorted, before limit and offset */
    private function matching(): array
    {
        $items = array_values(($this->source)($this->language ?? $this->site->language()));
        foreach ($this->filters as $filter) {
            $items = array_values(array_filter($items, $filter));
        }
        if ($this->order !== []) {
            $order = $this->order;
            usort($items, static function (array $a, array $b) use ($order): int {
                foreach ($order as [$field, $descending]) {
                    [$x, $y] = [$a[$field], $b[$field]];
                    if ($x === null || $y === null) {
                        $result = ($x === null) <=> ($y === null); // missing values last, either direction
                    } else {
                        $result = is_string($x) && is_string($y) ? strnatcasecmp($x, $y) : $x <=> $y;
                        $result = $descending ? -$result : $result;
                    }
                    if ($result !== 0) {
                        return $result;
                    }
                }

                return 0;
            });
        }

        return $items;
    }

    private function assertField(string $field): void
    {
        if (!in_array($field, $this->fields, true)) {
            throw new \InvalidArgumentException("{$this->label}: no field \"{$field}\". Fields: " . implode(', ', $this->fields) . '.');
        }
    }
}
