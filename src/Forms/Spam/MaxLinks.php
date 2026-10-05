<?php

declare(strict_types=1);

namespace Starlite\Forms\Spam;

use Starlite\Forms\Form;
use Symfony\Component\HttpFoundation\Request;

/** Most spam is links: more than `$max` in the whole message is refused, with a message a person can act on. */
final class MaxLinks implements SpamCheck
{
    /** @param \Closure(string, array<string, mixed>): string $t */
    public function __construct(private readonly int $max, private readonly \Closure $t)
    {
    }

    public function markup(Form $form): string
    {
        return '';
    }

    public function check(Form $form, Request $request): ?SpamResult
    {
        $links = 0;
        foreach (array_keys($form->fields) as $field) {
            $links += preg_match_all('#https?://|www\.#i', $request->request->getString($field));
        }

        return $links > $this->max
            ? new SpamResult("max_links: {$links}", ($this->t)('Please include at most {max} links.', ['max' => $this->max]))
            : null;
    }
}
