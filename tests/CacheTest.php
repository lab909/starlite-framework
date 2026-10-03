<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Cache;

final class CacheTest extends KernelTestCase
{
    public function testRememberBuildsOnceThenReadsTheFile(): void
    {
        $file = $this->tempDir() . '/nested/data.php';
        $calls = 0;
        $build = static function () use (&$calls) {
            ++$calls;

            return ['a' => 1, 'list' => [1, 2]];
        };

        $expected = ['a' => 1, 'list' => [1, 2]];
        self::assertEquals($expected, Cache::remember($file, $build));
        self::assertEquals($expected, Cache::remember($file, $build));
        self::assertSame(1, $calls);
        self::assertEquals($expected, require $file, 'stored as a plain PHP array file');
        self::assertSame([], glob(dirname($file) . '/.tmp*'), 'no temp files left behind');
    }

    public function testClearEmptiesADirectoryButKeepsGitkeep(): void
    {
        $dir = $this->tempDir();
        self::write($dir, ['.gitkeep' => '', 'a/b/c.php' => 'x', 'd.txt' => 'x']);
        Cache::clear($dir);

        self::assertSame(['.gitkeep'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
        Cache::clear($dir . '/missing'); // no error for a missing directory
    }
}
