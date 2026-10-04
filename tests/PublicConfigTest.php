<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\PublicConfig;

final class PublicConfigTest extends FrameworkTestCase
{
    public function testOnlyTheAllowlistedValuesReachThePage(): void
    {
        $html = $this->body($this->request($this->kernel(overrides: ['public' => ['media' => 'https://cdn.example.test/']]), '/'));

        self::assertStringContainsString('<script type="application/json" id="starlite-config">{"media":"https://cdn.example.test/"}</script>', $html);
        self::assertStringNotContainsString((string) getenv('APP_SECRET'), $html);
    }

    public function testNothingIsPrintedWithoutPublicValues(): void
    {
        self::assertStringNotContainsString('starlite-config', $this->body($this->request($this->kernel(), '/')));
    }

    public function testValuesCannotCloseTheScriptElement(): void
    {
        $tag = (new PublicConfig(['note' => '</script><script>alert(1)</script> & \'"<!--'], 'secret'))->tag();

        self::assertSame(1, substr_count($tag, '</script>'));
        self::assertStringNotContainsString('<!--', $tag);
        self::assertSame(['note' => '</script><script>alert(1)</script> & \'"<!--'], json_decode(strip_tags($tag), true));
    }

    public function testTheSecretIsRefusedAnywhereInTheValues(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"public.api.key" contains APP_SECRET');

        new PublicConfig(['api' => ['key' => 'prefix-s3cr3t-value']], 's3cr3t-value');
    }
}
