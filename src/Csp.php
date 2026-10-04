<?php

declare(strict_types=1);

namespace Starlite;

/**
 * Content Security Policy: the browser only loads scripts, styles, fonts, images, frames… from the
 * sources listed here, so injected markup can't pull in anything from elsewhere.
 *
 * Strict by default: everything from the site itself, nothing from other hosts. Allow more per
 * directive in config/app.php (`csp.sources`) or from config/bootstrap.php:
 *
 *   $app->csp->allow('frame-src', 'https://www.youtube-nocookie.com');
 *
 * No per-request nonces: they'd make every response different and break ETags and public caching.
 * The one inline script, the theme script, is allowed by its hash, which never changes.
 */
final class Csp
{
    /** Directives that take sources (allow() refuses anything else, to catch typos). */
    public const DIRECTIVES = [
        'default-src', 'script-src', 'script-src-elem', 'script-src-attr', 'style-src', 'style-src-elem',
        'style-src-attr', 'img-src', 'font-src', 'connect-src', 'media-src', 'object-src', 'frame-src',
        'child-src', 'worker-src', 'manifest-src', 'base-uri', 'form-action', 'frame-ancestors',
    ];

    /** @var array<string, list<string>> directive => sources */
    private array $policy = [
        'default-src' => ["'self'"],
        // Datastar evaluates its data-* expressions as functions: it needs 'unsafe-eval'.
        'script-src' => ["'self'", "'unsafe-eval'"],
        'style-src' => ["'self'"],
        // style="…" attributes (e.g. display: none until data-show runs). Attributes can't load or run
        // anything; <style> elements stay limited to style-src.
        'style-src-attr' => ["'unsafe-inline'"],
        // data: for the icons, which are SVG masks inlined in the CSS.
        'img-src' => ["'self'", 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ];

    /** @param array<string, list<string>|string> $sources extra sources per directive (config/app.php `csp.sources`) */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly bool $reportOnly = false,
        array $sources = [],
    ) {
        $this->allow('script-src', Theme::hash());
        foreach ($sources as $directive => $list) {
            $this->allow($directive, ...(array) $list);
        }
    }

    /**
     * Adds sources to a directive, e.g. allow('frame-src', 'https://player.vimeo.com'). A directive
     * the policy doesn't list yet starts from 'self', as it did through default-src.
     */
    public function allow(string $directive, string ...$sources): self
    {
        if (!in_array($directive, self::DIRECTIVES, true)) {
            throw new \InvalidArgumentException("Unknown Content-Security-Policy directive \"{$directive}\".");
        }
        foreach ($sources as $source) {
            // A ; or , would end the directive and let a source smuggle in a policy of its own.
            if (!preg_match('/^[^\s;,]+$/', $source)) {
                throw new \InvalidArgumentException("Invalid Content-Security-Policy source \"{$source}\" for {$directive}.");
            }
        }
        $current = $this->policy[$directive] ?? ["'self'"];
        if ($current === ["'none'"] && $sources !== []) {
            $current = [];
        }
        $this->policy[$directive] = array_values(array_unique([...$current, ...$sources]));

        return $this;
    }

    /**
     * Allows one inline script by its hash. Needed for scripts sent with Datastar's execute_script(),
     * which runs them as inline <script> elements: pass exactly the same code, e.g. from
     * config/bootstrap.php, `$app->csp->allowScript("console.log('Clock updated')")`.
     * Prefer patching elements and signals, which need nothing in the policy.
     */
    public function allowScript(string $script): self
    {
        return $this->allow('script-src', self::hash($script));
    }

    /** CSP hash source of an inline script or style: "'sha256-…'". */
    public static function hash(string $code): string
    {
        return "'sha256-" . base64_encode(hash('sha256', $code, true)) . "'";
    }

    /** @return array<string, list<string>> */
    public function directives(): array
    {
        return $this->policy;
    }

    public function headerName(): string
    {
        return $this->reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
    }

    /**
     * The header value. With the Vite dev server running, its origin is allowed too: scripts, the CSS
     * it injects as <style> elements, fonts, images, and its WebSocket for hot updates.
     */
    public function header(?string $devServer = null): string
    {
        $policy = $this->policy;
        if ($devServer !== null) {
            $socket = preg_replace('#^http#', 'ws', $devServer);
            foreach (['script-src', 'style-src', 'img-src', 'font-src', 'connect-src'] as $directive) {
                $policy[$directive] = [...($policy[$directive] ?? ["'self'"]), $devServer];
            }
            $policy['style-src'][] = "'unsafe-inline'";
            $policy['connect-src'][] = (string) $socket;
        }

        return implode('; ', array_map(
            static fn (string $directive, array $sources): string => $directive . ' ' . implode(' ', $sources),
            array_keys($policy),
            $policy,
        ));
    }
}
