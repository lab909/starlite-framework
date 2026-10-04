<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Query;
use Starlite\Site;

final class QueryTest extends FrameworkTestCase
{
    private const ITEMS = [
        'en' => [
            'b' => ['slug' => 'b', 'language' => 'en', 'title' => 'Banana', 'rank' => 2, 'tags' => ['fruit', 'yellow'], 'note' => null],
            'a' => ['slug' => 'a', 'language' => 'en', 'title' => 'apple', 'rank' => 1, 'tags' => ['fruit'], 'note' => 'crisp'],
            'c' => ['slug' => 'c', 'language' => 'en', 'title' => 'Carrot 10', 'rank' => 3, 'tags' => ['vegetable'], 'note' => null],
            'd' => ['slug' => 'd', 'language' => 'en', 'title' => 'Carrot 9', 'rank' => null, 'tags' => [], 'note' => 'sweet'],
        ],
        'it' => [
            'a' => ['slug' => 'a', 'language' => 'it', 'title' => 'Mela', 'rank' => 1, 'tags' => ['frutta'], 'note' => null],
        ],
    ];

    private Site $site;

    /** @return Query<array<string, mixed>> */
    private function query(int $perPage = 2): Query
    {
        $this->site = new Site('https://example.test', 'Fixture', defaultLanguage: 'en', languages: [
            'en' => ['name' => 'English', 'locale' => 'en_US'],
            'it' => ['name' => 'Italiano', 'locale' => 'it_IT'],
        ]);

        return new Query('fruits', self::items(...), ['slug', 'language', 'title', 'rank', 'tags', 'note'], ['title', 'tags'], $this->site, $perPage);
    }

    /** @return array<string, array<string, mixed>> the source: items by slug in a language */
    private static function items(string $language): array
    {
        return self::ITEMS[$language];
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function slugs(array $items): string
    {
        return implode(',', array_column($items, 'slug'));
    }

    public function testDefaultOrderIsTheSources(): void
    {
        self::assertSame('b,a,c,d', self::slugs($this->query()->all()));
    }

    public function testEveryStepReturnsANewQuery(): void
    {
        $base = $this->query();
        $fruit = $base->tag('fruit');

        self::assertNotSame($base, $fruit);
        self::assertSame('b,a,c,d', self::slugs($base->all()), 'the base query is unchanged');
        self::assertSame('b,a', self::slugs($fruit->all()));
        self::assertSame('a', self::slugs($fruit->slug('a')->all()));
    }

    public function testWhere(): void
    {
        $query = $this->query();

        self::assertSame('a', self::slugs($query->where('rank', 1)->all()));
        self::assertSame('b,c', self::slugs($query->where('rank', [2, 3])->all()), 'a list of values matches any');
        self::assertSame('b', self::slugs($query->where('tags', 'yellow')->all()), 'a list field contains the value');
        self::assertSame('b,c', self::slugs($query->where('tags', ['yellow', 'vegetable'])->all()));
        self::assertSame('', self::slugs($query->where('rank', '1')->all()), 'strict: "1" is not 1');
        self::assertSame('b,c', self::slugs($query->where('note', null)->all()));
        self::assertSame('a,c', self::slugs($query->slug(['c', 'a'])->orderBy('slug')->all()));
    }

    public function testSearchAndTagIgnoreEmptyValues(): void
    {
        $query = $this->query();

        self::assertSame('c,d', self::slugs($query->search('CARROT')->all()), 'case-insensitive, in the search fields');
        self::assertSame('b', self::slugs($query->search('yell')->all()), 'list fields are searched too');
        self::assertSame('', self::slugs($query->search('crisp')->all()), 'only the search fields');
        self::assertSame('b,a,c,d', self::slugs($query->search('')->tag('')->search(null)->tag(null)->all()));
    }

    public function testOrderBy(): void
    {
        $query = $this->query();

        self::assertSame('a,b,d,c', self::slugs($query->orderBy('title')->all()), 'case-insensitive (apple first) and natural (Carrot 9 before Carrot 10)');
        self::assertSame('c,d,b,a', self::slugs($query->orderBy('title desc')->all()));
        self::assertSame('a,b,c,d', self::slugs($query->orderBy('rank')->all()), 'missing values last');
        self::assertSame('c,b,a,d', self::slugs($query->orderBy('rank desc')->all()), 'missing values last, also descending');
        self::assertSame('a,d,b,c', self::slugs($query->orderBy('note, slug')->all()), 'ties broken by the next field');
    }

    public function testLimitOffsetAndOne(): void
    {
        $query = $this->query();

        self::assertSame('a,c', self::slugs($query->offset(1)->limit(2)->all()));
        self::assertSame('a', $query->offset(1)->one()['slug'] ?? null);
        self::assertNull($query->slug('zzz')->one());
        self::assertSame(2, $query->limit(2)->count(), 'count() is what all() returns');
        self::assertCount(4, $query, 'Countable');
        self::assertTrue($query->tag('fruit')->exists());
        self::assertFalse($query->tag('meat')->exists());
        self::assertSame('b,a,c,d', self::slugs(iterator_to_array($query)), 'iterable, as in a Twig for loop');
    }

    public function testPaginate(): void
    {
        $page = $this->query()->limit(1)->offset(3)->paginate(2);

        self::assertSame('c,d', self::slugs($page['items']), 'limit and offset are ignored');
        self::assertSame(['page' => 2, 'pages' => 2, 'per_page' => 2, 'total' => 4, 'has_more' => false], array_diff_key($page, ['items' => 1]));
        self::assertTrue($this->query()->paginate('1')['has_more'], 'a page from the URL can be a string');
        self::assertSame([], $this->query()->paginate(9)['items']);
        self::assertSame(1, $this->query()->paginate(-3)['page']);
        self::assertSame([2, 3], [$this->query()->paginate(1, perPage: 3)['pages'], $this->query()->paginate(1, perPage: 3)['per_page']]);
    }

    public function testCountBy(): void
    {
        self::assertSame(['fruit' => 2, 'yellow' => 1, 'vegetable' => 1], $this->query()->countBy('tags'));
        self::assertSame(['crisp' => 1, 'sweet' => 1], $this->query()->countBy('note'), 'null values are not counted');
        self::assertSame(['fruit' => 2, 'vegetable' => 1, 'yellow' => 1], $this->query()->orderBy('slug desc')->countBy('tags'), 'most frequent first, ties in item order');
        self::assertSame(['fruit' => 2, 'yellow' => 1], $this->query()->tag('fruit')->limit(1)->countBy('tags'), 'filters apply, limit does not');
    }

    public function testLanguage(): void
    {
        $query = $this->query();

        self::assertSame('Mela', $query->language('it')->slug('a')->one()['title'] ?? null);
        $this->site->enter('it', '/');
        self::assertSame('a', self::slugs($query->all()), 'the current language when none is given, at execution time');
        $this->expectExceptionMessage('Language "fr" is not configured in config/app.php.');
        $query->language('fr');
    }

    public function testTyposFailLoudly(): void
    {
        $query = $this->query();
        foreach ([
            fn () => $query->where('colour', 'red'),
            fn () => $query->orderBy('colour'),
            fn () => $query->orderBy('title sideways'),
            fn () => $query->countBy('colour'),
            fn () => $query->limit(-1),
            fn () => $query->offset(-1),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Call {$i} must throw.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('fruits:', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('fruits: no field "colour". Fields: slug, language, title, rank, tags, note.');
        $query->where('colour', 'red');
    }

    public function testTwigAndPhpGetTheSameQueries(): void
    {
        $app = $this->kernel();
        $app->get('/q', fn () => $app->twig->createTemplate(
            '{% set q = posts() %}{{ q.count }} {{ q.tag("php").one().slug }} {% for p in posts().limit(1) %}{{ p.slug }}{% endfor %} {{ posts().countBy("tags")|keys|join(",") }}',
        )->render());

        self::assertSame(
            sprintf('%d alpha %s %s', $app->posts()->count(), $app->posts()->one()['slug'] ?? '', implode(',', array_keys($app->posts()->countBy('tags')))),
            $this->body($this->request($app, '/q')),
        );
        self::assertStringContainsString('<article><a href="/blog/beta">', $this->body($this->request($app, '/blog')), 'paginate() items in a list template');
    }

    public function testContentIsNoLongerAGlobal(): void
    {
        $globals = $this->kernel()->twig->getGlobals();

        self::assertArrayNotHasKey('blog', $globals);
        self::assertArrayNotHasKey('collections', $globals);
        self::assertArrayHasKey('site', $globals);
    }
}
