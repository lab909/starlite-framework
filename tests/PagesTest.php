<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Starlite\Kernel;

final class PagesTest extends FrameworkTestCase
{
    /**
     * The fixture content plus extra files under content/pages/.
     *
     * @param array<string, string> $files
     */
    private function withPages(array $files, bool $debug = true): Kernel
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        self::write($content . '/pages', $files);

        return $this->kernel($debug, ['content_dir' => $content, 'cache_dir' => $this->tempDir('cache')]);
    }

    public function testPagesAreCompiledFromTheirFolders(): void
    {
        $pages = $this->kernel()->pages();

        self::assertSame(['contact', 'legal', 'legal/privacy'], array_column($pages->all(), 'path'), 'sorted by path: parents first');
        $privacy = $pages->where('path', 'legal/privacy')->one();
        self::assertNotNull($privacy);
        self::assertSame(
            ['privacy', 'legal', 2, 'Privacy', 'How we handle data.', '/media/pages/legal/privacy/shield.png', null, null, null, '2026-09-15', []],
            [$privacy['slug'], $privacy['parent'], $privacy['depth'], $privacy['title'], $privacy['summary'], $privacy['image'], $privacy['template'], $privacy['form'], $privacy['order'], $privacy['updated'], $privacy['data']],
        );
        self::assertStringContainsString('<img src="/media/pages/legal/privacy/shield.png" alt="Shield" width="4" height="4" loading="lazy" decoding="async">', $privacy['html']);
        self::assertStringContainsString('href="/media/pages/legal/privacy/policy.pdf"', $privacy['html']);
        self::assertSame(['policy.pdf', 'shield.png'], $privacy['assets'], 'notes.txt is not a publishable type');
        $legal = $pages->where('path', 'legal')->one();
        self::assertNotNull($legal);
        self::assertSame(['Everything legal.', ''], [$legal['summary'], $legal['parent']], 'summary: the first paragraph by default; top-level parent: ""');
    }

    public function testTranslationsKeepWhatTheyOmitAndUntranslatedPagesDoNotExist(): void
    {
        $app = $this->kernel();
        $contact = $app->pages()->language('it')->where('path', 'contact')->one();

        self::assertSame(['Contatti', 'custom-page.twig', 1, ['form_title' => 'Scrivici', 'success' => 'Grazie!']], [$contact['title'] ?? null, $contact['template'] ?? null, $contact['order'] ?? null, $contact['data'] ?? null]);
        self::assertSame(['chi-siamo', 'contact', 'legal'], array_column($app->pages()->language('it')->all(), 'path'), 'privacy has no Italian version');
        self::assertSame(['it'], $app->pages->translations('chi-siamo'));
        self::assertSame(['en', 'it'], $app->pages->translations('legal'));
    }

    public function testDataIsInheritedAndPathsSortParentsBeforeTheirChildren(): void
    {
        $app = $this->withPages([
            'b/index.md' => "---\ntitle: B\ndata: {cta: Buy}\n---\n",
            'b/index.it.md' => "---\ntitle: Bi\n---\n",
            'a/z/index.md' => "---\ntitle: Z\n---\n",
            'a/index.md' => "---\ntitle: A\n---\n",
        ]);

        self::assertSame(['cta' => 'Buy'], $app->pages()->language('it')->where('path', 'b')->one()['data'] ?? null);
        self::assertSame(['a', 'a/z', 'b', 'contact', 'legal', 'legal/privacy'], array_column($app->pages()->all(), 'path'));
    }

    public function testTranslatedSlugsGiveEachLanguageItsOwnUrl(): void
    {
        $app = $this->withPages([
            'company/index.md' => "---\ntitle: About\n---\n",
            'company/index.it.md' => "---\ntitle: Azienda\nslug: azienda\n---\n",
            'company/team/index.md' => "---\ntitle: Team\n---\n",
            'company/team/index.it.md' => "---\ntitle: Squadra\nslug: squadra\n---\n",
            'company/history/index.md' => "---\ntitle: History\n---\n",
            'company/history/index.it.md' => "---\ntitle: Storia\n---\n",
            'shop/index.md' => "---\ntitle: Shop\n---\n",
            'shop/gifts/index.it.md' => "---\ntitle: Regali\nslug: regali\n---\n",
        ]);

        $it = $app->pages()->language('it');
        self::assertSame(
            ['company' => 'azienda', 'company/history' => 'azienda/history', 'company/team' => 'azienda/squadra', 'shop/gifts' => 'shop/regali'],
            array_column(array_filter($it->all(), static fn (array $page) => !in_array($page['path'], ['contact', 'legal', 'chi-siamo'], true)), 'uri', 'path'),
            'parents\' translated slugs combine; an untranslated segment keeps its folder name',
        );
        self::assertSame('company/team', $app->pages()->where('uri', 'company/team')->one()['path'] ?? null, 'English uri is the folder path');

        // path() takes the identity (folder path) and writes each language's URL.
        self::assertSame('/it/azienda/squadra', $app->path('page', ['path' => 'company/team'], 'it'));
        self::assertSame('/company/team', $app->path('page', ['path' => 'company/team'], 'en'));

        $html = $this->body($this->request($app, '/it/azienda/squadra'));
        self::assertStringContainsString('<h1>Squadra</h1>', $html);
        self::assertStringContainsString('hreflang="en" href="https://example.test/company/team"', $html, 'alternates link each version to its own URL');
        self::assertStringContainsString('<link rel="canonical" href="https://example.test/it/azienda/squadra">', $html);

        // The untranslated URL moved: a permanent redirect, not a 404.
        $moved = $this->request($app, '/it/company/team');
        self::assertSame(301, $moved->getStatusCode());
        self::assertSame('/it/azienda/squadra', $moved->headers->get('Location'));
        self::assertSame(404, $this->request($app, '/azienda')->getStatusCode(), 'the Italian URL is not an English one');

        self::assertStringContainsString('<loc>https://example.test/it/azienda/squadra</loc>', $this->body($this->request($app, '/sitemap.xml')));
    }

    public function testPagesRenderWithTheirTemplateAndSeo(): void
    {
        $app = $this->kernel();

        $html = $this->body($this->request($app, '/legal/privacy'));
        self::assertStringContainsString('<article class="content-page"><h1>Privacy</h1>', $html);
        self::assertStringContainsString('<title>Privacy · Fixture</title>', $html);
        self::assertStringContainsString('<meta name="description" content="How we handle data.">', $html);
        self::assertStringContainsString('<meta property="og:image" content="https://example.test/media/pages/legal/privacy/shield.png">', $html);
        self::assertStringContainsString('"@type":"WebPage"', $html);
        self::assertStringNotContainsString('hreflang="it"', $html, 'no Italian version: no alternate');

        $contact = $this->body($this->request($app, '/contact'));
        self::assertStringContainsString('<article class="custom-page"><h1>Contact</h1>', $contact, 'template from the front matter');
        self::assertStringContainsString('<h2>Write us</h2>', $contact, 'data for the template');
        self::assertStringContainsString('<h2>Scrivici</h2>', $this->body($this->request($app, '/it/contact')));
        self::assertStringContainsString('hreflang="it" href="https://example.test/it/contact"', $contact);

        self::assertSame(404, $this->request($app, '/chi-siamo')->getStatusCode());
        self::assertSame(200, $this->request($app, '/it/chi-siamo')->getStatusCode());
    }

    public function testUnknownUrlsAreStillThePlain404(): void
    {
        $response = $this->request($this->kernel(), '/nope/missing');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<p class="message">Not found.</p>', $this->body($response));
    }

    public function testMenusAndChildrenAreQueries(): void
    {
        $pages = $this->kernel()->pages();

        self::assertSame(['contact', 'legal'], array_column($pages->where('parent', '')->orderBy('order, title')->all(), 'path'));
        self::assertSame(['legal/privacy'], array_column($pages->where('parent', 'legal')->all(), 'path'));
        self::assertSame(['legal/privacy'], array_column($pages->search('handle data')->all(), 'path'));
    }

    public function testOtherRoutesWinOverPagesWhateverTheOrderAddedAndInProduction(): void
    {
        foreach ([true, false] as $debug) {
            $app = $this->kernel($debug, ['cache_dir' => $this->tempDir('cache')]);
            $app->get('/contact', static fn () => 'the app route', 'contact_route'); // added after the catch-all

            self::assertSame('the app route', $this->body($this->request($app, '/contact')), $debug ? 'debug' : 'production (compiled routes)');
        }
    }

    public function testFilesAreServedAndPublished(): void
    {
        $app = $this->kernel();

        $response = $this->request($app, '/media/pages/legal/privacy/shield.png');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame(404, $this->request($app, '/media/pages/legal/privacy/notes.txt')->getStatusCode());
        self::assertSame(404, $this->request($app, '/media/pages/legal/privacy/../../contact/index.md')->getStatusCode());

        $public = $this->tempDir('public');
        self::assertSame(2 + count($app->images->formats), $app->pages->publishAssets($public), 'the PDF, the image, and its versions');
        self::assertFileExists($public . '/media/pages/legal/privacy/shield.png');
        self::assertFileDoesNotExist($public . '/media/pages/legal/privacy/notes.txt');
    }

    public function testSitemapListsThePagesPerLanguage(): void
    {
        $xml = $this->body($this->request($this->kernel(), '/sitemap.xml'));

        self::assertStringContainsString('<url><loc>https://example.test/legal/privacy</loc><lastmod>2026-09-15</lastmod></url>', $xml);
        self::assertStringContainsString('<loc>https://example.test/it/chi-siamo</loc>', $xml);
        self::assertStringNotContainsString('<loc>https://example.test/chi-siamo</loc>', $xml);
    }

    public function testPagesBehindAnotherRouteAreReported(): void
    {
        // The fixture app has an /about route: content/pages/about/ could never be shown.
        $app = $this->withPages(['about/index.md' => "---\ntitle: About\n---\n"]);

        self::assertSame(['about' => 'about'], $app->shadowedPages());

        // A translated slug can collide with a route too: /it/json is answered by the fixture's json route.
        $translated = $this->withPages(['x/index.md' => "---\ntitle: X\n---\n", 'x/index.it.md' => "---\ntitle: X\nslug: json\n---\n"]);
        self::assertSame(['json' => 'json'], $translated->shadowedPages());
        self::assertSame([], $this->kernel()->shadowedPages());
    }

    public function testProductionCompilesOnceIntoTheCache(): void
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        $cache = $this->tempDir('cache');
        $app = $this->kernel(false, ['content_dir' => $content, 'cache_dir' => $cache]);

        self::assertSame([4, 6], $app->pages->warmup(), '4 pages in 6 language versions');
        self::assertFileExists($cache . '/pages.php');
        unlink($content . '/pages/legal/privacy/index.md');
        self::assertTrue($this->kernel(false, ['content_dir' => $content, 'cache_dir' => $cache])->pages()->where('path', 'legal/privacy')->exists(), 'served from the cache until the next deploy');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidPages(): iterable
    {
        yield 'home page' => [['index.md' => "---\ntitle: Home\n---\n"], 'pages/index.md: the home page is a route'];
        yield 'folder without index' => [['images/a.png' => 'x'], 'pages/images/: every folder in content/pages/ is a page and needs an index.md'];
        yield 'bad folder name' => [['About_Us/index.md' => "---\ntitle: A\n---\n"], 'pages/About_Us/: page folders use lowercase letters, digits and dashes'];
        yield 'invalid yaml' => [['x/index.md' => "---\ntitle: X\nsummary: Note: this breaks\n---\n"], 'pages/x/index.md: invalid YAML front matter:'];
        yield 'no front matter' => [['x/index.md' => 'Just text'], 'pages/x/index.md: missing YAML front matter'];
        yield 'missing title' => [['x/index.md' => "---\nsummary: S\n---\n"], 'pages/x/index.md: front matter needs a "title"'];
        yield 'unknown key' => [['x/index.md' => "---\ntitle: X\ncolour: red\n---\n"], 'unknown front matter "colour" (title, summary, image, template, form, order, updated, cdn, data, slug; put anything else under "data")'];
        yield 'bad template' => [['x/index.md' => "---\ntitle: X\ntemplate: ../secret.twig\n---\n"], '"template" must be a template path such as pages/contact.twig'];
        yield 'order' => [['x/index.md' => "---\ntitle: X\norder: first\n---\n"], '"order" must be a whole number'];
        yield 'form' => [['x/index.md' => "---\ntitle: X\nform: [contact]\n---\n"], '"form" must be the name of a form in config/forms.php'];
        yield 'data' => [['x/index.md' => "---\ntitle: X\ndata: text\n---\n"], '"data" must be a mapping'];
        yield 'cdn' => [['x/index.md' => "---\ntitle: X\ncdn: no-thanks\n---\n"], '"cdn" is true or false'];
        yield 'updated' => [['x/index.md' => "---\ntitle: X\nupdated: soon\n---\n"], 'front matter needs a "updated" in YYYY-MM-DD format'];
        yield 'language' => [['x/index.fr.md' => "---\ntitle: X\n---\n"], 'pages/x/index.fr.md: language "fr" is not configured'];
        yield 'default code' => [['x/index.en.md' => "---\ntitle: X\n---\n"], 'pages/x/index.en.md: the default language (en) is index.md'];
        yield 'missing file' => [['x/index.md' => "---\ntitle: X\n---\n![a](nope.png)"], 'pages/x/index.md: "nope.png" not found in its folder'];
        yield 'slug in the default language' => [['x/index.md' => "---\ntitle: X\nslug: y\n---\n"], 'pages/x/index.md: "slug" is only for translations'];
        yield 'invalid slug' => [['x/index.md' => "---\ntitle: X\n---\n", 'x/index.it.md' => "---\ntitle: X\nslug: a/b\n---\n"], '"slug" uses lowercase letters, digits and dashes (one URL segment)'];
        yield 'duplicate url' => [['x/index.md' => "---\ntitle: X\n---\n", 'x/index.it.md' => "---\ntitle: X\nslug: contact\n---\n"], '/contact is already the URL of pages/'];
        yield 'file name' => [['x/index.md' => "---\ntitle: X\n---\n", 'x/bad name.png' => 'x'], 'file names may only use letters, digits, dots, dashes and underscores'];
    }

    /** @param array<string, string> $files */
    #[DataProvider('invalidPages')]
    public function testInvalidPagesFailLoudly(array $files, string $message): void
    {
        $app = $this->withPages($files);

        $this->expectExceptionMessage($message);
        $app->pages()->all();
    }
}
