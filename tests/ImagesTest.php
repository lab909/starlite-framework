<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Images\Images;

final class ImagesTest extends FrameworkTestCase
{
    /**
     * A 64×32 JPEG with EXIF metadata like a phone photo's: a GPS position (Rome) and Orientation 6
     * (stored sideways, shown upright: 32×64).
     */
    private static function phonePhoto(): string
    {
        $image = imagecreatetruecolor(64, 32);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 60, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // TIFF, little-endian: IFD0 (Orientation, GPS IFD pointer), GPS IFD (latitude), latitude values.
        $entry = static fn (int $tag, int $type, int $count, string $value) => pack('vvV', $tag, $type, $count) . str_pad($value, 4, "\0");
        $tiff = 'II' . pack('vV', 42, 8)
            . pack('v', 2) . $entry(0x0112, 3, 1, pack('v', 6)) . $entry(0x8825, 4, 1, pack('V', 38)) . pack('V', 0)
            . pack('v', 2) . $entry(0x0001, 2, 2, "N\0") . $entry(0x0002, 5, 3, pack('V', 68)) . pack('V', 0)
            . pack('V6', 41, 1, 54, 1, 0, 1);
        $app1 = "Exif\0\0" . $tiff;

        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /** @param array{widths?: list<int>, sizes?: string, formats?: list<string>|null, presets?: array<mixed>} $config */
    private function images(array $config = []): Images
    {
        return new Images($this->tempDir('images'), $config);
    }

    private function photoFile(): string
    {
        $dir = $this->tempDir('content');
        file_put_contents("{$dir}/photo.jpg", self::phonePhoto());

        return "{$dir}/photo.jpg";
    }

    public function testThePhotoReallyHasGpsData(): void
    {
        $exif = exif_read_data($this->photoFile());

        self::assertIsArray($exif);
        self::assertSame('N', $exif['GPSLatitudeRef'] ?? null, 'the fixture is a fair test');
        self::assertSame(6, $exif['Orientation'] ?? null);
    }

    public function testThePublishedOriginalHasNoMetadataAndIsUpright(): void
    {
        $images = $this->images();
        $original = $images->original($this->photoFile());

        $exif = @exif_read_data($original);
        self::assertArrayNotHasKey('GPSLatitudeRef', is_array($exif) ? $exif : [], 'no GPS position');
        self::assertArrayNotHasKey('Orientation', is_array($exif) ? $exif : []);
        self::assertSame([32, 64], array_slice((array) getimagesize($original), 0, 2), 'turned upright');
        self::assertSame(IMAGETYPE_JPEG, getimagesize($original)[2] ?? null);
    }

    public function testVariantsAreSmallerModernAndCarryNoMetadata(): void
    {
        $images = $this->images(['widths' => [16]]);
        $photo = $this->photoFile();

        self::assertSame([32, 64], $images->size($photo), 'the displayed size, from the EXIF orientation');
        self::assertSame([16 => [16, 32], 32 => [32, 64]], $images->default->outputs(32, 64), 'the smaller configured widths, and its own');
        foreach ($images->formats as $format) {
            $variant = $images->variant($photo, 16, $format);
            $bytes = (string) file_get_contents($variant);
            $info = getimagesize($variant);
            self::assertIsArray($info);
            self::assertSame([16, "image/{$format}"], [$info[0], $info['mime']], "{$format} at 16px");
            self::assertStringNotContainsString('Exif', $bytes, "{$format}: no metadata");
        }
        self::assertContains('webp', $images->formats);
    }

    public function testEncodedFilesAreKeptAndReused(): void
    {
        $images = $this->images();
        $photo = $this->photoFile();

        $first = $images->variant($photo, 32, 'webp');
        touch($first, time() - 3600);
        self::assertSame($first, $images->variant($photo, 32, 'webp'));
        self::assertSame(time() - 3600, filemtime($first), 'not encoded again');

        file_put_contents($photo, self::phonePhoto() . 'changed');
        self::assertNotSame($first, $images->variant($photo, 32, 'webp'), 'a changed image is encoded again');
    }

    public function testOnlyTheOfferedWidthsAndFormatsExist(): void
    {
        $images = $this->images();
        $photo = $this->photoFile();

        foreach ([[999, 'webp'], [32, 'gif']] as [$width, $format]) {
            try {
                $images->variant($photo, $width, $format);
                self::fail("{$width}w {$format} must not exist");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPictureMarkup(): void
    {
        $images = $this->images(['widths' => [16], 'formats' => ['webp']]);

        self::assertSame(
            '<picture><source type="image/webp" srcset="/media/blog/x/photo.jpg.16w.webp 16w, /media/blog/x/photo.jpg.32w.webp 32w" sizes="(min-width: 48rem) 48rem, 100vw">'
            . '<img src="/media/blog/x/photo.jpg" alt="A &quot;red&quot; photo" width="32" height="64" loading="lazy" decoding="async"></picture>',
            $images->picture($this->photoFile(), '/media/blog/x/photo.jpg', 'A "red" photo'),
        );
        self::assertSame(
            '<img src="https://example.com/a.jpg" alt="" loading="eager" decoding="async" class="w-full">',
            $images->picture(null, 'https://example.com/a.jpg', '', 'eager', attributes: ['class' => 'w-full']),
            'not a file of ours: a plain img',
        );
        self::assertStringStartsWith('<img', $this->images(['formats' => []])->picture($this->photoFile(), '/p.jpg', ''), 'no modern format available');
    }

    public function testVariantNames(): void
    {
        self::assertSame('team.jpg.960w.webp', Images::variantName('/media/blog/x/team.jpg', 960, 'webp'));
        self::assertSame(['about/team.jpg', 960, null, 'webp'], Images::parseVariant('about/team.jpg.960w.webp'));
        self::assertSame(['team.png', 480, null, 'avif'], Images::parseVariant('team.png.480w.avif'), 'team.jpg and team.png never collide');
        self::assertSame('team.jpg.320w.1x1-crop-center.avif', Images::variantName('team.jpg', 320, 'avif', '1x1-crop-center'));
        self::assertSame(['team.jpg', 320, '1x1-crop-center', 'avif'], Images::parseVariant('team.jpg.320w.1x1-crop-center.avif'));
        self::assertNull(Images::parseVariant('team.960w.webp'));
        self::assertNull(Images::parseVariant('team.jpg'));
    }

    public function testWidthsMustMakeSense(): void
    {
        $this->expectExceptionMessage('"widths" is a list of widths in pixels');
        $this->images(['widths' => []]);
    }

    // --- In posts, templates and development ------------------------------------------

    public function testPostsTemplatesAndTheDevelopmentServer(): void
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        file_put_contents("{$content}/blog/2026/09/alpha/photo.jpg", self::phonePhoto());
        file_put_contents("{$content}/blog/2026/09/alpha/index.md", (string) file_get_contents("{$content}/blog/2026/09/alpha/index.md") . "\n![Rome](photo.jpg \"In Rome\")\n");
        $app = $this->kernel(overrides: ['content_dir' => $content, 'images' => ['widths' => [16]]]);

        $html = $app->posts()->slug('alpha')->one()['html'] ?? '';
        self::assertStringContainsString('srcset="/media/blog/alpha/photo.jpg.16w.webp 16w, /media/blog/alpha/photo.jpg.32w.webp 32w"', $html, 'Markdown images');
        self::assertStringContainsString('<img src="/media/blog/alpha/photo.jpg" alt="Rome" width="32" height="64" loading="lazy" decoding="async" title="In Rome">', $html);

        $app->get('/t', fn () => $app->twig->createTemplate("{{ image('/media/blog/alpha/photo.jpg', 'Cover', {loading: 'eager', fetchpriority: 'high'}) }}|{{ image('https://example.com/x.png') }}|{{ image(null) }}")->render(), 't');
        $template = $this->body($this->request($app, '/t'));
        self::assertStringContainsString('alt="Cover" width="32" height="64" loading="eager" decoding="async" fetchpriority="high"></picture>|', $template, 'image() in templates');
        self::assertStringContainsString('|<img src="https://example.com/x.png" alt="" loading="lazy" decoding="async">|', $template);

        $variant = $this->request($app, '/media/blog/alpha/photo.jpg.16w.webp');
        self::assertSame([200, 'image/webp'], [$variant->getStatusCode(), $variant->headers->get('Content-Type')], 'made on first request');
        self::assertSame(404, $this->request($app, '/media/blog/alpha/photo.jpg.999w.webp')->getStatusCode());
        self::assertSame(404, $this->request($app, '/media/blog/alpha/nope.jpg.16w.webp')->getStatusCode());

        $original = $this->body($this->request($app, '/media/blog/alpha/photo.jpg'));
        self::assertStringNotContainsString('Exif', $original, 'served without metadata in development too');

        $public = $this->tempDir('public');
        $app->blog->publishAssets($public);
        self::assertFileExists("{$public}/media/blog/alpha/photo.jpg.16w.webp");
        $published = @exif_read_data("{$public}/media/blog/alpha/photo.jpg");
        self::assertArrayNotHasKey('GPSLatitudeRef', is_array($published) ? $published : [], 'deploy publishes it without GPS');
    }

    public function testMediaUrlAppliesToVariants(): void
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        file_put_contents("{$content}/blog/2026/09/alpha/photo.jpg", self::phonePhoto());
        file_put_contents("{$content}/blog/2026/09/alpha/index.md", (string) file_get_contents("{$content}/blog/2026/09/alpha/index.md") . "\n![Rome](photo.jpg)\n");
        $app = $this->kernel(overrides: ['content_dir' => $content, 'media_url' => 'https://cdn.example.test', 'images' => ['widths' => [16], 'formats' => ['webp']]]);

        self::assertStringContainsString('srcset="https://cdn.example.test/media/blog/alpha/photo.jpg.16w.webp 16w', $app->posts()->slug('alpha')->one()['html'] ?? '');
    }

    // --- Presets ----------------------------------------------------------------------

    /** A 400×200 landscape test image. */
    private function landscape(): string
    {
        $image = imagecreatetruecolor(400, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        $path = $this->tempDir('content') . '/wide.png';
        imagepng($image, $path);

        return $path;
    }

    public function testPresetDefaultsAndValidation(): void
    {
        $images = $this->images(['widths' => [100, 300], 'sizes' => '50vw', 'presets' => ['square' => ['ratio' => '2:2'], 'hero' => ['widths' => [200], 'sizes' => '100vw']]]);

        self::assertSame([[100, 300], '50vw', [1, 1], 'crop', 'center'], [$images->presets['square']->widths, $images->presets['square']->sizes, $images->presets['square']->ratio, $images->presets['square']->mode, $images->presets['square']->position], 'defaults; the ratio reduced');
        self::assertSame('1x1-crop-center', $images->presets['square']->token());
        self::assertNull($images->presets['hero']->token(), 'no shape: the plain versions');
        self::assertSame($images->default, $images->preset(null));

        foreach ([
            [['x' => ['mode' => 'fit']], '"mode", "position" and "background" need a "ratio"'],
            [['x' => ['ratio' => '16/9']], '"ratio" is width:height'],
            [['x' => ['ratio' => '1:1', 'mode' => 'cover']], '"mode" is one of crop, fit, letterbox, stretch'],
            [['x' => ['ratio' => '1:1', 'position' => 'middle']], '"position" is one of center, top'],
            [['x' => ['ratio' => '1:1', 'mode' => 'letterbox', 'background' => 'white']], '"background" is \'transparent\' or a colour'],
            [['x' => ['height' => 300]], 'unknown option "height"'],
            [['Hero' => []], 'names use lowercase letters'],
        ] as [$presets, $message]) {
            try {
                $this->images(['presets' => $presets]);
                self::fail("Must be refused: {$message}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('Unknown image preset "heroo". Presets: square, hero');
        $images->preset('heroo');
    }

    public function testEachModeShapesTheVersionsWithoutEnlarging(): void
    {
        $images = $this->images(['widths' => [100], 'formats' => ['webp'], 'presets' => [
            'crop' => ['ratio' => '1:1', 'position' => 'left'],
            'fit' => ['ratio' => '1:1', 'mode' => 'fit'],
            'letterbox' => ['ratio' => '1:1', 'mode' => 'letterbox', 'background' => '#ff0000'],
            'stretch' => ['ratio' => '1:1', 'mode' => 'stretch'],
        ]]);
        $wide = $this->landscape(); // 400×200
        $sizes = static fn (string $preset) => $images->presets[$preset]->outputs(400, 200);

        self::assertSame([100 => [100, 100], 200 => [200, 200]], $sizes('crop'), 'a square crop is at most as wide as the image is tall');
        self::assertSame([100 => [100, 50], 400 => [400, 200]], $sizes('fit'), 'fit keeps the image\'s shape: the real size goes in srcset');
        self::assertSame([100 => [50, 100], 400 => [200, 400]], $images->presets['fit']->outputs(200, 400), 'a tall image fits by its height');
        self::assertSame([100 => [100, 100], 400 => [400, 400]], $sizes('letterbox'));
        self::assertSame([100 => [100, 100], 400 => [400, 400]], $sizes('stretch'));

        foreach (['crop' => [200, 200], 'fit' => [400, 200], 'letterbox' => [400, 400], 'stretch' => [400, 400]] as $preset => $expected) {
            $box = (int) array_key_last($sizes($preset));
            $file = $images->variant($wide, $box, 'webp', $images->presets[$preset]->token());
            self::assertSame($expected, array_slice((array) getimagesize($file), 0, 2), $preset);
        }
        $padded = imagecreatefromwebp($images->variant($wide, 400, 'webp', $images->presets['letterbox']->token()));
        self::assertNotFalse($padded);
        $pixel = imagecolorat($padded, 200, 5);
        self::assertIsInt($pixel);
        ['red' => $red, 'green' => $green, 'blue' => $blue] = imagecolorsforindex($padded, $pixel);
        self::assertTrue($red > 240 && $green < 16 && $blue < 16, "letterbox pads with the background (red, give or take WebP's compression): {$red},{$green},{$blue}");
    }

    public function testOnlyConfiguredShapesExist(): void
    {
        $images = $this->images(['widths' => [100], 'formats' => ['webp'], 'presets' => ['square' => ['ratio' => '1:1']]]);
        $wide = $this->landscape();

        foreach ([[100, '16x9-crop-center'], [150, '1x1-crop-center'], [300, '1x1-crop-center']] as [$width, $token]) {
            try {
                $images->variant($wide, $width, 'webp', $token);
                self::fail("{$width}w {$token} must not exist");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertFileExists($images->variant($wide, 200, 'webp', '1x1-crop-center'));
    }

    public function testPicturesWithAPresetAndPublishingEveryPreset(): void
    {
        $images = $this->images(['widths' => [100], 'formats' => ['webp'], 'presets' => ['square' => ['ratio' => '1:1', 'sizes' => '10rem'], 'big' => ['widths' => [300]]]]);
        $wide = $this->landscape();

        self::assertSame(
            '<picture><source type="image/webp" srcset="/m/wide.png.100w.1x1-crop-center.webp 100w, /m/wide.png.200w.1x1-crop-center.webp 200w" sizes="10rem">'
            . '<img src="/m/wide.png.200w.1x1-crop-center.webp" alt="" width="200" height="200" loading="lazy" decoding="async"></picture>',
            $images->picture($wide, '/m/wide.png', '', preset: 'square'),
            'a shaped preset: its largest version as the fallback, with its size',
        );
        self::assertStringContainsString('srcset="/m/wide.png.300w.webp 300w, /m/wide.png.400w.webp 400w" sizes="5rem"', $images->picture($wide, '/m/wide.png', '', sizes: '5rem', preset: 'big'));

        $public = $this->tempDir('public');
        $images->publish($wide, $public);
        $files = array_values(array_diff((array) scandir($public), ['.', '..']));
        sort($files);
        self::assertSame(['wide.png', 'wide.png.100w.1x1-crop-center.webp', 'wide.png.100w.webp', 'wide.png.200w.1x1-crop-center.webp', 'wide.png.300w.webp', 'wide.png.400w.webp'], $files);
    }

    public function testTemplatesAndTheDevelopmentServerKnowThePresets(): void
    {
        $content = $this->copyToTemp(self::CONTENT, 'content');
        file_put_contents("{$content}/blog/2026/09/alpha/wide.png", (string) file_get_contents($this->landscape()));
        $app = $this->kernel(overrides: ['content_dir' => $content, 'images' => ['widths' => [100], 'formats' => ['webp'], 'presets' => ['square' => ['ratio' => '1:1']]]]);
        $app->get('/t', fn () => $app->twig->createTemplate("{{ image('/media/blog/alpha/wide.png', 'Wide', {preset: 'square'}) }}")->render(), 't');

        self::assertStringContainsString('<img src="/media/blog/alpha/wide.png.200w.1x1-crop-center.webp" alt="Wide" width="200" height="200"', $this->body($this->request($app, '/t')));
        $variant = $this->request($app, '/media/blog/alpha/wide.png.200w.1x1-crop-center.webp');
        self::assertSame([200, 'image/webp'], [$variant->getStatusCode(), $variant->headers->get('Content-Type')]);
        self::assertSame(404, $this->request($app, '/media/blog/alpha/wide.png.200w.3x2-crop-center.webp')->getStatusCode(), 'not a configured shape');
    }
}
