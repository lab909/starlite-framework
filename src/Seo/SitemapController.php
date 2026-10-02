<?php

declare(strict_types=1);

namespace Starlite\Seo;

use Starlite\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * /sitemap.xml: every static GET page (no placeholders, no file extension) plus every published blog post.
 */
final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($this->app->router->routes() as $name => $route) {
            $methods = $route->getMethods();
            $path = $route->getPath();
            if ($name === 'datastar' || ($methods !== [] && !in_array('GET', $methods, true))
                || str_contains($path, '{') || str_contains(basename($path), '.')) {
                continue;
            }
            self::url($xml, $this->app->seo->url($path));
        }

        foreach ($this->app->blog->all() as $post) {
            if ($post['draft']) {
                continue; // only listed while APP_DEBUG=1; never advertise them to crawlers
            }
            self::url($xml, $this->app->seo->url($this->app->router->generate('blog_post', ['slug' => $post['slug']])), $post['updated'] ?? $post['date']);
        }

        $xml->endElement();
        $xml->endDocument();

        return new Response($xml->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private static function url(\XMLWriter $xml, string $loc, ?string $lastmod = null): void
    {
        $xml->startElement('url');
        $xml->writeElement('loc', $loc);
        if ($lastmod !== null) {
            $xml->writeElement('lastmod', $lastmod);
        }
        $xml->endElement();
    }
}
