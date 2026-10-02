<?php

declare(strict_types=1);

namespace Starlite;

/**
 * Opcache warming for PHP-FPM.
 *
 * The CLI and PHP-FPM have separate Opcache memory, so `bin/console deploy` cannot warm FPM directly.
 * Instead it writes the list of files to compile (var/cache/opcache-files.php) and sends a signed
 * POST to /_opcache/warm. That request runs inside FPM and recompiles every listed file.
 *
 * The request only carries a timestamp and an HMAC of it (keyed with APP_SECRET); the file list
 * is always read from the server's own cache, never from the request.
 */
final class Opcache
{
    public const PATH = '/_opcache/warm';
    public const SIGNATURE_HEADER = 'X-Opcache-Signature';
    public const TIMESTAMP_HEADER = 'X-Opcache-Timestamp';
    private const MAX_AGE = 60;

    public static function sign(string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', 'opcache-warm|' . $timestamp, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $signature): bool
    {
        return ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= self::MAX_AGE
            && hash_equals(self::sign($secret, (int) $timestamp), $signature);
    }

    public static function listFile(string $cacheDir): string
    {
        return $cacheDir . '/opcache-files.php';
    }

    /**
     * Invalidates and recompiles each file in the current process's Opcache.
     *
     * @param list<string> $files
     *
     * @return array{compiled: int, failed: list<string>} files loaded by this request count as neither
     */
    public static function compile(array $files): array
    {
        $compiled = 0;
        $failed = [];
        // Files this request already loaded cannot be compiled again (functions would be redeclared);
        // invalidating them is enough, the next request recompiles them.
        $loaded = array_flip(get_included_files());
        foreach ($files as $file) {
            try {
                opcache_invalidate($file, true);
                if (isset($loaded[$file])) {
                    continue;
                }
                if (@opcache_compile_file($file)) {
                    ++$compiled;
                    continue;
                }
            } catch (\Throwable) {
            }
            $failed[] = $file;
        }

        return ['compiled' => $compiled, 'failed' => $failed];
    }

    public static function isEnabled(): bool
    {
        return function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false);
    }
}
