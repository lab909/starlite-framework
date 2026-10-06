<?php

declare(strict_types=1);

namespace Starlite\Cdn;

/**
 * Bunny CDN (https://docs.bunny.net/reference): purges a pull zone, or single URLs, with the pull
 * zone's ID and the account's API key (BUNNY_PULL_ZONE_ID, BUNNY_API_KEY).
 */
final class BunnyPurger implements Purger
{
    /** @var \Closure(string, string, array<string, string>, ?string): array{int, string} */
    private readonly \Closure $send;

    /** @param (\Closure(string, string, array<string, string>, ?string): array{int, string})|null $send */
    public function __construct(private readonly string $pullZone, #[\SensitiveParameter] private readonly string $key, ?\Closure $send = null)
    {
        if (!preg_match('/^\d+$/', $pullZone) || $key === '') {
            throw new \InvalidArgumentException('Bunny purging needs BUNNY_PULL_ZONE_ID (a number, in the pull zone\'s URL in the dashboard) and BUNNY_API_KEY (Account settings → API).');
        }
        $this->send = $send ?? Http::send(...);
    }

    public function name(): string
    {
        return 'Bunny';
    }

    public function purgeAll(): void
    {
        $this->request("https://api.bunny.net/pullzone/{$this->pullZone}/purgeCache");
    }

    public function purge(array $urls): void
    {
        foreach ($urls as $url) {
            $this->request('https://api.bunny.net/purge?' . http_build_query(['url' => $url, 'async' => 'false']));
        }
    }

    private function request(string $url): void
    {
        [$status, $response] = ($this->send)('POST', $url, ['AccessKey' => $this->key, 'Content-Length' => '0'], null);
        if ($status >= 200 && $status < 300) {
            return;
        }
        $result = json_decode($response, true);
        $error = is_array($result) ? ($result['Message'] ?? null) : null;

        throw new \RuntimeException('Bunny refused the purge: ' . (is_string($error) ? rtrim($error, '.') : ($status === 0 ? 'the API could not be reached' : "HTTP {$status}")) . '.');
    }
}
