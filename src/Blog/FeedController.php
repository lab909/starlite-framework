<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Atom feed of the latest posts. Expects the app to name its routes `blog` (list) and `blog_post` (post).
 */
final class FeedController extends Controller
{
    private const LIMIT = 20;

    public function __invoke(): Response
    {
        $seo = $this->app->seo;
        $router = $this->app->router;
        $published = array_filter($this->app->blog->all(), static fn (array $post) => !$post['draft']);
        $posts = array_slice(array_values($published), 0, self::LIMIT);
        $author = $seo->site['author'] ?? $seo->site['name'];

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        // Relative links and images inside post HTML resolve against the site URL.
        $xml->writeAttribute('xml:base', $seo->url('/'));

        $xml->writeElement('id', $seo->url($router->generate('blog')));
        $xml->writeElement('title', $seo->site['name']);
        $xml->writeElement('subtitle', $seo->site['description']);
        $xml->writeElement('updated', self::time($posts[0]['updated'] ?? $posts[0]['date'] ?? gmdate('Y-m-d')));
        self::link($xml, $seo->url($router->generate('blog_feed')), 'self', 'application/atom+xml');
        self::link($xml, $seo->url($router->generate('blog')), 'alternate', 'text/html');
        $xml->startElement('author');
        $xml->writeElement('name', $author);
        $xml->endElement();

        foreach ($posts as $post) {
            $url = $seo->url($router->generate('blog_post', ['slug' => $post['slug']]));
            $xml->startElement('entry');
            $xml->writeElement('id', $url);
            $xml->writeElement('title', $post['title']);
            self::link($xml, $url, 'alternate', 'text/html');
            $xml->writeElement('published', self::time($post['date']));
            $xml->writeElement('updated', self::time($post['updated'] ?? $post['date']));
            foreach ($post['tags'] as $tag) {
                $xml->startElement('category');
                $xml->writeAttribute('term', $tag);
                $xml->endElement();
            }
            $xml->writeElement('summary', $post['summary']);
            $xml->startElement('content');
            $xml->writeAttribute('type', 'html');
            $xml->text($post['html']);
            $xml->endElement();
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return new Response($xml->outputMemory(), 200, ['Content-Type' => 'application/atom+xml; charset=utf-8']);
    }

    private static function time(string $date): string
    {
        return $date . 'T00:00:00Z';
    }

    private static function link(\XMLWriter $xml, string $href, string $rel, string $type): void
    {
        $xml->startElement('link');
        $xml->writeAttribute('href', $href);
        $xml->writeAttribute('rel', $rel);
        $xml->writeAttribute('type', $type);
        $xml->endElement();
    }
}
