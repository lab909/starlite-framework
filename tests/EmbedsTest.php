<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Content\Embeds;
use Starlite\Kernel;

final class EmbedsTest extends FrameworkTestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0fake-jpeg";

    /** @var list<string> URLs the fake downloader was asked for */
    private array $requested = [];

    /** @param array<string, string> $responses URL prefix => body; anything else fails */
    private function embeds(array $responses, bool $debug = false, ?string $public = null): Embeds
    {
        return new Embeds($public ?? $this->tempDir('public'), $debug, function (string $url) use ($responses): ?string {
            $this->requested[] = $url;
            foreach ($responses as $prefix => $body) {
                if (str_starts_with($url, $prefix)) {
                    return $body;
                }
            }

            return null;
        });
    }

    public function testIdsAreChecked(): void
    {
        $embeds = $this->embeds([]);

        self::assertNull($embeds->check('youtube', ['id' => 'aqz-KE-bpKQ']));
        self::assertNull($embeds->check('vimeo', ['id' => '1084537', 'start' => 90]));
        self::assertStringContainsString('the 11 characters after watch?v=', (string) $embeds->check('youtube', ['id' => 'https://youtu.be/aqz-KE-bpKQ']));
        self::assertStringContainsString('needs id', (string) $embeds->check('youtube', []));
        self::assertStringContainsString('vimeo.com/123456789', (string) $embeds->check('vimeo', ['id' => 'abc']));
        self::assertStringContainsString('start is a number of seconds', (string) $embeds->check('youtube', ['id' => 'aqz-KE-bpKQ', 'start' => '1:30']));
    }

    public function testYoutubePosterAndTitleAreDownloadedLargestFirst(): void
    {
        $public = $this->tempDir('public');
        $embeds = $this->embeds([
            'https://www.youtube.com/oembed' => '{"title":"Big Buck Bunny"}',
            'https://i.ytimg.com/vi/aqz-KE-bpKQ/maxresdefault.jpg' => '<html>not found</html>', // not an image: skipped
            'https://i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg' => self::JPEG,
        ], public: $public);

        self::assertTrue($embeds->fetch('youtube', 'aqz-KE-bpKQ'));
        self::assertSame(self::JPEG, file_get_contents($public . '/media/embeds/youtube-aqz-KE-bpKQ.jpg'));
        $video = $embeds->video('youtube', 'aqz-KE-bpKQ', 90);
        self::assertSame(
            ['Big Buck Bunny', '/media/embeds/youtube-aqz-KE-bpKQ.jpg', 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1&rel=0&start=90', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ&t=90s', 'https://www.youtube-nocookie.com'],
            [$video['title'], $video['poster'], $video['src'], $video['url'], $video['player']],
        );
        self::assertFalse($embeds->missing('youtube', 'aqz-KE-bpKQ'));
    }

    public function testVimeoUsesItsOembedThumbnailAndDoNotTrack(): void
    {
        $embeds = $this->embeds([
            'https://vimeo.com/api/oembed.json' => '{"title":"Bunny","thumbnail_url":"https://i.vimeocdn.com/video/1-d_1280"}',
            'https://i.vimeocdn.com/video/1-d_1280' => "RIFF\x00\x00\x00\x00WEBPfake",
        ]);

        self::assertTrue($embeds->fetch('vimeo', '1084537'));
        $video = $embeds->video('vimeo', '1084537');
        self::assertSame(['Bunny', '/media/embeds/vimeo-1084537.webp', 'https://player.vimeo.com/video/1084537?autoplay=1&dnt=1'], [$video['title'], $video['poster'], $video['src']]);
    }

    public function testNothingButImagesIsSavedAndFailuresAreRemembered(): void
    {
        $public = $this->tempDir('public');
        $embeds = $this->embeds(['https://vimeo.com/api/oembed.json' => '{"thumbnail_url":"http://insecure.test/x.jpg"}'], public: $public);

        self::assertFalse($embeds->fetch('vimeo', '1084537'));
        self::assertSame([], array_filter($this->requested, static fn (string $url) => str_starts_with($url, 'http://')), 'an http thumbnail URL is never requested');
        self::assertSame(['vimeo-1084537.json'], array_values(array_diff((array) scandir($public . '/media/embeds'), ['.', '..'])));
        self::assertNull($embeds->video('vimeo', '1084537')['poster']);
        self::assertTrue($embeds->missing('vimeo', '1084537'), 'deploy tries again');
    }

    public function testDevelopmentFetchesOnceAndProductionNever(): void
    {
        $embeds = $this->embeds([], debug: true);
        $embeds->video('youtube', 'aqz-KE-bpKQ');
        $embeds->video('youtube', 'aqz-KE-bpKQ');
        self::assertCount(3, $this->requested, 'oembed + two poster sizes, once: the failure is remembered');

        $this->requested = [];
        $this->embeds([], debug: false)->video('youtube', 'aqz-KE-bpKQ');
        self::assertSame([], $this->requested, 'production only uses what deploy downloaded');

        $this->expectExceptionMessage('Invalid YouTube video id "nope".');
        $embeds->video('youtube', 'nope');
    }

    /** A copy of the fixture project with a post holding $markdown; the posters are seeded (no network). */
    private function siteWith(string $markdown): Kernel
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        $content = $this->copyToTemp(self::CONTENT, 'content');
        self::write($content, ['blog/2026/09/video/index.md' => "---\ntitle: Video\ndate: 2026-09-10\n---\nWatch:\n\n{$markdown}\n"]);
        self::write($root, [
            'public/media/embeds/youtube-aqz-KE-bpKQ.json' => '{"title":"Big Buck Bunny","poster":"youtube-aqz-KE-bpKQ.jpg"}',
            'public/media/embeds/vimeo-1084537.json' => '{"title":null,"poster":null}',
        ]);

        return $this->kernel(true, ['content_dir' => $content, 'cache_dir' => $this->tempDir('cache')], $root);
    }

    public function testTheDefaultComponentsRenderClickToLoad(): void
    {
        $response = $this->request($this->siteWith("::youtube{id=\"aqz-KE-bpKQ\" start=90}\n\n::vimeo{id=\"1084537\" title=\"Bunny on Vimeo\"}"), '/blog/video');
        $html = $this->body($response);

        // From the framework's templates (the fixture site has no youtube.twig of its own).
        self::assertStringContainsString('data-src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1&amp;rel=0&amp;start=90"', $html);
        self::assertStringNotContainsString(' src="https://www.youtube', $html, 'no src until play');
        self::assertStringContainsString('<img src="/media/embeds/youtube-aqz-KE-bpKQ.jpg"', $html);
        self::assertStringContainsString('aria-label="Play video: Big Buck Bunny"', $html, 'the downloaded title');
        self::assertStringContainsString('<a href="https://vimeo.com/1084537"', $html, 'without JavaScript: a link to the video');
        self::assertStringContainsString('aria-label="Play video: Bunny on Vimeo"', $html, 'a title argument wins');
        self::assertStringContainsString('bg-gradient-to-br', $html, 'no poster: a neutral one');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com", $csp, 'the players, on this page');
        self::assertStringNotContainsString('frame-src', (string) $this->request($this->siteWith('No video.'), '/blog/video')->headers->get('Content-Security-Policy'), 'and only there');
    }

    public function testABadIdFailsWhenContentCompiles(): void
    {
        $app = $this->siteWith('::youtube{id="https://youtu.be/aqz-KE-bpKQ"}');

        $this->expectExceptionMessage('2026/09/video/index.md: "::youtube{id="https://youtu.be/aqz-KE-bpKQ"}": needs id="…", the 11 characters after watch?v=');
        $app->posts()->all();
    }

    public function testComponentsCanAllowSourcesForTheirPageOnly(): void
    {
        $app = $this->kernel();
        $app->get('/audio', fn () => $app->twig->createTemplate("{% do csp_allow('media-src', 'https://cdn.example.test') %}<p>x</p>")->render(), 'audio');

        self::assertStringContainsString("media-src 'self' https://cdn.example.test", (string) $this->request($app, '/audio')->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('cdn.example.test', (string) $this->request($app, '/')->headers->get('Content-Security-Policy'), 'reset for the next response');
        $this->expectException(\InvalidArgumentException::class);
        $app->cspAllow('media-src', 'https://a.test; script-src *');
    }
}
