<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Kernel;
use Starlite\Testing\KernelTestCase;

/**
 * Boots the fixture project (tests/data/project) with the fixture content (tests/data/content).
 */
abstract class FrameworkTestCase extends KernelTestCase
{
    protected const PROJECT = __DIR__ . '/data/project';
    protected const CONTENT = __DIR__ . '/data/content';

    /** @param array<string, mixed> $overrides merged over the fixture's config/app.php */
    protected function kernel(bool $debug = true, array $overrides = [], string $root = self::PROJECT): Kernel
    {
        return $this->bootKernel($root, $debug, array_replace_recursive(['content_dir' => self::CONTENT], $overrides));
    }
}
