<?php

declare(strict_types=1);

namespace Starlite;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ vite('resources/js/app.js') }}` prints the tags for Vite entry points. Lists work too, so the
 * layout can add the page's own bundles: `{{ vite('resources/js/app.js', page_scripts ?? []) }}`.
 *
 * - Debug + `npm run dev` running: points at the Vite dev server (URL read from var/vite.hot).
 * - Otherwise: reads public/build/.vite/manifest.json (compiled into var/cache/vite.php)
 *   and prints hashed CSS, module preloads and the script.
 */
final class Vite extends AbstractExtension
{
    /** @var array<string, array{file: string, imports?: list<string>, css?: list<string>}>|null */
    private ?array $manifest = null;

    private bool $buildOptional = false;

    public function __construct(
        private readonly string $root,
        private readonly string $cacheDir,
        private readonly bool $debug,
        private readonly string $buildDir = 'build',
    ) {
    }

    /** `as` and `type` of the files vite_preload() accepts, by extension. */
    private const PRELOAD_TYPES = [
        'woff2' => ['font', 'font/woff2'],
        'woff' => ['font', 'font/woff'],
        'avif' => ['image', 'image/avif'],
        'webp' => ['image', 'image/webp'],
        'png' => ['image', 'image/png'],
        'jpg' => ['image', 'image/jpeg'],
        'jpeg' => ['image', 'image/jpeg'],
        'svg' => ['image', 'image/svg+xml'],
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vite', $this->tags(...), ['is_safe' => ['html']]),
            new TwigFunction('vite_preload', $this->preload(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * `<link rel="preload">` for built assets the browser would otherwise find late, typically the
     * main font file (only known once the CSS is parsed):
     * `{{ vite_preload('node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2') }}`.
     * Sources are paths as in the manifest. Nothing is printed while the dev server runs.
     */
    public function preload(string ...$sources): string
    {
        if ($this->devServer() !== null || ($this->buildOptional && !$this->built())) {
            return '';
        }
        $tags = [];
        foreach ($sources as $source) {
            [$as, $type] = self::PRELOAD_TYPES[strtolower(pathinfo($source, PATHINFO_EXTENSION))]
                ?? throw new \InvalidArgumentException("vite_preload() takes fonts and images, not \"{$source}\".");
            $file = $this->manifest()[$source]['file']
                ?? throw new \RuntimeException("Vite asset \"{$source}\" is not in the manifest. Run `npm run build`.");
            // Fonts are always fetched in CORS mode: without crossorigin the preload would be wasted.
            $tags[] = '<link rel="preload" href="' . self::e($this->asset($file)) . '" as="' . $as . '" type="' . $type . '"'
                . ($as === 'font' ? ' crossorigin' : '') . '>';
        }

        return implode("\n", $tags);
    }

    /**
     * Without a build, print a comment instead of failing: for tests that don't look at assets
     * (KernelTestCase turns it on when public/build has no manifest yet).
     */
    public function allowMissingBuild(): void
    {
        $this->buildOptional = true;
    }

    /** Whether `npm run build` has written the manifest. */
    public function built(): bool
    {
        return is_file($this->root . '/public/' . $this->buildDir . '/.vite/manifest.json');
    }

    /** @param string|list<string> ...$entries entries, or lists of entries; duplicates are printed once */
    public function tags(string|array ...$entries): string
    {
        $entries = array_values(array_unique(array_merge(...array_map(fn (string|array $entry): array => (array) $entry, $entries))));
        $devServer = $this->devServer();
        if ($devServer !== null) {
            $tags = [self::script($devServer . '/@vite/client')];
            foreach ($entries as $entry) {
                $url = $devServer . '/' . $entry;
                $tags[] = str_ends_with($entry, '.css') ? self::stylesheet($url) : self::script($url);
            }

            return implode("\n", $tags);
        }

        if ($this->buildOptional && !$this->built()) {
            return '<!-- Vite: no build in public/' . $this->buildDir . ' (run npm run build) -->';
        }
        $css = $preloads = $scripts = [];
        foreach ($entries as $entry) {
            $chunk = $this->manifest()[$entry]
                ?? throw new \RuntimeException("Vite entry \"{$entry}\" is not in the manifest. Run `npm run build`.");
            foreach ($this->collectCss($entry) as $file) {
                $css[$file] = self::stylesheet($this->asset($file));
            }
            foreach ($chunk['imports'] ?? [] as $import) {
                $preloads[$import] = '<link rel="modulepreload" href="' . self::e($this->asset($this->manifest()[$import]['file'])) . '">';
            }
            if (!str_ends_with($chunk['file'], '.css')) {
                $scripts[] = self::script($this->asset($chunk['file']));
            }
        }

        return implode("\n", [...array_values($css), ...array_values($preloads), ...$scripts]);
    }

    /** Builds the manifest cache; returns false when no build exists yet. */
    public function warmup(): bool
    {
        $this->manifest = null;
        $json = $this->root . '/public/' . $this->buildDir . '/.vite/manifest.json';
        if (!is_file($json)) {
            return false;
        }
        Cache::writeData($this->cacheDir . '/vite.php', $this->readManifest($json));

        return true;
    }

    /** The Vite dev server's URL, in debug mode while `npm run dev` runs. */
    public function devServer(): ?string
    {
        if (!$this->debug || !is_file($hot = $this->root . '/var/vite.hot')) {
            return null;
        }
        $url = rtrim(trim((string) file_get_contents($hot)), '/');

        return preg_match('#^https?://[^\s"\'<>]+$#', $url) ? $url : null;
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<string> CSS files of an entry and everything it imports
     */
    private function collectCss(string $key, array &$seen = []): array
    {
        if (isset($seen[$key])) {
            return [];
        }
        $seen[$key] = true;
        $chunk = $this->manifest()[$key];
        $css = str_ends_with($chunk['file'], '.css') ? [$chunk['file']] : [];
        foreach ($chunk['imports'] ?? [] as $import) {
            $css = [...$css, ...$this->collectCss($import, $seen)];
        }

        return [...$css, ...($chunk['css'] ?? [])];
    }

    /** @return array<string, array{file: string, imports?: list<string>, css?: list<string>}> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $json = $this->root . '/public/' . $this->buildDir . '/.vite/manifest.json';

        return $this->manifest = $this->debug
            ? $this->readManifest($json)
            : Cache::remember($this->cacheDir . '/vite.php', fn () => $this->readManifest($json));
    }

    /** @return array<string, array{file: string, imports?: list<string>, css?: list<string>}> */
    private function readManifest(string $json): array
    {
        if (!is_file($json)) {
            throw new \RuntimeException('Vite manifest not found. Run `npm run build` (or `npm run dev` with APP_DEBUG=1).');
        }

        return json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);
    }

    private function asset(string $file): string
    {
        return '/' . $this->buildDir . '/' . $file;
    }

    private static function script(string $url): string
    {
        return '<script type="module" src="' . self::e($url) . '"></script>';
    }

    private static function stylesheet(string $url): string
    {
        return '<link rel="stylesheet" href="' . self::e($url) . '">';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
