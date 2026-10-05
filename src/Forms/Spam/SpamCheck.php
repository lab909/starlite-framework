<?php

declare(strict_types=1);

namespace Starlite\Forms\Spam;

use Starlite\Forms\Form;
use Symfony\Component\HttpFoundation\Request;

/**
 * A way to tell people from bots, listed per form in config/forms.php (`spam`). The built-in ones
 * keep everything on this server; others (a proof-of-work challenge, a captcha) can be added with
 * `$app->forms->addSpamCheck()`.
 */
interface SpamCheck
{
    /** Markup printed inside the form by `{{ form_spam('contact') }}`: hidden fields, a widget… */
    public function markup(Form $form): string;

    /** Null if the submission looks human, else why not. */
    public function check(Form $form, Request $request): ?SpamResult;
}
