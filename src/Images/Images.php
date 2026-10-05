<?php

declare(strict_types=1);

namespace Starlite\Images;

use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;

/**
 * Responsive images for the files of posts and pages: smaller, modern versions at several widths,
 * and the original without its metadata.
 *
 *   team.jpg → team.jpg.480w.avif, team.jpg.960w.avif, team.jpg.480w.webp, … and team.jpg re-saved without EXIF
 *
 * Markdown images and `{{ image(post.image, 'alt') }}` print a <picture> with AVIF (where the server's
 * GD or Imagick can write it) and WebP sources, and the original as <img> with its width and height.
 * Templates can pick a preset (widths, sizes, and a shape: see Preset): `{{ image(url, '', {preset: 'card'}) }}`.
 * `deploy` writes every file to public/media/; in development they're made on first request. Encoded
 * files are kept in var/images/, so an unchanged image is never encoded twice.
 *
 * Privacy: photos carry metadata (EXIF), often the GPS position where they were taken. Every published
 * image, the original included, is re-saved without it, the right way up.
 */
final class Images
{
    /** Formats that get responsive versions; SVG scales by itself, GIF is often animated. */
    public const RASTER = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    /**
     * A variant's file name: the original's (extension included, so team.jpg and team.png never
     * collide), the requested width, the preset's shape if it has one, and the format:
     * team.jpg.960w.webp, team.jpg.320w.1x1-crop-center.avif.
     */
    private const VARIANT = '/^(.+\.(?:jpe?g|png|webp|avif))\.(\d+)w(?:\.([a-z0-9-]+))?\.(avif|webp)$/i';

    /** The default preset: config/app.php `images.widths` and `images.sizes`, no shape. */
    public readonly Preset $default;

    /** @var array<string, Preset> */
    public readonly array $presets;

    /** @var list<int> */
    public readonly array $widths;

    /** @var list<string> 'avif' and/or 'webp': what this server can write, best first */
    public readonly array $formats;

    public readonly string $sizes;

    /** @var array{avif: int, webp: int, jpeg: int} */
    private readonly array $quality;

    private ?ImageManager $manager = null;

    /**
     * @param array{widths?: list<int>, sizes?: string, formats?: list<string>|null, quality?: array<string, int>, presets?: array<mixed>} $config
     */
    public function __construct(
        private readonly string $cacheDir,
        array $config = [],
        private readonly ?string $driver = null,
    ) {
        $this->default = new Preset('', ['widths' => $config['widths'] ?? [480, 960, 1440]], [], $config['sizes'] ?? '(min-width: 48rem) 48rem, 100vw');
        $this->widths = $this->default->widths;
        $this->sizes = $this->default->sizes;
        $presets = [];
        foreach ($config['presets'] ?? [] as $name => $options) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
                throw new \InvalidArgumentException('config/app.php images.presets: names use lowercase letters, digits, dashes and underscores.');
            }
            $presets[$name] = new Preset($name, (array) $options, $this->widths, $this->sizes);
        }
        $this->presets = $presets;
        $this->quality = ($config['quality'] ?? []) + ['avif' => 50, 'webp' => 75, 'jpeg' => 90];

        $wanted = $config['formats'] ?? ['avif', 'webp'];
        $this->formats = array_values(array_filter($wanted, fn (string $format) => in_array($format, ['avif', 'webp'], true)
            && $this->manager()->driver->supports($format === 'avif' ? Format::AVIF : Format::WEBP)));
    }

    /** Whether a file gets responsive versions (by its extension). */
    public static function isRaster(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::RASTER, true);
    }

    /**
     * Width and height as displayed (a phone photo taken upright is stored sideways, with an EXIF
     * Orientation tag), without decoding the image; null if it isn't a readable raster image.
     *
     * @return array{int, int}|null
     */
    public function size(string $path): ?array
    {
        if (!self::isRaster($path) || !is_file($path) || ($info = @getimagesize($path)) === false) {
            return null;
        }
        [$width, $height] = $info;
        if (function_exists('exif_read_data') && $info[2] === IMAGETYPE_JPEG) {
            $exif = @exif_read_data($path, 'IFD0');
            if (is_array($exif) && in_array($exif['Orientation'] ?? 1, [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }

        return [$width, $height];
    }

    /** A preset by name ('' or null: the default); a typo fails loudly. */
    public function preset(?string $name): Preset
    {
        if ($name === null || $name === '') {
            return $this->default;
        }

        return $this->presets[$name] ?? throw new \InvalidArgumentException(
            "Unknown image preset \"{$name}\". Presets: " . (implode(', ', array_keys($this->presets)) ?: 'none') . ' (config/app.php images.presets).',
        );
    }

    /** "team.jpg", 960, "webp" → "team.jpg.960w.webp"; with a shape: "team.jpg.960w.16x9-crop-center.webp". */
    public static function variantName(string $file, int $width, string $format, ?string $token = null): string
    {
        return basename($file) . ".{$width}w" . ($token !== null ? ".{$token}" : '') . ".{$format}";
    }

    /**
     * "about/team.jpg.320w.1x1-crop-center.avif" → ["about/team.jpg", 320, "1x1-crop-center", "avif"]:
     * which image, width, shape (null: none) and format a variant's name asks for.
     *
     * @return array{string, int, ?string, string}|null
     */
    public static function parseVariant(string $file): ?array
    {
        return preg_match(self::VARIANT, $file, $m) ? [$m[1], (int) $m[2], $m[3] !== '' ? $m[3] : null, $m[4]] : null;
    }

    /**
     * The markup for an image: a <picture> with the modern formats for a raster image this site serves,
     * a plain <img> otherwise.
     *
     * @param string                      $path       the image file, or null for one that isn't ours (a URL)
     * @param array<string, string|null>  $attributes more <img> attributes (class, fetchpriority…)
     */
    public function picture(?string $path, string $url, string $alt, string $loading = 'lazy', ?string $sizes = null, array $attributes = [], ?string $preset = null): string
    {
        $preset = $this->preset($preset);
        $size = $path !== null ? $this->size($path) : null;
        if ($size === null || $this->formats === []) {
            $img = ['src' => $url, 'alt' => $alt] + ($size !== null ? ['width' => (string) $size[0], 'height' => (string) $size[1]] : []);

            return '<img' . self::attributes($img + ['loading' => $loading, 'decoding' => 'async'] + $attributes) . '>';
        }
        $base = substr($url, 0, (int) strrpos($url, '/') + 1);
        $outputs = $preset->outputs($size[0], $size[1]);
        $sources = '';
        foreach ($this->formats as $format) {
            $srcset = [];
            foreach ($outputs as $box => [$width]) {
                $srcset[] = $base . rawurlencode(self::variantName($url, $box, $format, $preset->token())) . " {$width}w";
            }
            $sources .= '<source' . self::attributes(['type' => "image/{$format}", 'srcset' => implode(', ', $srcset), 'sizes' => $sizes ?? $preset->sizes]) . '>';
        }
        // The fallback: the original as it is or, for a preset with a shape, its largest version in
        // WebP (the original isn't that shape).
        if ($preset->token() === null) {
            $img = ['src' => $url, 'alt' => $alt, 'width' => (string) $size[0], 'height' => (string) $size[1]];
        } else {
            $box = (int) array_key_last($outputs);
            $format = in_array('webp', $this->formats, true) ? 'webp' : $this->formats[0];
            $img = ['src' => $base . rawurlencode(self::variantName($url, $box, $format, $preset->token())), 'alt' => $alt, 'width' => (string) $outputs[$box][0], 'height' => (string) $outputs[$box][1]];
        }

        return '<picture>' . $sources . '<img' . self::attributes($img + ['loading' => $loading, 'decoding' => 'async'] + $attributes) . '></picture>';
    }

    /**
     * A variant of an image (a file in var/images/), encoded the first time it's asked for. Only the
     * widths and shapes of the configured presets exist: anything else would let anyone make the
     * server encode endless versions.
     */
    public function variant(string $path, int $width, string $format, ?string $token = null): string
    {
        $size = $this->size($path) ?? throw new \InvalidArgumentException("Not an image: {$path}.");
        $preset = null;
        foreach ([$this->default, ...array_values($this->presets)] as $candidate) {
            if ($candidate->token() === $token && isset($candidate->outputs($size[0], $size[1])[$width])) {
                $preset = $candidate;
                break;
            }
        }
        if ($preset === null || !in_array($format, $this->formats, true)) {
            throw new \InvalidArgumentException("No {$width}w " . ($token ?? 'plain') . " {$format} version of {$path}.");
        }

        return $this->cached($path, "{$width}w." . ($token !== null ? "{$token}." : '') . $format, fn () => $preset->apply($this->manager()->decode($path), $width)->encode(
            $format === 'avif' ? new AvifEncoder(quality: $this->quality['avif'], strip: true) : new WebpEncoder(quality: $this->quality['webp'], strip: true),
        )->toString());
    }

    /** The image re-saved without metadata (EXIF: GPS position, camera…), upright, in its own format. */
    public function original(string $path): string
    {
        $encoder = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => new PngEncoder(strip: true),
            'webp' => new WebpEncoder(quality: 90, strip: true),
            'avif' => new AvifEncoder(quality: 70, strip: true),
            default => new JpegEncoder(quality: $this->quality['jpeg'], strip: true),
        };

        return $this->cached($path, 'original.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)), fn () => $this->encode($path, $encoder));
    }

    /**
     * Publishes an image: the original without metadata, plus every variant, into $targetDir.
     *
     * @return int files written
     */
    public function publish(string $path, string $targetDir): int
    {
        $file = basename($path);
        copy($this->original($path), "{$targetDir}/{$file}");
        $count = 1;
        $size = $this->size($path);
        if ($size === null) {
            return $count;
        }
        // Every preset's versions: templates choose presets when pages render, so deploy can't know
        // which image is shown where. Presets without a shape share the plain versions.
        $versions = [];
        foreach ([$this->default, ...array_values($this->presets)] as $preset) {
            foreach (array_keys($preset->outputs($size[0], $size[1])) as $width) {
                $versions[$width . '|' . $preset->token()] = [$width, $preset->token()];
            }
        }
        foreach ($this->formats as $format) {
            foreach ($versions as [$width, $token]) {
                copy($this->variant($path, $width, $format, $token), $targetDir . '/' . self::variantName($file, $width, $format, $token));
                ++$count;
            }
        }

        return $count;
    }

    private function encode(string $path, EncoderInterface $encoder): string
    {
        return $this->manager()->decode($path)->encode($encoder)->toString();
    }

    /**
     * An encoded file kept in var/images/, named after the source's content and the settings, so it's
     * made again only when the image or the settings change.
     *
     * @param \Closure(): string $make
     */
    private function cached(string $path, string $suffix, \Closure $make): string
    {
        $key = hash('xxh128', (string) hash_file('xxh128', $path) . json_encode([$this->quality, $suffix]));
        $file = "{$this->cacheDir}/" . substr($key, 0, 2) . "/{$key}.{$suffix}";
        if (!is_file($file)) {
            if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
                throw new \RuntimeException('Cannot create ' . dirname($file) . '.');
            }
            $tmp = "{$file}." . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, $make());
            rename($tmp, $file); // atomic: a concurrent request never reads half a file
        }

        return $file;
    }

    private function manager(): ImageManager
    {
        $driver = $this->driver ?? (extension_loaded('imagick') ? 'imagick' : 'gd');

        return $this->manager ??= new ImageManager(
            $driver === 'imagick' ? \Intervention\Image\Drivers\Imagick\Driver::class : \Intervention\Image\Drivers\Gd\Driver::class,
            autoOrientation: true,
            strip: true, // every encoder above also strips: two layers, so a new encoder can't forget it
        );
    }

    /** @param array<string, string|null> $attributes */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5) . '"';
            }
        }

        return $html;
    }
}
