<?php

declare(strict_types=1);

namespace Starlite\Images;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * An image preset from config/app.php `images.presets`, like a Craft CMS transform made responsive:
 * the widths to make, how wide the image is shown (`sizes`), and optionally a shape.
 *
 *   'card' => ['widths' => [400, 800], 'ratio' => '16:9', 'mode' => 'crop', 'position' => 'top'],
 *
 * Every option has a default: widths and sizes from `images`, no ratio (the image keeps its shape),
 * mode `crop`, position `center`, background `transparent`. A ratio replaces Craft's width × height:
 * each width gets the height that keeps the shape. Images are never enlarged.
 *
 *   crop       fills the ratio exactly, cutting what's outside; `position` picks what stays
 *   fit        the whole image within the ratio's box, keeping its own shape
 *   letterbox  the whole image within the box, padded to the ratio with `background`
 *   stretch    distorted to the box
 */
final class Preset
{
    public const MODES = ['crop', 'fit', 'letterbox', 'stretch'];
    public const POSITIONS = ['center', 'top', 'top-right', 'right', 'bottom-right', 'bottom', 'bottom-left', 'left', 'top-left'];

    /** @var list<int> */
    public readonly array $widths;

    public readonly string $sizes;

    /** @var array{int, int}|null width:height, reduced (16:9, never 32:18) */
    public readonly ?array $ratio;

    public readonly string $mode;
    public readonly string $position;
    public readonly string $background;

    /**
     * @param array<mixed> $options
     * @param list<int>    $widths default widths
     */
    public function __construct(public readonly string $name, array $options, array $widths, string $sizes)
    {
        $where = $name === '' ? 'config/app.php images' : "config/app.php images.presets.{$name}";
        $unknown = array_diff(array_keys($options), ['widths', 'sizes', 'ratio', 'mode', 'position', 'background']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$where}: unknown option \"" . implode('", "', $unknown) . '" (widths, sizes, ratio, mode, position, background).');
        }

        $widths = array_values(array_unique(array_map('intval', (array) ($options['widths'] ?? $widths))));
        sort($widths);
        if ($widths === [] || $widths[0] < 16) {
            throw new \InvalidArgumentException("{$where}: \"widths\" is a list of widths in pixels, e.g. [480, 960, 1440].");
        }
        $this->widths = $widths;
        $this->sizes = (string) ($options['sizes'] ?? $sizes);

        $ratio = $options['ratio'] ?? null;
        if ($ratio !== null) {
            if (!is_string($ratio) || !preg_match('/^([1-9]\d*):([1-9]\d*)$/', $ratio, $m)) {
                throw new \InvalidArgumentException("{$where}: \"ratio\" is width:height, e.g. '16:9' or '1:1'.");
            }
            $gcd = self::gcd((int) $m[1], (int) $m[2]);
            $ratio = [intdiv((int) $m[1], $gcd), intdiv((int) $m[2], $gcd)];
        } elseif (array_intersect(array_keys($options), ['mode', 'position', 'background']) !== []) {
            throw new \InvalidArgumentException("{$where}: \"mode\", \"position\" and \"background\" need a \"ratio\": the shape to crop or pad to.");
        }
        $this->ratio = $ratio;

        $this->mode = (string) ($options['mode'] ?? 'crop');
        if (!in_array($this->mode, self::MODES, true)) {
            throw new \InvalidArgumentException("{$where}: \"mode\" is one of " . implode(', ', self::MODES) . '.');
        }
        $this->position = (string) ($options['position'] ?? 'center');
        if (!in_array($this->position, self::POSITIONS, true)) {
            throw new \InvalidArgumentException("{$where}: \"position\" is one of " . implode(', ', self::POSITIONS) . '.');
        }
        $this->background = (string) ($options['background'] ?? 'transparent');
        if ($this->background !== 'transparent' && !preg_match('/^#[0-9a-f]{6}$/i', $this->background)) {
            throw new \InvalidArgumentException("{$where}: \"background\" is 'transparent' or a colour like '#ffffff'.");
        }
    }

    /**
     * The preset's shape in file names: "16x9-crop-top" (null without a ratio: plain scaled versions,
     * shared by every preset without one).
     */
    public function token(): ?string
    {
        if ($this->ratio === null) {
            return null;
        }
        $token = "{$this->ratio[0]}x{$this->ratio[1]}-{$this->mode}";

        return match ($this->mode) {
            'crop' => "{$token}-{$this->position}",
            'letterbox' => $this->background === 'transparent' ? $token : $token . '-' . strtolower(ltrim($this->background, '#')),
            default => $token,
        };
    }

    /**
     * The versions of a $width × $height image: requested width (in the file name) => actual output
     * size, smallest first. Widths that would enlarge the image are left out; the largest possible
     * one is always there, so even a small image gets the modern formats.
     *
     * @return array<int, array{int, int}>
     */
    public function outputs(int $width, int $height): array
    {
        $max = match (true) {
            $this->ratio === null, $this->mode === 'stretch' => $width,
            $this->mode === 'crop' => min($width, intdiv($height * $this->ratio[0], $this->ratio[1])),
            default => max($width, intdiv($height * $this->ratio[0], $this->ratio[1])), // fit, letterbox: the box the image fills one way
        };
        $outputs = [];
        foreach ([...array_filter($this->widths, static fn (int $w) => $w < $max), $max] as $box) {
            $outputs[$box] = $this->size($box, $width, $height);
        }

        return $outputs;
    }

    /** Resizes an image to this preset's version for a requested width. */
    public function apply(ImageInterface $image, int $box): ImageInterface
    {
        if ($this->ratio === null) {
            return $image->scaleDown(width: $box);
        }
        $boxHeight = (int) round($box * $this->ratio[1] / $this->ratio[0]);

        return match ($this->mode) {
            'crop' => $image->cover($box, $boxHeight, $this->position),
            'fit' => $image->scaleDown($box, $boxHeight),
            'letterbox' => $image->contain($box, $boxHeight, $this->background === 'transparent' ? 'ffffff00' : ltrim($this->background, '#')),
            default => $image->resize($box, $boxHeight),
        };
    }

    /** @return array{int, int} the output size for a requested width */
    private function size(int $box, int $width, int $height): array
    {
        if ($this->ratio === null) {
            return [$box, (int) round($box * $height / $width)];
        }
        $boxHeight = (int) round($box * $this->ratio[1] / $this->ratio[0]);
        if ($this->mode === 'fit') {
            $scale = min($box / $width, $boxHeight / $height, 1);

            return [(int) round($width * $scale), (int) round($height * $scale)];
        }

        return [$box, $boxHeight];
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }
}
