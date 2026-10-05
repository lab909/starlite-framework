<?php

declare(strict_types=1);

namespace Starlite\Forms\Spam;

use Starlite\Forms\Form;
use Symfony\Component\HttpFoundation\Request;

/**
 * People take a few seconds to fill in a form; bots post at once, or replay an old form. The form
 * carries a token with the time it was shown, signed with APP_SECRET so it can't be forged, and a
 * submission faster than `$minSeconds` is rejected.
 *
 * The token makes every view of the page different, so `$onRender` marks the response as not
 * cacheable (pages with a form are never stored by browsers or CDNs).
 */
final class Timing implements SpamCheck
{
    /** A form left open longer than this must be sent again (with a fresh token). */
    public const MAX_AGE = 86400;

    /**
     * @param \Closure(): void   $onRender called when a token is printed
     * @param \Closure(): int    $clock    the current time
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $minSeconds,
        private readonly \Closure $onRender,
        private readonly \Closure $clock,
        private readonly \Closure $t,
    ) {
    }

    public function markup(Form $form): string
    {
        ($this->onRender)();
        $payload = $form->name . '.' . ($this->clock)();

        return '<input type="hidden" name="_token" value="' . htmlspecialchars($payload . '.' . $this->sign($payload), ENT_QUOTES) . '">';
    }

    public function check(Form $form, Request $request): ?SpamResult
    {
        $parts = explode('.', $request->request->getString('_token'));
        if (count($parts) !== 3 || $parts[0] !== $form->name || !ctype_digit($parts[1])
            || !hash_equals($this->sign($parts[0] . '.' . $parts[1]), $parts[2])) {
            return new SpamResult('timing: missing or forged token');
        }
        $age = ($this->clock)() - (int) $parts[1];
        if ($age < $this->minSeconds) {
            return new SpamResult("timing: sent after {$age}s");
        }
        if ($age > self::MAX_AGE) {
            return new SpamResult('timing: expired', ($this->t)('This form was open too long: please send it again.'));
        }

        return null;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'starlite-form|' . $payload, $this->secret);
    }
}
