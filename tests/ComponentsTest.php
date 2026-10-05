<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Starlite\Kernel;

final class ComponentsTest extends FrameworkTestCase
{
    private const POST = "---\ntitle: With a component\ndate: 2026-09-10\n---\nBefore.\n\n%s\n\nAfter.\n";

    /**
     * The fixture content plus extra files (paths relative to content/).
     *
     * @param array<string, string>      $files
     * @param array<string, array<mixed>> $collections
     */
    private function app(array $files, array $collections = []): Kernel
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        self::write($content, $files);

        return $this->kernel(true, ['content_dir' => $content, 'cache_dir' => $this->tempDir('cache'), 'collections' => $collections]);
    }

    /** @param array<string, string> $more */
    private function postWith(string $markdown, array $more = []): Kernel
    {
        return $this->app(['blog/2026/09/comp/index.md' => sprintf(self::POST, $markdown)] + $more);
    }

    public function testAComponentLineIsRecordedWithItsArguments(): void
    {
        $post = $this->postWith('::greeting{text="Hi \"you\"" count=3 ratio=1.5 loud=true off=false}')->posts()->slug('comp')->one();
        self::assertNotNull($post);

        self::assertSame([['name' => 'greeting', 'args' => ['text' => 'Hi "you"', 'count' => 3, 'ratio' => 1.5, 'loud' => true, 'off' => false]]], $post['components']);
        self::assertSame("<p>Before.</p>\n<!--starlite-component:0-->\n<p>After.</p>\n", $post['html'], 'the compiled HTML keeps a placeholder');
        self::assertSame('Before.', $post['summary']);
    }

    public function testTemplatesRenderThemPerRequestWithTheEntry(): void
    {
        $app = $this->postWith('::greeting{text="Hi \"you\"" count=3 loud=true}', [
            'blog/2026/09/comp/index.it.md' => "---\ntitle: Con un componente\n---\n::greeting{text=\"Ciao\"}\n",
        ]);

        self::assertStringContainsString(
            '<p>Before.</p>' . "\n" . '<aside class="greeting">Hi &quot;you&quot; · With a component (3!) /blog</aside>' . "\n\n" . '<p>After.</p>',
            $this->body($this->request($app, '/blog/comp')),
        );
        self::assertStringContainsString(
            '<aside class="greeting">Ciao · Con un componente (0) /it/blog</aside>',
            $this->body($this->request($app, '/it/blog/comp')),
            'the Italian version as entry, links in Italian',
        );
    }

    public function testCodeIsNeverAComponent(): void
    {
        $post = $this->postWith("```\n::greeting{text=\"in a fence\"}\n```\n\n    ::greeting{text=\"indented\"}\n\nInline `::greeting` and text ::greeting.")->posts()->slug('comp')->one();
        self::assertNotNull($post);

        self::assertSame([], $post['components']);
        self::assertStringContainsString('<pre><code>::greeting{text=&quot;in a fence&quot;}', $post['html']);
        self::assertStringContainsString('<code>::greeting</code> and text ::greeting.', $post['html']);
    }

    public function testEachFileHasItsOwnComponentsAndUpToThreeSpacesAreAllowed(): void
    {
        $app = $this->postWith('  ::greeting{text="first"}', [
            'blog/2026/09/comp2/index.md' => sprintf(self::POST, '::greeting{text="second"}'),
        ]);

        self::assertSame([['name' => 'greeting', 'args' => ['text' => 'first']]], $app->posts()->slug('comp')->one()['components'] ?? null);
        self::assertSame([['name' => 'greeting', 'args' => ['text' => 'second']]], $app->posts()->slug('comp2')->one()['components'] ?? null);
    }

    public function testComponentsWorkInPagesAndCollectionBodies(): void
    {
        $app = $this->app([
            'pages/hello/index.md' => "---\ntitle: Hello page\n---\n::greeting{text=\"On a page\"}\n",
            'faq/q.md' => "---\nquestion: Why?\n---\n::greeting{text=\"In a collection\"}\n",
        ], ['faq' => ['fields' => ['question' => 'string']]]);

        self::assertStringContainsString('<aside class="greeting">On a page · Hello page (0) /blog</aside>', $this->body($this->request($app, '/hello')));
        $item = $app->collection('faq')->slug('q')->one();
        self::assertNotNull($item);
        self::assertStringContainsString('<aside class="greeting">In a collection ·  (0) /blog</aside>', $app->content($item), 'a collection item has no title: entry.title is empty');
    }

    public function testTheFeedShowsALinkInstead(): void
    {
        $xml = $this->body($this->request($this->postWith('::greeting{text="Hi"}'), '/blog/feed.xml'));

        self::assertStringContainsString('&lt;p&gt;&lt;a href=&quot;https://example.test/blog/comp&quot;&gt;Open the page to see this part.&lt;/a&gt;&lt;/p&gt;', $xml);
        self::assertStringNotContainsString('greeting', $xml);
    }

    public function testContentWithoutComponentsIsTheHtml(): void
    {
        $app = $this->kernel();
        $post = $app->posts()->slug('alpha')->one();
        self::assertNotNull($post);

        self::assertSame($post['html'], $app->content($post));
        self::assertSame('<p>x</p>', $app->content(['html' => '<p>x</p>']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidComponents(): iterable
    {
        yield 'unknown component' => ['::audio-player{playlist="x"}', '2026/09/comp/index.md: unknown component "audio-player" in "::audio-player{playlist="x"}": there is no templates/_components/audio-player.twig.'];
        yield 'expression' => ['::greeting{text=page.title}', 'arguments are key="text", key=12 or key=true, separated by spaces (near "text=page.title")'];
        yield 'single quotes' => ["::greeting{text='hi'}", 'arguments are key="text"'];
        yield 'unclosed' => ['::greeting{text="hi"', 'a component is ::name or ::name{key="value" …}'];
        yield 'bad name' => ['::Greeting_x', 'a component is ::name or ::name{key="value" …}, with a name of lowercase letters, digits and dashes'];
        yield 'entry' => ['::greeting{entry="x"}', '"entry" is the post or page the component is in, set by Starlite'];
        yield 'twice' => ['::greeting{text="a" text="b"}', '"text" is given twice'];
    }

    #[DataProvider('invalidComponents')]
    public function testInvalidComponentsFailWithTheFileName(string $markdown, string $message): void
    {
        $app = $this->postWith($markdown);

        $this->expectExceptionMessage($message);
        $app->posts()->all();
    }

    public function testAComponentInAMarkdownFieldIsRefused(): void
    {
        $content = $this->tempDir('content');
        self::write($content, ['team/ada.yaml' => "bio: \"::greeting{text=\\\"x\\\"}\"\n"]);
        $app = $this->kernel(true, ['content_dir' => $content, 'collections' => ['team' => ['fields' => ['bio' => 'markdown']]], 'cache_dir' => $this->tempDir('cache')]);

        $this->expectExceptionMessage('team/ada.yaml: field "bio": components (::name) only work in the Markdown body, not in fields.');
        $app->collection('team')->all();
    }

    public function testTemplateLookupSiteThenPackagesThenFramework(): void
    {
        $package = $this->tempDir('package');
        self::write($package, [
            '_components/greeting.twig' => 'package greeting',
            '_components/box.twig' => '<div class="box">{{ text }}</div>',
        ]);
        $app = $this->postWith('::box{text="from a package"}');
        $app->addTemplates($package, 'acme');

        self::assertStringContainsString('<aside class="greeting">', $app->twig->render('_components/greeting.twig', ['text' => '', 'entry' => ['title' => '']]), 'the site overrides the package');
        self::assertSame('<div class="box">from a package</div>', $app->twig->render('_components/box.twig', ['text' => 'from a package']));
        self::assertSame('package greeting', $app->twig->render('@acme/_components/greeting.twig'), 'the original stays reachable for extending');
        self::assertStringContainsString('<div class="box">from a package</div>', $this->body($this->request($app, '/blog/comp')), 'package components count as known');

        $paths = $app->twig->getLoader() instanceof \Twig\Loader\FilesystemLoader ? $app->twig->getLoader()->getPaths() : [];
        self::assertSame([realpath(self::PROJECT . '/templates'), $package, Kernel::TEMPLATES], [realpath($paths[0] ?? ''), $paths[1] ?? null, $paths[2] ?? null], 'the framework stays last');
        self::assertSame([Kernel::TEMPLATES], $app->twig->getLoader() instanceof \Twig\Loader\FilesystemLoader ? $app->twig->getLoader()->getPaths('starlite') : []);
    }
}
