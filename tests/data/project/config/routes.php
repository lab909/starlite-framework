<?php

declare(strict_types=1);

use Starlite\Blog\AssetController;
use Starlite\Blog\Blog;
use Starlite\Blog\FeedController;
use Starlite\Blog\PostSeo;
use Starlite\Kernel;
use Starlite\Seo\RobotsController;
use Starlite\Seo\SitemapController;
use Starlite\Tests\Fixtures\Controller\DemoController;
use Starlite\Tests\Fixtures\Controller\InvokableController;

return static function (Kernel $app): void {
    $app->get('/', static fn () => $app->render('page.twig', ['heading' => $app->t('Welcome')]), 'home');
    $app->get('/about', static fn () => $app->render('page.twig', ['heading' => 'About']), 'about');
    $app->get('/hello/{name}', [DemoController::class, 'hello'], 'hello', ['name' => '[a-z]+']);
    $app->get('/invokable', InvokableController::class, 'invokable');
    $app->get('/json', [DemoController::class, 'data'], 'json');
    $app->get('/service', [DemoController::class, 'service'], 'service');
    $app->get('/boom', static fn () => throw new RuntimeException('secret failure detail'), 'boom');
    $app->post('/action', static fn () => 'action ok', 'action');
    $app->route(['POST'], '/open', static fn () => 'open ok', 'open', csrf: false);

    // Minimal blog pages, built like an app's BlogController.
    $app->get('/blog', static fn () => $app->render('list.twig', ['result' => $app->posts()->paginate(1)]), 'blog');
    $app->get('/blog/feed.xml', FeedController::class, 'blog_feed');
    $app->get('/blog/{slug}', static function (string $slug) use ($app) {
        $alternates = [];
        foreach ($app->blog->translations($slug) as $language) {
            $alternates[$language] = $app->path('blog_post', ['slug' => $slug], $language);
        }
        $app->site->setAlternates($alternates);
        $post = $app->posts()->slug($slug)->one();
        if ($post === null) {
            return $app->error(404, 'Post not found.');
        }
        PostSeo::apply($app->seo, $post);

        return $app->render('post.twig', ['post' => $post]);
    }, 'blog_post', ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*']);
    $app->get(Blog::ASSET_URL . '/{slug}/{file}', AssetController::class, 'blog_asset', ['file' => '.+']);

    $app->get('/sitemap.xml', SitemapController::class, 'sitemap');
    $app->get('/robots.txt', RobotsController::class, 'robots');
};
