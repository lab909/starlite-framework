<?php

declare(strict_types=1);

namespace Starlite\Content;

use League\CommonMark\Node\Block\AbstractBlock;

/** A `::name{key="value"}` line in Markdown: a content component (see ComponentStartParser). */
final class Component extends AbstractBlock
{
    /** @param array<string, string|int|float|bool> $args */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        public readonly string $markup,
    ) {
        parent::__construct();
    }
}
