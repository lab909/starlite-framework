<?php

declare(strict_types=1);

namespace Starlite\Seo;

use Starlite\Controller;
use Symfony\Component\HttpFoundation\Response;

/** /robots.txt: allows everything and points crawlers at the sitemap (absolute URL from APP_URL). */
final class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        $body = "User-agent: *\nAllow: /\n\nSitemap: " . $this->app->seo->url('/sitemap.xml') . "\n";

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
