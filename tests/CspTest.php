<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Csp;
use Starlite\Theme;

final class CspTest extends FrameworkTestCase
{
    public function testPagesGetAStrictPolicy(): void
    {
        $policy = (string) $this->request($this->kernel(), '/')->headers->get('Content-Security-Policy');

        self::assertStringStartsWith("default-src 'self'; script-src 'self' 'unsafe-eval' " . Theme::hash() . ';', $policy);
        foreach (["object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'", "img-src 'self' data:"] as $directive) {
            self::assertStringContainsString($directive, $policy);
        }
        self::assertStringNotContainsString("'unsafe-inline'; ", explode('style-src-attr', $policy)[0], 'Only style attributes may be inline.');
    }

    public function testOnlyHtmlResponsesGetIt(): void
    {
        $app = $this->kernel();

        self::assertTrue($this->request($app, '/missing')->headers->has('Content-Security-Policy'), 'error pages are HTML too');
        self::assertFalse($this->request($app, '/blog/feed.xml')->headers->has('Content-Security-Policy'));
    }

    public function testConfiguredSourcesAreAdded(): void
    {
        $app = $this->kernel(overrides: ['csp' => ['sources' => [
            'frame-src' => ['https://www.youtube-nocookie.com'],
            'media-src' => 'https://cdn.example.test',
        ]]]);
        $policy = (string) $this->request($app, '/')->headers->get('Content-Security-Policy');

        // A directive the policy didn't list starts from 'self', as it had through default-src.
        self::assertStringContainsString("frame-src 'self' https://www.youtube-nocookie.com", $policy);
        self::assertStringContainsString("media-src 'self' https://cdn.example.test", $policy);
    }

    public function testReportOnlyAndDisabled(): void
    {
        $reportOnly = $this->request($this->kernel(overrides: ['csp' => ['report_only' => true]]), '/');
        self::assertFalse($reportOnly->headers->has('Content-Security-Policy'));
        self::assertTrue($reportOnly->headers->has('Content-Security-Policy-Report-Only'));

        $disabled = $this->request($this->kernel(overrides: ['csp' => ['enabled' => false]]), '/');
        self::assertFalse($disabled->headers->has('Content-Security-Policy'));
        self::assertFalse($disabled->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testAllowScriptAddsTheHashOfExactlyThatCode(): void
    {
        $csp = (new Csp())->allowScript("console.log('hi')");

        self::assertContains("'sha256-" . base64_encode(hash('sha256', "console.log('hi')", true)) . "'", $csp->directives()['script-src']);
    }

    public function testNoneIsReplacedAndSourcesAreNotRepeated(): void
    {
        $csp = (new Csp())->allow('object-src', "'self'")->allow('img-src', "'self'", 'data:');

        self::assertSame(["'self'"], $csp->directives()['object-src']);
        self::assertSame(["'self'", 'data:'], $csp->directives()['img-src']);
    }

    public function testDevServerIsAllowedWhileItRuns(): void
    {
        $policy = (new Csp())->header('https://site.test:5173');

        self::assertStringContainsString("script-src 'self' 'unsafe-eval' " . Theme::hash() . ' https://site.test:5173;', $policy);
        self::assertStringContainsString("style-src 'self' https://site.test:5173 'unsafe-inline';", $policy);
        self::assertStringContainsString("connect-src 'self' https://site.test:5173 wss://site.test:5173;", $policy);
        self::assertStringNotContainsString('5173', (new Csp())->header());
    }

    public function testTyposAndInjectionAreRefused(): void
    {
        foreach ([['frame-scr', 'https://a.test'], ['frame-src', "https://a.test; script-src *"], ['frame-src', 'https://a.test,b'], ['frame-src', 'a b']] as [$directive, $source]) {
            try {
                (new Csp())->allow($directive, $source);
                self::fail("{$directive} {$source} must be refused.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAHandlersOwnPolicyIsKept(): void
    {
        $app = $this->kernel();
        $app->get('/custom', fn () => new \Symfony\Component\HttpFoundation\Response('<p>x</p>', 200, ['Content-Security-Policy' => "default-src 'none'"]));

        self::assertSame("default-src 'none'", $this->request($app, '/custom')->headers->get('Content-Security-Policy'));
    }
}
