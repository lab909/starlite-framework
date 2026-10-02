<?php

declare(strict_types=1);

namespace Starlite;

/**
 * Compile-once PHP file cache. Data is stored as `return [...]` files, so Opcache keeps it
 * in shared memory as immutable arrays: reading it costs no parsing and no unserialize().
 */
final class Cache
{
    /** Returns the cached file's value, building and writing it first if it does not exist. */
    public static function remember(string $file, \Closure $build): mixed
    {
        if (is_file($file)) {
            return require $file;
        }
        $data = $build();
        self::writeData($file, $data);

        return $data;
    }

    public static function writeData(string $file, mixed $data): void
    {
        self::writeCode($file, '<?php return ' . var_export($data, true) . ";\n");
    }

    /** Atomic write (temp file + rename), so concurrent requests never include a half-written file. */
    public static function writeCode(string $file, string $code): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache directory {$dir}.");
        }
        $tmp = tempnam($dir, '.tmp');
        if ($tmp === false || file_put_contents($tmp, $code) === false) {
            throw new \RuntimeException("Cannot write cache file {$file}.");
        }
        chmod($tmp, 0664);
        rename($tmp, $file);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    /** Deletes everything inside $dir (but not $dir itself or its .gitkeep). */
    public static function clear(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->getPathname() === $dir . '/.gitkeep') {
                continue;
            }
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }
}
