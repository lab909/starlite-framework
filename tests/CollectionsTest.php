<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Starlite\Kernel;

final class CollectionsTest extends FrameworkTestCase
{
    private const SCHEMAS = [
        'faq' => [
            'fields' => ['question' => 'string', 'order' => 'int', 'tags' => '?list'],
            'sort' => 'order',
            'json' => ['question'],
        ],
        'team' => [
            'fields' => ['name' => 'string', 'joined' => 'date', 'photo' => '?url', 'rate' => '?float', 'active' => 'bool', 'bio' => '?markdown', 'links' => '?array'],
            'sort' => '-joined',
            'fallback' => true,
        ],
    ];

    private const FILES = [
        'faq/install.md' => "---\nquestion: How do I install it?\norder: 2\ntags: [setup]\n---\nRun **composer**.\n",
        'faq/install.it.md' => "---\nquestion: Come si installa?\n---\nEsegui **composer**.\n",
        'faq/what.md' => "---\nquestion: What is it?\norder: 1\n---\nA framework.\n",
        'faq/later.md' => "---\nquestion: Untranslated?\norder: 3\ntags: [setup, misc]\n---\n",
        'team/ada.yaml' => "name: Ada\njoined: 2024-03-01\nphoto: /images/ada.webp\nrate: 2\nactive: true\nbio: Writes *tests*.\nlinks: {site: 'https://ada.test'}\n",
        'team/ada.it.yaml' => "bio: Scrive *test*.\n",
        'team/bob.yml' => "name: Bob\njoined: '2025-01-15'\nactive: false\n",
    ];

    /**
     * @param array<string, string> $content
     * @param array<string, array<mixed>> $schemas
     */
    private function app(array $content = self::FILES, array $schemas = self::SCHEMAS, bool $debug = true, ?string $cacheDir = null): Kernel
    {
        $dir = $this->tempDir('content');
        self::write($dir, $content);

        return $this->kernel($debug, ['content_dir' => $dir, 'collections' => $schemas, 'cache_dir' => $cacheDir ?? $this->tempDir('cache')]);
    }

    public function testMarkdownAndYamlItemsInTheirConfiguredOrder(): void
    {
        $faq = $this->app()->collection('faq');

        self::assertSame(['what', 'install', 'later'], array_column($faq->all(), 'slug'));
        self::assertSame(3, count($faq));
        $install = $faq->slug('install')->one();
        self::assertSame(['slug' => 'install', 'language' => 'en', 'question' => 'How do I install it?', 'order' => 2, 'tags' => ['setup'], 'html' => '<p>Run <strong>composer</strong>.</p>', 'source' => 'faq/install.md'], $install);
        self::assertNull($faq->slug('what')->one()['tags'] ?? null, 'optional fields are null when missing');
        self::assertSame('', $faq->slug('later')->one()['html'] ?? null);

        $team = $this->app()->collection('team')->all();
        self::assertSame(['bob', 'ada'], array_column($team, 'slug'), 'sorted by -joined: newest first');
        self::assertSame(['2025-01-15', '2024-03-01'], array_column($team, 'joined'), 'quoted and unquoted dates');
        self::assertSame(2.0, $team[1]['rate']);
        self::assertSame("<p>Writes <em>tests</em>.</p>\n", $team[1]['bio']);
        self::assertSame(['site' => 'https://ada.test'], $team[1]['links']);
        self::assertSame('', $team[1]['html'], 'YAML items have no body');
    }

    public function testTranslationsInheritWhatTheyOmitAndUntranslatedItemsAreHidden(): void
    {
        $faq = $this->app()->collection('faq');

        $install = $faq->language('it')->slug('install')->one();
        self::assertSame(['Come si installa?', 2, ['setup'], '<p>Esegui <strong>composer</strong>.</p>', 'it'], [$install['question'] ?? null, $install['order'] ?? null, $install['tags'] ?? null, $install['html'] ?? null, $install['language'] ?? null]);
        self::assertSame(['install'], array_column($faq->language('it')->all(), 'slug'), 'no fallback: untranslated items do not exist in Italian');
    }

    public function testFallbackShowsTheDefaultLanguageVersion(): void
    {
        $team = $this->app()->collection('team');

        self::assertSame(['bob', 'ada'], array_column($team->language('it')->all(), 'slug'));
        self::assertSame('en', $team->language('it')->slug('bob')->one()['language'] ?? null, 'marked as English, for lang=""');
        self::assertSame(['it', "<p>Scrive <em>test</em>.</p>\n", 'Ada'], [$team->language('it')->slug('ada')->one()['language'] ?? null, $team->language('it')->slug('ada')->one()['bio'] ?? null, $team->language('it')->slug('ada')->one()['name'] ?? null]);
    }

    public function testTemplatesUseThemInTheCurrentLanguage(): void
    {
        $app = $this->app();
        $app->get('/faq', fn () => $app->twig->createTemplate(
            '{% for q in collection("faq") %}[{{ q.question }}]{% endfor %} {{ collection("faq").slug("what").one().question ?? "-" }} {{ collection("team")|length }}',
        )->render());

        self::assertSame('[What is it?][How do I install it?][Untranslated?] What is it? 2', $this->body($this->request($app, '/faq')));
        self::assertSame('[Come si installa?] - 2', $this->body($this->request($app, '/it/faq')));
    }

    public function testItemsWithoutTheSortValueComeLastInEitherDirection(): void
    {
        $files = ['faq/a.md' => "---\nquestion: A\norder: 1\n---\n", 'faq/b.md' => "---\nquestion: B\n---\n", 'faq/c.md' => "---\nquestion: C\norder: 2\n---\n"];
        foreach (['priority' => ['a', 'c', 'b'], '-priority' => ['c', 'a', 'b']] as $sort => $expected) {
            $schemas = ['faq' => ['fields' => ['question' => 'string', 'order' => '?int'], 'sort' => str_replace('priority', 'order', $sort)]];
            self::assertSame($expected, array_column($this->app($files, $schemas)->collection('faq')->all(), 'slug'), $sort);
        }
    }

    public function testATranslationWithoutABodyKeepsTheDefaultLanguageBody(): void
    {
        $files = self::FILES + ['faq/what.it.md' => "---\nquestion: Cos'è?\n---\n"];

        self::assertSame(["Cos'è?", '<p>A framework.</p>'], [
            $this->app($files)->collection('faq')->language('it')->slug('what')->one()['question'] ?? null,
            $this->app($files)->collection('faq')->language('it')->slug('what')->one()['html'] ?? null,
        ]);
    }

    public function testWhere(): void
    {
        $faq = $this->app()->collection('faq');

        self::assertSame(['install', 'later'], array_column($faq->where('tags', 'setup')->all(), 'slug'), 'a list field contains the value');
        self::assertSame(['what'], array_column($faq->where('order', 1)->all(), 'slug'));
        $this->expectExceptionMessage('collection "faq": no field "nope". Fields: slug, language, question, order, tags.');
        $faq->where('nope', 1);
    }

    public function testJsonExportServesOnlyTheAllowlistedFields(): void
    {
        $app = $this->app();

        $response = $this->request($app, '/data/faq.json');
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame([
            ['slug' => 'what', 'language' => 'en', 'question' => 'What is it?'],
            ['slug' => 'install', 'language' => 'en', 'question' => 'How do I install it?'],
            ['slug' => 'later', 'language' => 'en', 'question' => 'Untranslated?'],
        ], json_decode($this->body($response), true));
        self::assertSame([['slug' => 'install', 'language' => 'it', 'question' => 'Come si installa?']], json_decode($this->body($this->request($app, '/it/data/faq.json')), true));
        self::assertSame(404, $this->request($app, '/data/team.json')->getStatusCode(), 'no json option: not exported');
        self::assertNotNull($response->getEtag());
    }

    public function testNoJsonRouteWithoutExports(): void
    {
        $schemas = self::SCHEMAS;
        unset($schemas['faq']['json']);

        self::assertSame(404, $this->request($this->app(schemas: $schemas), '/data/faq.json')->getStatusCode());
    }

    public function testProductionCompilesOnceIntoTheCache(): void
    {
        $cache = $this->tempDir('cache');
        $app = $this->app(debug: false, cacheDir: $cache);

        self::assertSame(['faq' => 3, 'team' => 2], $app->collections->warmup());
        self::assertFileExists($cache . '/collections.php');

        // Content changes don't show until the next deploy (warmup), as for the blog.
        $fresh = $this->kernel(false, ['content_dir' => $this->tempDir('empty'), 'collections' => self::SCHEMAS, 'cache_dir' => $cache]);
        self::assertCount(3, $fresh->collection('faq'));
    }

    public function testEmptyAndMissingFoldersAreEmptyCollections(): void
    {
        self::assertCount(0, $this->app(['faq/.gitkeep' => ''])->collection('faq'));
        self::assertCount(0, $this->app([])->collection('team'));
    }

    /** @return iterable<string, array{array<string, string>, string, 2?: array<mixed>}> */
    public static function invalidContent(): iterable
    {
        $faq = "---\nquestion: Q\norder: 1\n---\n";
        yield 'unknown field' => [['faq/a.md' => "---\nquestion: Q\norder: 1\ncolour: red\n---\n"], 'faq/a.md: unknown field "colour". Fields of faq: question, order, tags.'];
        yield 'slug as field' => [['faq/a.md' => "---\nquestion: Q\norder: 1\nslug: b\n---\n"], 'unknown field "slug" (slug and language come from the file name)'];
        yield 'missing field' => [['faq/a.md' => "---\nquestion: Q\n---\n"], 'faq/a.md: missing field "order" (int).'];
        yield 'empty string' => [['faq/a.md' => "---\nquestion: ''\norder: 1\n---\n"], 'field "question" must be a non-empty string'];
        yield 'int' => [['faq/a.md' => "---\nquestion: Q\norder: '1'\n---\n"], 'field "order" must be a whole number'];
        yield 'list' => [['faq/a.md' => "---\nquestion: Q\norder: 1\ntags: {a: b}\n---\n"], 'field "tags" must be a list of plain values'];
        yield 'date' => [['team/a.yaml' => "name: A\njoined: soon\nactive: true\n"], 'team/a.yaml: front matter needs a "joined" in YYYY-MM-DD format.'];
        yield 'bool' => [['team/a.yaml' => "name: A\njoined: 2024-01-01\nactive: yes please\n"], 'field "active" must be true or false'];
        yield 'url' => [['team/a.yaml' => "name: A\njoined: 2024-01-01\nactive: true\nphoto: http://insecure.test/a.png\n"], 'field "photo" must be a /path or an https:// URL'];
        yield 'protocol-relative url' => [['team/a.yaml' => "name: A\njoined: 2024-01-01\nactive: true\nphoto: //evil.test/a.png\n"], 'field "photo" must be a /path or an https:// URL'];
        yield 'relative link' => [['faq/a.md' => $faq . "See [this](other.pdf).\n"], 'faq/a.md: "other.pdf" is a relative link; use a /path'];
        yield 'not a mapping' => [['team/a.yaml' => "- a\n- b\n"], 'team/a.yaml: the fields must be a YAML mapping'];
        yield 'invalid yaml' => [['team/a.yaml' => "name: [unclosed\n"], 'team/a.yaml: invalid YAML'];
        yield 'stray file' => [['faq/photo.png' => 'x'], 'faq/photo.png: collections hold <slug>.md or <slug>.yaml files'];
        yield 'bad slug' => [['faq/Bad_Name.md' => $faq], 'faq/Bad_Name.md: collections hold <slug>.md'];
        yield 'subfolder' => [['faq/sub/a.md' => $faq], 'faq/sub: collections hold <slug>.md'];
        yield 'translation only' => [['faq/a.it.md' => $faq], 'faq/a.it.md: a translation needs the default-language file faq/a.md first.'];
        yield 'default language code' => [['faq/a.en.md' => $faq], 'faq/a.en.md: the default language (en) has no language code: a.md.'];
        yield 'unknown language' => [['faq/a.fr.md' => $faq, 'faq/a.md' => $faq], 'faq/a.fr.md: language "fr" is not configured'];
        yield 'md and yaml' => [['faq/a.md' => $faq, 'faq/a.yaml' => "question: Q\norder: 1\n"], 'already has a file for this language'];
        yield 'stray folder' => [['faqs/a.md' => $faq], 'content/faqs/ is not a collection: define it in config/collections.php (collections: faq, team).'];
    }

    /** @param array<string, string> $content */
    #[DataProvider('invalidContent')]
    public function testInvalidContentFailsLoudly(array $content, string $message): void
    {
        $app = $this->app($content);

        $this->expectExceptionMessage($message);
        $app->collection('faq')->all();
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidSchemas(): iterable
    {
        yield 'no fields' => [['faq' => []], 'config/collections.php "faq": needs "fields"'];
        yield 'unknown type' => [['faq' => ['fields' => ['q' => 'text']]], 'field "q" has unknown type "text"'];
        yield 'reserved field' => [['faq' => ['fields' => ['html' => 'string']]], '"html" is set by Starlite and can\'t be a field'];
        yield 'bad name' => [['FAQ-list' => ['fields' => ['q' => 'string']]], 'collection names use lowercase letters, digits and underscores'];
        yield 'reserved name' => [['blog' => ['fields' => ['q' => 'string']]], '"blog" is reserved'];
        yield 'unknown option' => [['faq' => ['fields' => ['q' => 'string'], 'order' => 'q']], 'unknown option "order"'];
        yield 'bad sort' => [['faq' => ['fields' => ['q' => 'string'], 'sort' => 'nope']], '"sort" must name a plain field'];
        yield 'bad json' => [['faq' => ['fields' => ['q' => 'string'], 'json' => ['secret']]], '"json" is true, false or a list of its fields'];
    }

    /** @param array<mixed> $schemas */
    #[DataProvider('invalidSchemas')]
    public function testInvalidSchemasFailAtBoot(array $schemas, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->app([], $schemas);
    }

    public function testUnknownCollectionNamesTheConfiguredOnes(): void
    {
        $this->expectExceptionMessage('Unknown collection "faqs". Collections: faq, team (config/collections.php).');
        $this->app()->collection('faqs');
    }
}
