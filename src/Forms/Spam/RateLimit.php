<?php

declare(strict_types=1);

namespace Starlite\Forms\Spam;

use Starlite\Forms\Form;
use Symfony\Component\HttpFoundation\Request;

/**
 * At most `$limit` messages per visitor in `$window` seconds ("5/hour" in config/forms.php).
 *
 * Privacy: visitors are told apart by a keyed hash of their IP address (HMAC with APP_SECRET), never
 * the address itself, stored in var/forms/ only for the length of the window. Without the secret the
 * hash can't be turned back into an IP.
 */
final class RateLimit implements SpamCheck
{
    /** @param \Closure(): int $clock */
    public function __construct(
        private readonly int $limit,
        private readonly int $window,
        private readonly string $dir,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly \Closure $clock,
        private readonly \Closure $t,
    ) {
    }

    /**
     * "5/hour", "20/day", "3/minute" → [5, 3600].
     *
     * @return array{int, int} limit, window in seconds
     */
    public static function parse(string $rate): array
    {
        if (!preg_match('#^(\d+)/(minute|hour|day)$#', $rate, $m) || (int) $m[1] < 1) {
            throw new \InvalidArgumentException("rate_limit must look like \"5/hour\" (per minute, hour or day), not \"{$rate}\".");
        }

        return [(int) $m[1], ['minute' => 60, 'hour' => 3600, 'day' => 86400][$m[2]]];
    }

    public function markup(Form $form): string
    {
        return '';
    }

    public function check(Form $form, Request $request): ?SpamResult
    {
        $dir = $this->dir . '/' . $form->name; // each form its own window
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
        $now = ($this->clock)();
        $this->forgetOldVisitors($dir, $now);

        $file = $dir . '/' . hash_hmac('sha256', $form->name . '|' . $request->getClientIp(), $this->secret) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$file}.");
        }
        try {
            flock($handle, LOCK_EX);
            $times = json_decode((string) stream_get_contents($handle), true);
            $times = array_values(array_filter(is_array($times) ? $times : [], fn ($time) => is_int($time) && $time > $now - $this->window));
            if (count($times) >= $this->limit) {
                return new SpamResult('rate_limit', ($this->t)('Too many messages: please try again later.'));
            }
            $times[] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($times));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return null;
    }

    /** Deletes the records of visitors whose window has passed, so nothing outlives it. */
    private function forgetOldVisitors(string $dir, int $now): void
    {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (filemtime($file) < $now - $this->window) {
                @unlink($file);
            }
        }
    }
}
