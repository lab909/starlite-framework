<?php

declare(strict_types=1);

namespace Starlite\Cdn;

/**
 * Cloudflare (https://developers.cloudflare.com/cache/how-to/purge-cache/): a zone ID and an
 * API token with the "Cache Purge" permission for that zone (CLOUDFLARE_ZONE_ID, CLOUDFLARE_API_TOKEN).
 */
final class CloudflarePurger implements Purger
{
    /** URLs per request: Cloudflare's limit on every plan. */
    private const BATCH = 30;

    /** @var \Closure(string, string, array<string, string>, ?string): array{int, string} */
    private readonly \Closure $send;

    /** @param (\Closure(string, string, array<string, string>, ?string): array{int, string})|null $send */
    public function __construct(private readonly string $zone, #[\SensitiveParameter] private readonly string $token, ?\Closure $send = null)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $zone) || $token === '') {
            throw new \InvalidArgumentException('Cloudflare purging needs CLOUDFLARE_ZONE_ID (32 characters, on the zone\'s overview page) and CLOUDFLARE_API_TOKEN (a token with Cache Purge permission).');
        }
        $this->send = $send ?? Http::send(...);
    }

    public function name(): string
    {
        return 'Cloudflare';
    }

    public function purgeAll(): void
    {
        $this->request(['purge_everything' => true]);
    }

    public function purge(array $urls): void
    {
        foreach (array_chunk($urls, self::BATCH) as $batch) {
            $this->request(['files' => $batch]);
        }
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): void
    {
        [$status, $response] = ($this->send)(
            'POST',
            "https://api.cloudflare.com/client/v4/zones/{$this->zone}/purge_cache",
            ['Authorization' => "Bearer {$this->token}", 'Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $result = json_decode($response, true);
        if ($status === 200 && is_array($result) && ($result['success'] ?? false) === true) {
            return;
        }
        $error = is_array($result) ? ($result['errors'][0]['message'] ?? null) : null;

        throw new \RuntimeException('Cloudflare refused the purge: ' . (is_string($error) ? rtrim($error, '.') : ($status === 0 ? 'the API could not be reached' : "HTTP {$status}")) . '.');
    }
}
