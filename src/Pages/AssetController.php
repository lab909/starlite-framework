<?php

declare(strict_types=1);

namespace Starlite\Pages;

use Starlite\Content\AssetResponse;
use Starlite\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a page's files (/media/pages/<path>/<file>) from its content folder, images as published
 * (see AssetResponse): the development path, as `deploy` copies them to public/media/pages/.
 */
final class AssetController extends Controller
{
    public function __invoke(string $file): Response
    {
        return AssetResponse::for($this->app, $file, fn (string $file) => $this->app->pages->asset($file));
    }
}
