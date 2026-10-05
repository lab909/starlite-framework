<?php

declare(strict_types=1);

namespace Starlite\Blog;

use Starlite\Content\AssetResponse;
use Starlite\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a post's files (/media/blog/<slug>/<file>) from its content folder, images as published
 * (see AssetResponse). In production `bin/console deploy` copies them to public/media/blog/, so the
 * web server answers before PHP is reached; this controller is the development path and a fallback.
 */
final class AssetController extends Controller
{
    public function __invoke(string $slug, string $file): Response
    {
        return AssetResponse::for($this->app, $file, fn (string $file) => $this->app->blog->asset($slug, $file));
    }
}
