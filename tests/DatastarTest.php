<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Kernel;

final class DatastarTest extends FrameworkTestCase
{
    public function testSignedTemplateUrlRendersThePartialAsServerSentEvents(): void
    {
        $app = $this->kernel();
        $response = $this->request($app, $this->greetUrl($app, '/') . '&datastar=' . rawurlencode('{"mood":"happy"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        self::assertNull($response->getEtag(), 'SSE responses are never cached');

        $events = self::body($response);
        self::assertStringContainsString("event: datastar-patch-elements\ndata: elements <p id=\"greeting\">Hello Ada, happy</p>", $events);
        self::assertStringContainsString("event: datastar-patch-signals\ndata: signals {\"greeted\":true}", $events);
    }

    public function testSignalsArriveInTheBodyForPostRequests(): void
    {
        $app = $this->kernel();
        $config = parse_url($this->greetUrl($app, '/'), PHP_URL_QUERY);
        $response = $this->request($app, '/datastar?' . $config, 'POST', ['Sec-Fetch-Site' => 'same-origin'], '{"mood":"calm"}');

        self::assertStringContainsString('Hello Ada, calm', self::body($response));
    }

    public function testTamperedConfigIsRejected(): void
    {
        $app = $this->kernel();
        $url = $this->greetUrl($app, '/');
        [$payload] = explode('.', (string) parse_url($url, PHP_URL_QUERY));
        $forged = rtrim(strtr(base64_encode('{"t":"_error","v":{"status":1,"message":"x"}}'), '+/', '-_'), '=');

        self::assertSame(400, $this->request($app, $url . 'x')->getStatusCode());
        self::assertSame(400, $this->request($app, '/datastar?config=' . $forged . '.' . substr($url, -64))->getStatusCode());
        self::assertSame(400, $this->request($app, '/datastar?' . $payload)->getStatusCode());
    }

    public function testUrlsKeepTheCurrentLanguage(): void
    {
        $app = $this->kernel();
        $url = $this->greetUrl($app, '/it');

        self::assertStringStartsWith('/it/datastar?config=', $url);
        self::assertStringContainsString('Ciao Ada', self::body($this->request($app, $url)));
    }

    public function testActionsAreDatastarExpressions(): void
    {
        $datastar = $this->kernel()->datastar;

        self::assertSame('@post("/clock")', $datastar->action('POST', '/clock'));
        self::assertSame('@get("/x", {"openWhenHidden":true})', $datastar->action('get', '/x', ['openWhenHidden' => true]));
    }

    /** The greet button's URL, as rendered on a page. */
    private function greetUrl(Kernel $app, string $page): string
    {
        self::assertSame(1, preg_match('#@get\(&quot;([^&]+)&quot;\)#', self::body($this->request($app, $page)), $match), 'greet button not found');

        return html_entity_decode($match[1] ?? '');
    }
}
