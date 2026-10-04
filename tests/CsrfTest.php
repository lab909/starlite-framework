<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

final class CsrfTest extends FrameworkTestCase
{
    /** @return iterable<string, array{array<string, string>, int}> */
    public static function requests(): iterable
    {
        yield 'no origin information' => [[], 403];
        yield 'same-origin fetch' => [['Sec-Fetch-Site' => 'same-origin'], 200];
        yield 'cross-site fetch' => [['Sec-Fetch-Site' => 'cross-site'], 403];
        yield 'same-site but other subdomain' => [['Sec-Fetch-Site' => 'same-site'], 403];
        yield 'matching Origin' => [['Origin' => 'http://localhost'], 200];
        yield 'foreign Origin' => [['Origin' => 'https://evil.example'], 403];
        yield 'matching Referer' => [['Referer' => 'http://localhost/page'], 200];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('requests')]
    public function testUnsafeRequestsMustComeFromThisSite(array $headers, int $status): void
    {
        $response = $this->request($this->kernel(), '/action', 'POST', $headers);

        self::assertSame($status, $response->getStatusCode());
        if ($status === 200) {
            self::assertSame('action ok', self::body($response));
        }
    }

    public function testNoTokenCookieOrSessionIsInvolved(): void
    {
        $response = $this->request($this->kernel(), '/action', 'POST', ['Sec-Fetch-Site' => 'same-origin']);

        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    public function testSafeMethodsAreNotChecked(): void
    {
        self::assertSame(200, $this->request($this->kernel(), '/about', 'GET', ['Sec-Fetch-Site' => 'cross-site'])->getStatusCode());
    }

    public function testRoutesCanOptOut(): void
    {
        self::assertSame('open ok', self::body($this->request($this->kernel(), '/open', 'POST')));
    }
}
