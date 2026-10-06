<?php

declare(strict_types=1);

namespace Starlite\Cdn;

/**
 * Any CDN with a command-line tool or a script of your own (CDN_PURGE_CMD): the command runs in the
 * project root with the URLs to purge as arguments, or none to purge everything.
 *
 *   CDN_PURGE_CMD="bin/purge-cdn"      →  bin/purge-cdn 'https://example.com/blog/my-post'
 */
final class CommandPurger implements Purger
{
    public function __construct(private readonly string $command, private readonly string $root)
    {
        if (trim($command) === '') {
            throw new \InvalidArgumentException('Purging with a command needs CDN_PURGE_CMD, e.g. "bin/purge-cdn".');
        }
    }

    public function name(): string
    {
        return 'the purge command';
    }

    public function purgeAll(): void
    {
        $this->run([]);
    }

    public function purge(array $urls): void
    {
        $this->run($urls);
    }

    /** @param list<string> $urls */
    private function run(array $urls): void
    {
        $command = 'cd ' . escapeshellarg($this->root) . ' && ' . $this->command;
        foreach ($urls as $url) {
            $command .= ' ' . escapeshellarg($url);
        }
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException("The purge command failed (exit code {$code}): " . trim(implode("\n", array_slice($output, -5))));
        }
    }
}
