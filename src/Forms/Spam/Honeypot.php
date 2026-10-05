<?php

declare(strict_types=1);

namespace Starlite\Forms\Spam;

use Starlite\Forms\Form;
use Symfony\Component\HttpFoundation\Request;

/**
 * A field people never see or reach, which form-filling bots happily complete. Moved off-screen with
 * an inline style (no CSS needed), out of the tab order and hidden from screen readers.
 */
final class Honeypot implements SpamCheck
{
    public function markup(Form $form): string
    {
        return '<div aria-hidden="true" style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden">'
            . '<label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>';
    }

    public function check(Form $form, Request $request): ?SpamResult
    {
        return $request->request->getString('website') !== '' ? new SpamResult('honeypot') : null;
    }
}
