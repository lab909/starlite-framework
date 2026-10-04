<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Atom feed of the latest posts in the current language (/blog/feed.xml, /it/blog/feed.xml).
 * Expects the app to name its routes `blog` (list), `blog_post` (post) and `blog_feed` (this feed).
 */
final class FeedController extends Controller
{
    private const LIMIT = 20;

    public function __invoke(): Response
    {
        $seo = $this->app->seo;
        $posts = $this->app->posts()->where('draft', false)->limit(self::LIMIT)->all();
        $author = $seo->site->author ?? $seo->site->name;

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        // Relative links and images inside post HTML resolve against the site URL.
        $xml->writeAttribute('xml:base', $seo->url('/'));
        $xml->writeAttribute('xml:lang', $this->app->site->language());

        $xml->writeElement('id', $seo->url($this->app->path('blog')));
        $xml->writeElement('title', $seo->site->name);
        $xml->writeElement('subtitle', $seo->site->description);
        $xml->writeElement('updated', self::time($posts[0]['updated'] ?? $posts[0]['date'] ?? gmdate('Y-m-d')));
        self::link($xml, $seo->url($this->app->path('blog_feed')), 'self', 'application/atom+xml');
        self::link($xml, $seo->url($this->app->path('blog')), 'alternate', 'text/html');
        $xml->startElement('author');
        $xml->writeElement('name', $author);
        $xml->endElement();

        foreach ($posts as $post) {
            $url = $seo->url($this->app->path('blog_post', ['slug' => $post['slug']]));
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
