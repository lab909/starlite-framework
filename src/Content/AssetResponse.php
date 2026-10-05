<?php

declare(strict_types=1);

namespace Starlite\Content;

use Starlite\Blog\Blog;
use Starlite\Images\Images;
use Starlite\Kernel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a post's or page's file in development (deploy publishes them to public/media/ for the web
 * server). Images are served as published: the original without its metadata, and the responsive
 * versions (team.jpg.960w.webp), made on first request. Only files a post or page lists are served:
 * `$find` maps the URL's file name to one, or null.
 */
final class AssetResponse
{
    /** @param \Closure(string): ?string $find */
    public static function for(Kernel $app, string $file, \Closure $find): Response
    {
        $variant = Images::parseVariant($file);
        if ($variant !== null) {
            [$original, $width, $token, $format] = $variant;
            $path = $find($original);
            if ($path === null) {
                return $app->error(404, $app->t('Not found.'));
            }
            try {
                return self::send($app, $app->images->variant($path, $width, $format, $token), "image/{$format}");
            } catch (\InvalidArgumentException) {
                return $app->error(404, $app->t('Not found.')); // a width or format this image doesn't have
            }
        }

        $path = $find($file);
        if ($path === null) {
            return $app->error(404, $app->t('Not found.'));
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $response = self::send($app, Images::isRaster($path) ? $app->images->original($path) : $path, Blog::ASSET_TYPES[$extension]);
        if ($extension === 'svg') {
            // An SVG opened directly could run scripts on this origin: sandbox it.
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        return $response;
    }

    private static function send(Kernel $app, string $path, string $type): Response
    {
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $type], true, null, true, true);

        return $response->setPublic()->setMaxAge($app->debug ? 0 : 3600);
    }
}
