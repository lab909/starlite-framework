<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a post's files (/media/blog/<slug>/<file>) straight from its content folder.
 *
 * In production `bin/console deploy` copies these files to public/media/blog/, so the web server
 * answers before PHP is reached; this controller is the development path and a fallback.
 * Only files listed in the post's asset whitelist are served, so no path from the URL ever
 * reaches the filesystem unchecked.
 */
final class AssetController extends Controller
{
    public function __invoke(string $slug, string $file): Response
    {
        $path = $this->app->blog->asset($slug, $file);
        if ($path === null) {
            return $this->notFound();
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => Blog::ASSET_TYPES[$extension]], true, null, true, true);
        if ($extension === 'svg') {
            // An SVG opened directly could run scripts on this origin: sandbox it.
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        return $response->setPublic()->setMaxAge($this->app->debug ? 0 : 3600);
    }
}
