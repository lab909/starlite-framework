<?php

declare(strict_types=1);

namespace Starlite\Pages;

use Starlite\Blog\Blog;
use Starlite\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a page's files (/media/pages/<path>/<file>) straight from its content folder: the
 * development path, as `deploy` copies them to public/media/pages/. Only files in a page's asset
 * list are served, so no path from the URL reaches the filesystem unchecked.
 */
final class AssetController extends Controller
{
    public function __invoke(string $file): Response
    {
        $path = $this->app->pages->asset($file);
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
