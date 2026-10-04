<?php

declare(strict_types=1);

namespace Starlite\Collections;

/**
 * One collection in the current language: iterable in its configured order.
 *
 *   {% for question in collections.faq %}{{ question.question }} {{ question.html|raw }}{% endfor %}
 *   {% set member = collections.team.find('ada') %}
 *
 * An item is an array of its fields plus `slug`, `language` (differs from the page's for a
 * fallback), `html` (the Markdown body; '' for YAML files) and `source`.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Collection implements \IteratorAggregate, \Countable
{
    /** @param \Closure(?string): array<string, array<string, mixed>> $items slug => item in a language (null: current) */
    public function __construct(
        public readonly Schema $schema,
        private readonly \Closure $items,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(?string $language = null): array
    {
        return array_values(($this->items)($language));
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug, ?string $language = null): ?array
    {
        return ($this->items)($language)[$slug] ?? null;
    }

    /**
     * Items whose field equals the value (or, for a list field, contains it).
     *
     * @return list<array<string, mixed>>
     */
    public function where(string $field, mixed $value, ?string $language = null): array
    {
        if (!isset($this->schema->fields[$field]) && !in_array($field, Schema::RESERVED, true)) {
            throw new \InvalidArgumentException("Collection \"{$this->schema->name}\" has no field \"{$field}\".");
        }

        return array_values(array_filter(
            ($this->items)($language),
            static fn (array $item) => is_array($item[$field]) ? in_array($value, $item[$field], true) : $item[$field] === $value,
        ));
    }

    /**
     * The fields allowlisted under `json` in config/collections.php, plus slug and language: what
     * /data/<collection>.json serves to JavaScript.
     *
     * @return list<array<string, mixed>>
     */
    public function json(?string $language = null): array
    {
        $fields = $this->schema->json ?? throw new \LogicException("Collection \"{$this->schema->name}\" has no JSON export: set \"json\" in config/collections.php.");
        $keep = array_flip(['slug', 'language', ...$fields]);

        return array_map(static fn (array $item) => array_intersect_key($item, $keep), $this->all($language));
    }

    /** @return \ArrayIterator<int, array<string, mixed>> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    public function count(): int
    {
        return count(($this->items)(null));
    }
}
