<?php

declare(strict_types=1);

namespace Starlite\Cdn;

/**
 * The HTTP requests purgers make to CDN APIs, with PHP's own streams (no HTTP client needed). Tests
 * pass purgers their own sender instead.
 */
final class Http
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{int, string} status code and body (0 when the API couldn't be reached)
     */
    public static function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $lines = ['User-Agent: Starlite'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body ?? '',
                'timeout' => 15,
                'ignore_errors' => true, // read the API's error message rather than failing on 4xx
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (http_get_last_response_headers() ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1]; // the last one, after any redirect
            }
        }

        return [$status, is_string($response) ? $response : ''];
    }
}
