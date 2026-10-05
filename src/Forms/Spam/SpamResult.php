<?php

declare(strict_types=1);

namespace Starlite\Forms\Spam;

/**
 * Why a submission was rejected. Without a message the visitor sees "sent" as usual, so a bot learns
 * nothing; with one (too many links, too many messages), a person can fix it.
 */
final class SpamResult
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $message = null,
    ) {
    }
}
