<?php

declare(strict_types=1);

namespace Starlite\Cdn;

use Symfony\Component\HttpFoundation\Request;

/**
 * Caching pages at a CDN (config/app.php `cdn`), off by default: a new site goes live without one,
 * and turns it on when it needs it (CDN_CACHE=1).
 *
 * On, pages say how long the CDN may keep them, and to keep serving them while the site is down:
 *
 *   Cache-Control: public, max-age=0, s-maxage=3600, stale-while-revalidate=60, stale-if-error=86400
 *
 * Browsers still check back every time (max-age=0, answered by the CDN). Every route is cached except:
 * the ones in `exclude` (and the Datastar endpoint), content pages with `cdn: false`, requests made by
 * Datastar, pages with a form (no-store), errors and redirects, and responses whose handler set its own
 * Cache-Control.
 *
 * `ttl` (s-maxage) defaults to an hour when the CDN can be purged (`purge`, or a package's purger),
 * since `deploy` purges it; otherwise to 5 minutes, so changes still show quickly.
 */
final class Cdn
{
    public const PURGERS = ['cloudflare', 'bunny', 'command'];

    /** Routes never cached at the CDN, whatever the config says: they answer each request differently. */
    public const ALWAYS_EXCLUDED = ['datastar'];

    public readonly bool $enabled;
    public readonly int $staleWhileRevalidate;
    public readonly int $staleIfError;

    /** @var list<string> route names */
    public readonly array $exclude;

    private readonly ?int $ttl;
    private readonly ?string $purge;

    /** @var array<string, mixed> */
    private readonly array $config;

    private ?Purger $purger = null;

    /** The current response must not be cached at the CDN (skip()). */
    private bool $skipped = false;

    /** @param array<string, mixed> $config config/app.php `cdn` */
    public function __construct(array $config, private readonly string $root, private readonly string $siteUrl)
    {
        $known = ['enabled', 'ttl', 'stale_while_revalidate', 'stale_if_error', 'exclude', 'purge', 'cloudflare', 'bunny', 'command'];
        $unknown = array_diff(array_keys($config), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('config/app.php cdn: unknown option "' . implode('", "', $unknown) . '" (' . implode(', ', $known) . ').');
        }
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->ttl = self::seconds($config, 'ttl', null);
        $this->staleWhileRevalidate = self::seconds($config, 'stale_while_revalidate', 60) ?? 60;
        $this->staleIfError = self::seconds($config, 'stale_if_error', 86400) ?? 86400;
        $exclude = $config['exclude'] ?? [];
        if (!is_array($exclude) || array_filter($exclude, is_string(...)) !== $exclude) {
            throw new \InvalidArgumentException('config/app.php cdn: "exclude" is a list of route names, e.g. [\'clock\'].');
        }
        $this->exclude = array_values(array_unique([...self::ALWAYS_EXCLUDED, ...$exclude]));
        $purge = $config['purge'] ?? null;
        if ($purge !== null && $purge !== '' && !in_array($purge, self::PURGERS, true)) {
            throw new \InvalidArgumentException('config/app.php cdn: "purge" (CDN_PURGE) is one of ' . implode(', ', self::PURGERS) . ' (got "' . (is_scalar($purge) ? $purge : gettype($purge)) . '").');
        }
        $this->purge = $purge === '' ? null : $purge;
        $this->config = $config;
    }

    /** Purges with another CDN's purger, e.g. from a package's register(). */
    public function usePurger(Purger $purger): void
    {
        $this->purger = $purger;
    }

    /** Whether the CDN can be purged: `purge` is set, or a package gave its purger. */
    public function purgeable(): bool
    {
        return $this->purger !== null || $this->purge !== null;
    }

    /**
     * The purger: a package's, or the one `purge` names, built from its credentials (only now, so a
     * missing token never stops the site from booting).
     */
    public function purger(): Purger
    {
        return $this->purger ??= match ($this->purge) {
            'cloudflare' => new CloudflarePurger((string) ($this->config['cloudflare']['zone'] ?? ''), (string) ($this->config['cloudflare']['token'] ?? '')),
            'bunny' => new BunnyPurger((string) ($this->config['bunny']['pull_zone'] ?? ''), (string) ($this->config['bunny']['key'] ?? '')),
            'command' => new CommandPurger((string) ($this->config['command'] ?? ''), $this->root),
            default => throw new \LogicException('No CDN purge set up: set CDN_PURGE (cloudflare, bunny or command) in .env, or add a package\'s purger.'),
        };
    }

    /** How long the CDN keeps a page, in seconds (s-maxage). */
    public function ttl(): int
    {
        return $this->ttl ?? ($this->purgeable() ? 3600 : 300);
    }

    /**
     * The absolute URL to purge for a command-line argument: a path on this site (blog/my-post,
     * /it/chi-siamo) or a full URL (to purge the production site from a development machine).
     */
    public function url(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            if (filter_var($pathOrUrl, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException("\"{$pathOrUrl}\" is not a valid URL.");
            }

            return $pathOrUrl;
        }

        return rtrim($this->siteUrl, '/') . '/' . ltrim($pathOrUrl, '/');
    }

    /** Keeps the current response out of the CDN's cache (a content page with `cdn: false`…). */
    public function skip(): void
    {
        $this->skipped = true;
    }

    /** Called by the kernel at the start of each request. */
    public function reset(): void
    {
        $this->skipped = false;
    }

    /**
     * The Cache-Control of a successful GET page for this request, or null when the CDN must not keep
     * it (off, excluded, skipped, or asked for by Datastar).
     */
    public function cacheControl(Request $request): ?string
    {
        if (!$this->enabled || $this->skipped || $request->headers->has('Datastar-Request')
            || in_array($request->attributes->get('_route'), $this->exclude, true)) {
            return null;
        }

        return sprintf('public, max-age=0, s-maxage=%d, stale-while-revalidate=%d, stale-if-error=%d', $this->ttl(), $this->staleWhileRevalidate, $this->staleIfError);
    }

    /** @param array<string, mixed> $config */
    private static function seconds(array $config, string $key, ?int $default): ?int
    {
        $value = $config[$key] ?? $default;
        if ($value !== null && (!is_int($value) || $value < 0)) {
            throw new \InvalidArgumentException("config/app.php cdn: \"{$key}\" is a number of seconds.");
        }

        return $value;
    }
}
