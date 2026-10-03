<?php

declare(strict_types=1);

namespace Starlite\Seo;

use Starlite\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * /sitemap.xml: in every language, the static GET pages (no placeholders, no file extension) and
 * the published blog posts written in that language.
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

        // Every language: the static pages under its prefix, and the posts written in it.
        foreach (array_keys($this->app->site->languages) as $language) {
            foreach ($this->app->router->routes() as $name => $route) {
                $methods = $route->getMethods();
                $path = $route->getPath();
                if ($name === 'datastar' || ($methods !== [] && !in_array('GET', $methods, true))
                    || str_contains($path, '{') || str_contains(basename($path), '.')) {
                    continue;
                }
                self::url($xml, $this->app->seo->url($this->app->site->localize($path, $language)));
            }

            foreach ($this->app->blog->all($language) as $post) {
                if ($post['draft']) {
                    continue; // only listed while APP_DEBUG=1; never advertise them to crawlers
                }
                self::url($xml, $this->app->seo->url($this->app->path('blog_post', ['slug' => $post['slug']], $language)), $post['updated'] ?? $post['date']);
            }
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
