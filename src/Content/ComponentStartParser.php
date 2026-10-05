<?php

declare(strict_types=1);

namespace Starlite\Content;

use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Recognises a content component, a line of its own:
 *
 *   ::youtube{id="dQw4w9WgXcQ"}
 *   ::related-posts{tag="php" limit=3 images=false}
 *   ::newsletter
 *
 * Arguments are plain values only: "double-quoted strings", numbers, true and false. No expressions,
 * so content can't run code. A line that starts like a component (`::` and a letter) but doesn't
 * parse is an error rather than text, so a typo never silently disappears. Code blocks are parsed
 * before this, so components inside them stay text.
 */
final class ComponentStartParser implements BlockStartParserInterface
{
    public const NAME = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none(); // four spaces: indented code
        }
        $cursor->advanceToNextNonSpaceOrTab(); // up to three spaces are allowed, as for any block
        if (!preg_match('/^::[a-z]/i', $cursor->getRemainder())) {
            return BlockStart::none();
        }
        $markup = trim($cursor->getRemainder());
        if (!preg_match('/^::(' . self::NAME . ')(?:\{(.*)\})?$/s', $markup, $m)) {
            throw new ComponentSyntaxError("\"{$markup}\": a component is ::name or ::name{key=\"value\" …}, with a name of lowercase letters, digits and dashes.");
        }
        $component = new Component($m[1], self::arguments($m[2] ?? '', $markup), $markup);
        $cursor->advanceToEnd();

        return BlockStart::of(new class ($component) extends AbstractBlockContinueParser {
            public function __construct(private readonly Component $component)
            {
            }

            public function getBlock(): Component
            {
                return $this->component;
            }

            public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
            {
                return BlockContinue::none(); // one line
            }
        })->at($cursor);
    }

    /** @return array<string, string|int|float|bool> */
    private static function arguments(string $source, string $markup): array
    {
        $args = [];
        $rest = trim($source);
        while ($rest !== '') {
            if (!preg_match('/^([a-z_][a-z0-9_]*)=("(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?|true|false)(?:\s+|$)/', $rest, $m)) {
                throw new ComponentSyntaxError("\"{$markup}\": arguments are key=\"text\", key=12 or key=true, separated by spaces (near \"{$rest}\").");
            }
            [$all, $key, $value] = $m;
            if ($key === 'entry') {
                throw new ComponentSyntaxError("\"{$markup}\": \"entry\" is the post or page the component is in, set by Starlite.");
            }
            if (array_key_exists($key, $args)) {
                throw new ComponentSyntaxError("\"{$markup}\": \"{$key}\" is given twice.");
            }
            $args[$key] = match (true) {
                $value[0] === '"' => stripcslashes(substr($value, 1, -1)),
                $value === 'true' => true,
                $value === 'false' => false,
                str_contains($value, '.') => (float) $value,
                default => (int) $value,
            };
            $rest = substr($rest, strlen($all));
        }

        return $args;
    }
}
