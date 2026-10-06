<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Psr\Container\NotFoundExceptionInterface;

final class KernelTest extends FrameworkTestCase
{
    public function testDispatchesClosuresControllerMethodsAndInvokableControllers(): void
    {
        $app = $this->kernel();

        self::assertStringContainsString('<h1>Welcome</h1>', self::body($this->request($app, '/')));
        self::assertSame('hello ada in en', self::body($this->request($app, '/hello/ada')));
        self::assertSame('invoked', self::body($this->request($app, '/invokable')));
    }

    public function testStringResultsAreHtmlAndJsonHelperReturnsJson(): void
    {
        $app = $this->kernel();

        self::assertSame('text/html; charset=utf-8', $this->request($app, '/invokable')->headers->get('Content-Type'));
        $json = $this->request($app, '/json');
        self::assertSame('application/json', $json->headers->get('Content-Type'));
        self::assertSame(['ok' => true, 'path' => '/about'], json_decode(self::body($json), true));
    }

    public function testUnknownPathIsA404ErrorPage(): void
    {
        $response = $this->request($this->kernel(), '/nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<p class="message">Not found.</p>', self::body($response));
        self::assertStringContainsString('<meta name="robots" content="noindex">', self::body($response));
    }

    public function testRouteRequirementsAreEnforced(): void
    {
        self::assertSame(404, $this->request($this->kernel(), '/hello/ADA')->getStatusCode());
    }

    public function testWrongMethodIsA405WithAllowHeader(): void
    {
        $response = $this->request($this->kernel(), '/about', 'POST', ['Sec-Fetch-Site' => 'same-origin']);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testDatastarRequestsGetPlainTextErrors(): void
    {
        $response = $this->request($this->kernel(), '/nope', 'GET', ['Datastar-Request' => 'true']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not found.', self::body($response));
    }

    public function testExceptionsInProductionAreLoggedAndHidden(): void
    {
        $response = $this->request($this->kernel(debug: false), '/boom');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret failure detail', self::body($response));
        self::assertStringContainsString('app.ERROR: secret failure detail', $this->logged());
    }

    public function testExceptionsInDebugAreRethrown(): void
    {
        $this->expectExceptionMessage('secret failure detail');
        $this->request($this->kernel(debug: true), '/boom');
    }

    public function testSecurityHeadersOnEveryResponse(): void
    {
        foreach (['/', '/nope'] as $path) {
            $headers = $this->request($this->kernel(), $path)->headers;
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
            self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
            self::assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'));
        }
    }

    public function testPagesArePubliclyCacheableAndRevalidateWith304(): void
    {
        $app = $this->kernel();
        $first = $this->request($app, '/about');
        $etag = (string) $first->getEtag();

        self::assertNotSame('', $etag);
        self::assertStringContainsString('public', (string) $first->headers->get('Cache-Control'));
        self::assertStringContainsString('no-cache', (string) $first->headers->get('Cache-Control'));
        self::assertFalse($first->headers->has('Set-Cookie'));

        $second = $this->request($app, '/about', 'GET', ['If-None-Match' => $etag]);
        self::assertSame(304, $second->getStatusCode());
        self::assertSame('', self::body($second));
    }

    public function testErrorPagesGetNoEtag(): void
    {
        self::assertNull($this->request($this->kernel(), '/nope')->getEtag());
    }

    public function testProductionModeCompilesTheRouterIntoTheCacheDirectory(): void
    {
        $cache = $this->tempDir('cache');
        $app = $this->kernel(debug: false, overrides: ['cache_dir' => $cache]);

        self::assertSame('hello bob in en', self::body($this->request($app, '/hello/bob')));
        self::assertFileExists($cache . '/routes.matcher.php');
        self::assertSame('/hello/bob', $app->router->generate('hello', ['name' => 'bob']));
        self::assertFileExists($cache . '/routes.generator.php');
    }

    public function testBootstrapRegistersServicesAndTwigGlobals(): void
    {
        $app = $this->kernel();

        self::assertSame('service:Fixture', self::body($this->request($app, '/service')));
        self::assertStringContainsString('<p id="global">from-bootstrap</p>', self::body($this->request($app, '/')));
    }

    public function testContainerFactoriesAreLazyAndShared(): void
    {
        $app = $this->kernel();
        $calls = 0;
        $app->container->set('counter', static function () use (&$calls) {
            ++$calls;

            return new \stdClass();
        });

        self::assertTrue($app->container->has('counter'));
        self::assertSame(0, $calls);
        self::assertSame($app->container->get('counter'), $app->container->get('counter'));
        self::assertSame(1, $calls);

        $app->container->set('value', 42);
        self::assertSame(42, $app->container->get('value'));
    }

    public function testMissingServiceThrowsPsrNotFound(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->kernel()->container->get('missing');
    }
}
