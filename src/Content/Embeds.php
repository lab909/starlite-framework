<?php

declare(strict_types=1);

namespace Starlite\Content;

/**
 * Privacy-friendly video embeds, behind the default `::youtube{id="…"}` and `::vimeo{id="…"}`
 * components (resources/templates/_components/).
 *
 * Nothing reaches the video host until the visitor presses play: the page shows a poster image that
 * this site serves itself, downloaded with the video's title by `deploy` (the `embeds` step) into
 * public/media/embeds/. In development a missing poster is fetched once, on first view. Without a
 * poster (offline, the host refused), the component shows a neutral one instead.
 */
final class Embeds
{
    public const URL = '/media/embeds';

    /**
     * Supported hosts: video id pattern, the player's origin (for the CSP's frame-src) and its name.
     *
     * @var array<string, array{pattern: string, player: string, name: string}>
     */
    public const PROVIDERS = [
        'youtube' => ['pattern' => '/^[A-Za-z0-9_-]{11}$/', 'player' => 'https://www.youtube-nocookie.com', 'name' => 'YouTube'],
        'vimeo' => ['pattern' => '/^[0-9]{6,12}$/', 'player' => 'https://player.vimeo.com', 'name' => 'Vimeo'],
    ];

    /** Image types a poster may be, by their first bytes: anything else is never saved to public/. */
    private const IMAGES = ["\xFF\xD8\xFF" => 'jpg', "\x89PNG" => 'png', 'RIFF' => 'webp'];

    /** @var \Closure(string): ?string */
    private readonly \Closure $fetch;

    /** @param (\Closure(string): ?string)|null $fetch downloads a URL (body or null); tests pass their own */
    public function __construct(
        private readonly string $publicDir,
        private readonly bool $debug,
        ?\Closure $fetch = null,
        private readonly string $mediaUrl = '',
    ) {
        $this->fetch = $fetch ?? self::download(...);
    }

    /**
     * A `::youtube` / `::vimeo` component's arguments: an error message, or null if they're fine.
     *
     * @param array<string, string|int|float|bool> $args
     */
    public function check(string $provider, array $args): ?string
    {
        $id = $args['id'] ?? null;
        if (!is_string($id) || !preg_match(self::PROVIDERS[$provider]['pattern'], $id)) {
            return match ($provider) {
                'youtube' => 'needs id="…", the 11 characters after watch?v= in the video\'s URL.',
                default => 'needs id="…", the number in the video\'s URL (vimeo.com/123456789).',
            };
        }
        if (isset($args['start']) && (!is_int($args['start']) || $args['start'] < 0)) {
            return 'start is a number of seconds, e.g. start=90.';
        }

        return null;
    }

    /**
     * What a video component's template needs: poster (a local URL or null), title, the player URL
     * to load on play and the video's own page (the link without JavaScript).
     *
     * @return array{provider: string, name: string, id: string, title: ?string, poster: ?string, player: string, src: string, url: string}
     */
    public function video(string $provider, string $id, int $start = 0): array
    {
        $info = self::PROVIDERS[$provider] ?? throw new \InvalidArgumentException("Unknown video host \"{$provider}\".");
        if (!preg_match($info['pattern'], $id)) {
            throw new \InvalidArgumentException("Invalid {$info['name']} video id \"{$id}\".");
        }
        $meta = $this->meta($provider, $id);
        if ($meta === null && $this->debug) {
            $this->fetch($provider, $id);
            $meta = $this->meta($provider, $id);
        }

        return [
            'provider' => $provider,
            'name' => $info['name'],
            'id' => $id,
            'title' => $meta['title'] ?? null,
            'poster' => isset($meta['poster']) ? $this->mediaUrl . self::URL . '/' . $meta['poster'] : null,
            'player' => $info['player'],
            'src' => match ($provider) {
                'youtube' => "{$info['player']}/embed/{$id}?autoplay=1&rel=0" . ($start > 0 ? "&start={$start}" : ''),
                default => "{$info['player']}/video/{$id}?autoplay=1&dnt=1" . ($start > 0 ? "#t={$start}s" : ''),
            },
            'url' => match ($provider) {
                'youtube' => "https://www.youtube.com/watch?v={$id}" . ($start > 0 ? "&t={$start}s" : ''),
                default => "https://vimeo.com/{$id}" . ($start > 0 ? "#t={$start}s" : ''),
            },
        ];
    }

    /**
     * Downloads a video's poster and title into public/media/embeds/. Returns whether a poster was
     * saved; a failure is remembered (poster null), so development doesn't retry on every request,
     * and `deploy` tries again.
     */
    public function fetch(string $provider, string $id): bool
    {
        [$title, $posters] = match ($provider) {
            'youtube' => self::youtube($id, $this->fetch),
            default => self::vimeo($id, $this->fetch),
        };
        $poster = null;
        foreach ($posters as $url) {
            $body = ($this->fetch)($url);
            $type = $body === null ? null : self::imageType($body);
            if ($type !== null) {
                $poster = "{$provider}-{$id}.{$type}";
                $this->write($poster, (string) $body);
                break;
            }
        }
        $this->write("{$provider}-{$id}.json", json_encode(['title' => $title, 'poster' => $poster], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $poster !== null;
    }

    /** Whether a video still needs fetching: never tried, or the last try found no poster. */
    public function missing(string $provider, string $id): bool
    {
        return ($this->meta($provider, $id)['poster'] ?? null) === null;
    }

    /** @return array{title?: ?string, poster?: ?string}|null */
    private function meta(string $provider, string $id): ?array
    {
        $file = "{$this->publicDir}" . self::URL . "/{$provider}-{$id}.json";
        if (!is_file($file)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($file), true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * @param \Closure(string): ?string $fetch
     *
     * @return array{?string, list<string>} title, poster URLs to try (largest first)
     */
    private static function youtube(string $id, \Closure $fetch): array
    {
        $oembed = json_decode((string) $fetch('https://www.youtube.com/oembed?format=json&url=' . rawurlencode("https://www.youtube.com/watch?v={$id}")), true);

        return [
            is_string($oembed['title'] ?? null) ? $oembed['title'] : null,
            ["https://i.ytimg.com/vi/{$id}/maxresdefault.jpg", "https://i.ytimg.com/vi/{$id}/hqdefault.jpg"],
        ];
    }

    /**
     * @param \Closure(string): ?string $fetch
     *
     * @return array{?string, list<string>}
     */
    private static function vimeo(string $id, \Closure $fetch): array
    {
        $oembed = json_decode((string) $fetch('https://vimeo.com/api/oembed.json?width=1280&url=' . rawurlencode("https://vimeo.com/{$id}")), true);
        $poster = $oembed['thumbnail_url'] ?? null;

        return [
            is_string($oembed['title'] ?? null) ? $oembed['title'] : null,
            is_string($poster) && str_starts_with($poster, 'https://') ? [$poster] : [],
        ];
    }

    private static function imageType(string $body): ?string
    {
        foreach (self::IMAGES as $magic => $type) {
            if (str_starts_with($body, $magic) && ($type !== 'webp' || substr($body, 8, 4) === 'WEBP')) {
                return $type;
            }
        }

        return null;
    }

    private function write(string $file, string $contents): void
    {
        $dir = $this->publicDir . self::URL;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
        file_put_contents("{$dir}/{$file}", $contents);
    }

    private static function download(string $url): ?string
    {
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => false, 'header' => "User-Agent: Starlite\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);

        return is_string($body) && $body !== '' ? $body : null;
    }
}
