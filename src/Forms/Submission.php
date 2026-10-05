<?php

declare(strict_types=1);

namespace Starlite\Forms;

/**
 * A form's state for its template: what was sent, the errors, and whether it went out.
 *
 *   {{ form.values.email ?? '' }}   {{ form.errors.email ?? '' }}   {% if form.sent %}Thanks!{% endif %}
 *   {{ form.errors._form ?? '' }}   a message about the whole form (too many messages, expired…)
 */
final class Submission
{
    /**
     * @param array<string, string|bool> $values
     * @param array<string, string>      $errors field (or _form) => message
     * @param string|null                $spam   why it was rejected as spam, for the log; the visitor sees a normal "sent"
     */
    public function __construct(
        public readonly string $form,
        public readonly array $values = [],
        public readonly array $errors = [],
        public readonly ?string $spam = null,
        public readonly bool $sent = false,
    ) {
    }

    public function valid(): bool
    {
        return $this->errors === [] && $this->spam === null;
    }
}
