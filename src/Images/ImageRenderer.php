<?php

declare(strict_types=1);

namespace Starlite\Images;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Renderer\Inline\ImageRenderer as CommonMarkImageRenderer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;

/**
 * `![Our team](team.jpg)` in a post or page: a <picture> with responsive versions (see Images) when the
 * image is a file of the post's folder; anything else (a URL, an SVG) as CommonMark renders it.
 */
final class ImageRenderer implements \League\CommonMark\Renderer\NodeRendererInterface
{
    /** Node data key MarkdownParser sets to the image's file, for the images of the post's own folder. */
    public const SOURCE = 'starlite_source';

    private readonly CommonMarkImageRenderer $fallback;

    public function __construct(private readonly Images $images)
    {
        $this->fallback = new CommonMarkImageRenderer();
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string
    {
        if (!$node instanceof Image) {
            throw new \InvalidArgumentException('Expected an image.');
        }
        $source = $node->data->get(self::SOURCE, null);
        if (!is_string($source) || !Images::isRaster($source)) {
            return $this->fallback->render($node, $childRenderer);
        }
        $title = $node->getTitle();

        return $this->images->picture($source, $node->getUrl(), self::altText($node), attributes: ['title' => $title !== null && $title !== '' ? $title : null]);
    }

    private static function altText(Image $node): string
    {
        $alt = '';
        foreach (new NodeIterator($node) as $child) {
            if ($child instanceof StringContainerInterface) {
                $alt .= $child->getLiteral();
            } elseif ($child instanceof Newline) {
                $alt .= "\n";
            }
        }

        return $alt;
    }
}
