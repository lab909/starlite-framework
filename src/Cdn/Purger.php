<?php

declare(strict_types=1);

namespace Starlite\Cdn;

/**
 * Drops pages from a CDN's cache, so it fetches them again from the site: after a deploy (everything)
 * or with `bin/console cdn:purge <url>` (a few pages). Starlite has Cloudflare, Bunny and a shell
 * command built in (config/app.php `cdn.purge`); a package adds another CDN with
 * `$app->cdn->usePurger(new FastlyPurger(…))`.
 *
 * Methods throw when the CDN refuses (wrong credentials, unknown zone), with a message that says why
 * and never contains the credentials.
 */
interface Purger
{
    /** The CDN's name, for messages: "Cloudflare". */
    public function name(): string;

    public function purgeAll(): void;

    /** @param list<string> $urls absolute URLs, e.g. https://example.com/blog/my-post */
    public function purge(array $urls): void;
}
