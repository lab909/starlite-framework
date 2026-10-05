<?php

declare(strict_types=1);

namespace Starlite\Content;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Leaves a placeholder where a component goes: the compiled HTML stays static, and
 * Kernel::content() (`{{ content(post) }}`) renders the component's template per request, with the
 * current language, routes and queries.
 */
final class ComponentRenderer implements NodeRendererInterface
{
    public const PLACEHOLDER = '<!--starlite-component:%d-->';

    /** @param \Closure(Component): int $register records a component, returns its index */
    public function __construct(private readonly \Closure $register)
    {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (!$node instanceof Component) {
            throw new \InvalidArgumentException('Expected a ' . Component::class . '.');
        }

        return sprintf(self::PLACEHOLDER, ($this->register)($node));
    }
}
