<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Starlite\Blog\Blog;
use Starlite\Site;

final class BlogTest extends FrameworkTestCase
{
    private function site(string $default = 'en'): Site
    {
        $site = new Site('https://example.test', 'Fixture', defaultLanguage: $default, languages: [
            'en' => ['name' => 'English', 'locale' => 'en_US'],
            'it' => ['name' => 'Italiano', 'locale' => 'it_IT'],
        ]);

        return $site;
    }

    private function blog(bool $debug = true, string $content = self::CONTENT, int $perPage = 20, ?Site $site = null): Blog
    {
        return new Blog($content . '/blog', $this->tempDir('cache') . '/blog.php', $debug, $site ?? $this->site(), $perPage);
    }

    // --- Listing, per language ---------------------------------------------------

    public function testPostsAreListedNewestFirstPerLanguage(): void
    {
        $blog = $this->blog(debug: false);

        self::assertSame(['beta', 'alpha'], array_column($blog->query()->language('en')->all(), 'slug'));
        self::assertSame(['alpha', 'gamma'], array_column($blog->query()->language('it')->all(), 'slug'));
    }

    public function testUntranslatedPostsDoNotExistInThatLanguage(): void
    {
        $blog = $this->blog(debug: false);

        self::assertNull($blog->query()->language('it')->slug('beta')->one());
        self::assertNull($blog->query()->language('en')->slug('gamma')->one());
        self::assertSame(['en', 'it'], $blog->translations('alpha'));
        self::assertSame(['en'], $blog->translations('beta'));
        self::assertSame(['it'], $blog->translations('gamma'));
    }

    public function testCurrentLanguageIsTheDefault(): void
    {
        $site = $this->site();
        $blog = $this->blog(debug: false, site: $site);
        $site->enter('it', '/blog');

        self::assertSame('Alfa', $blog->query()->slug('alpha')->one()['title'] ?? null);
    }

    public function testDraftsOnlyExistInDebugMode(): void
    {
        self::assertNull($this->blog(debug: false)->query()->language('en')->slug('delta')->one());

        $draft = $this->blog(debug: true)->query()->language('en')->slug('delta')->one();
        self::assertNotNull($draft);
        self::assertTrue($draft['draft']);
        self::assertSame(gmdate('Y-m-d'), $draft['date'], 'a draft without a date sorts as if published today');
    }

    // --- Front matter -------------------------------------------------------------

    public function testFrontMatterFields(): void
    {
        $post = $this->blog()->query()->language('en')->slug('alpha')->one();

        self::assertNotNull($post);
        self::assertSame('Alpha "quoted" & <b>bold</b>', $post['title']);
        self::assertSame('2026-09-10', $post['date']);
        self::assertSame('2026-09-12', $post['updated']);
        self::assertSame('/media/blog/alpha/cover.png', $post['image']);
        self::assertSame(['php', 'starlite'], $post['tags'], 'tags are lowercased');
        self::assertSame('en', $post['language']);
        self::assertSame('2026/09/alpha/index.md', $post['source']);
    }

    public function testSummaryDefaultsToTheFirstParagraphWithText(): void
    {
        // alpha opens with an image-only paragraph; whitespace and line breaks are collapsed.
        self::assertSame('First paragraph of alpha, with a line break.', $this->blog()->query()->language('en')->slug('alpha')->one()['summary'] ?? null);
    }

    public function testTranslationsInheritWhatTheyOmit(): void
    {
        $post = $this->blog()->query()->language('it')->slug('alpha')->one();

        self::assertNotNull($post);
        self::assertSame('Alfa', $post['title']);
        self::assertSame('Riassunto italiano.', $post['summary']);
        self::assertSame('2026-09-10', $post['date']);
        self::assertSame('2026-09-12', $post['updated']);
        self::assertSame('/media/blog/alpha/cover.png', $post['image']);
        self::assertSame(['php', 'starlite'], $post['tags']);
        self::assertSame('it', $post['language']);
    }

    public function testPostsWrittenOnlyInASecondaryLanguageKeepTheirOwnDate(): void
    {
        self::assertSame('2026-08-05', $this->blog()->query()->language('it')->slug('gamma')->one()['date'] ?? null);
    }

    // --- Markdown -----------------------------------------------------------------

    public function testMarkdownIsRenderedSafely(): void
    {
        $html = $this->blog()->query()->language('en')->slug('alpha')->one()['html'] ?? '';

        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html, 'raw HTML is escaped');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html, 'unsafe links are dropped');
        self::assertStringContainsString('<a rel="noopener noreferrer" target="_blank" href="https://example.org">', $html);
        self::assertStringContainsString('<h2>Section title<a id="section-title" href="#section-title"', $html);
    }

    public function testRelativeLinksPointAtThePostFiles(): void
    {
        $blog = $this->blog();

        self::assertStringContainsString('<img src="/media/blog/alpha/cover.png" alt="Diagram" />', $blog->query()->language('en')->slug('alpha')->one()['html'] ?? '');
        self::assertStringContainsString('href="/media/blog/alpha/files/doc.pdf"', $blog->query()->language('en')->slug('alpha')->one()['html'] ?? '');
        self::assertStringContainsString('<img src="/media/blog/alpha/cover.png" alt="Diagramma" />', $blog->query()->language('it')->slug('alpha')->one()['html'] ?? '');
    }

    public function testOnlyAllowedFileTypesArePublished(): void
    {
        self::assertSame(['cover.png', 'files/doc.pdf', 'icon.svg'], $this->blog()->query()->language('en')->slug('alpha')->one()['assets'] ?? null);
    }

    // --- Search, tags, pages --------------------------------------------------------

    public function testSearchTagsAndPagesArePerLanguage(): void
    {
        $blog = $this->blog(debug: false, perPage: 1);

        $en = $blog->query()->language('en');
        self::assertSame(['beta'], array_column($en->search('searching')->all(), 'slug'), 'matches the summary');
        self::assertSame(['alpha'], array_column($en->tag('php')->all(), 'slug'));
        self::assertSame([], $blog->query()->language('it')->search('beta')->all());
        self::assertEquals(['guide' => 1, 'php' => 1, 'starlite' => 1], $en->countBy('tags'), 'counts per tag; ties in any order');
        self::assertEquals(['guida' => 1, 'php' => 1, 'starlite' => 1], $blog->query()->language('it')->countBy('tags'));

        $page2 = $en->paginate(2);
        self::assertSame(['alpha'], array_column($page2['items'], 'slug'));
        self::assertSame(['page' => 2, 'pages' => 2, 'per_page' => 1, 'total' => 2, 'has_more' => false], array_diff_key($page2, ['items' => 1]));
        self::assertTrue($en->paginate(1)['has_more']);
        self::assertSame([], $en->paginate(9)['items']);
        self::assertSame(['alpha'], array_column($en->tag('php')->paginate(1)['items'], 'slug'));
    }

    // --- Cache and assets ----------------------------------------------------------

    public function testProductionCompilesOnceIntoTheCache(): void
    {
        $cache = $this->tempDir('cache') . '/blog.php';
        $content = $this->copyToTemp(self::CONTENT, 'content');
        $blog = new Blog($content . '/blog', $cache, false, $this->site());

        self::assertSame([3, 4], $blog->warmup(), '3 published posts in 4 language versions');
        self::assertFileExists($cache);

        unlink($content . '/blog/2026/09/beta/index.md');
        $fresh = new Blog($content . '/blog', $cache, false, $this->site());
        self::assertNotNull($fresh->query()->language('en')->slug('beta')->one(), 'served from the cache until the next deploy');
    }

    public function testPublishAssetsCopiesOnlyPublishedPostFiles(): void
    {
        $public = $this->tempDir('public');
        $count = $this->blog(debug: true)->publishAssets($public);

        self::assertSame(3, $count);
        self::assertFileExists($public . '/media/blog/alpha/files/doc.pdf');
        self::assertFileDoesNotExist($public . '/media/blog/alpha/notes.txt');
        self::assertDirectoryDoesNotExist($public . '/media/blog/delta', 'drafts are never published, even in debug');
    }

    // --- Validation: every mistake fails loudly --------------------------------------

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidContent(): iterable
    {
        $post = "---\ntitle: X\ndate: 2026-09-01\n---\nx";

        yield 'file outside a post folder' => [['2026/09/stray.md' => $post], 'posts must be content/blog/YYYY/MM/<slug>/index.md'];
        yield 'invalid slug folder' => [['2026/09/Bad_Slug/index.md' => $post], 'with a slug of lowercase letters, digits and dashes'];
        yield 'invalid month folder' => [['2026/13/thirteen/index.md' => $post], 'posts must be content/blog/YYYY/MM/<slug>/index.md'];
        yield 'date outside its month folder' => [['2026/09/wrong/index.md' => "---\ntitle: X\ndate: 2026-08-15\n---\nx"], 'date 2026-08-15 does not match its YYYY/MM folder'];
        yield 'missing front matter' => [['2026/09/bare/index.md' => 'Just text.'], 'missing YAML front matter'];
        yield 'missing title' => [['2026/09/untitled/index.md' => "---\ndate: 2026-09-01\n---\nx"], 'front matter needs a "title"'];
        yield 'missing date' => [['2026/09/undated/index.md' => "---\ntitle: X\n---\nx"], 'front matter needs a "date" in YYYY-MM-DD format'];
        yield 'malformed date' => [['2026/09/baddate/index.md' => "---\ntitle: X\ndate: 09/01/2026\n---\nx"], 'front matter needs a "date" in YYYY-MM-DD format'];
        yield 'old slug field' => [['2026/09/old/index.md' => "---\ntitle: X\ndate: 2026-09-01\nslug: other\n---\nx"], '"slug" is no longer a front matter field: rename the post folder instead'];
        yield 'old draft field' => [['2026/09/old/index.md' => "---\ntitle: X\ndate: 2026-09-01\ndraft: true\n---\nx"], '"draft" is no longer a front matter field'];
        yield 'invalid yaml' => [['2026/09/yaml/index.md' => "---\ntitle: Note: this breaks\ndate: 2026-09-01\n---\nx"], '2026/09/yaml/index.md: invalid YAML front matter:'];
        yield 'missing linked file' => [['2026/09/img/index.md' => "---\ntitle: X\ndate: 2026-09-01\n---\n![a](nope.png)"], '"nope.png" not found in its folder'];
        yield 'link escaping the folder' => [['2026/09/esc/index.md' => "---\ntitle: X\ndate: 2026-09-01\n---\n![a](../alpha/cover.png)"], 'must stay inside the post folder'];
        yield 'link to unpublishable type' => [['2026/09/zip/index.md' => "---\ntitle: X\ndate: 2026-09-01\n---\n[a](a.zip)", '2026/09/zip/a.zip' => 'x'], 'is not a publishable file type'];
        yield 'missing image' => [['2026/09/noimg/index.md' => "---\ntitle: X\ndate: 2026-09-01\nimage: nope.jpg\n---\nx"], 'image "nope.jpg" not found in its folder'];
        yield 'bad asset file name' => [['2026/09/names/index.md' => $post, '2026/09/names/my photo.jpg' => 'x'], 'file names may only use letters, digits, dots, dashes and underscores'];
        yield 'duplicate slug' => [['2026/08/beta/index.md' => "---\ntitle: X\ndate: 2026-08-01\n---\nx"], 'Duplicate slug "beta"'];
        yield 'default language with a code' => [['2026/09/alpha/index.en.md' => $post], 'the default language (en) is index.md, without a language code'];
        yield 'unconfigured language' => [['2026/09/alpha/index.fr.md' => $post], 'language "fr" is not configured in config/app.php'];
        yield 'translation outside its month' => [['2026/09/beta/index.it.md' => "---\ntitle: X\ndate: 2026-07-01\n---\nx"], 'date 2026-07-01 does not match its YYYY/MM folder'];
        yield 'secondary-language-only post without date' => [['2026/09/solo/index.it.md' => "---\ntitle: X\n---\nx"], 'front matter needs a "date"'];
    }

    /** @param array<string, string> $files */
    #[DataProvider('invalidContent')]
    public function testInvalidContentFailsWithAClearMessage(array $files, string $message): void
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        self::write($content . '/blog', $files);

        $this->expectExceptionMessage($message);
        $this->blog(debug: false, content: $content)->query()->language('en')->all();
    }

    public function testDraftErrorsDoNotBreakProductionBuilds(): void
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        self::write($content . '/blog', ['drafts/broken/index.md' => 'no front matter']);

        self::assertCount(2, $this->blog(debug: false, content: $content)->query()->language('en')->all());
    }

    public function testEditorTempFilesAreIgnored(): void
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        self::write($content . '/blog', ['2026/09/alpha/.index.md.swp' => 'x', '2026/09/.notes.md' => 'x']);

        self::assertCount(2, $this->blog(debug: false, content: $content)->query()->language('en')->all());
    }
}
