<?php

declare(strict_types=1);

namespace Starlite\Forms;

use Starlite\Forms\Spam\Honeypot;
use Starlite\Forms\Spam\MaxLinks;
use Starlite\Forms\Spam\RateLimit;
use Starlite\Forms\Spam\SpamCheck;
use Starlite\Forms\Spam\Timing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Forms defined in config/forms.php (see Form): validation in the page's language, spam checks,
 * and sending by email with Symfony Mailer (MAILER_DSN, MAILER_FROM). Submissions are emailed, never
 * stored; the log records only that a form was sent or rejected, and why, never what was in it.
 *
 *   $submission = $app->forms->submit('contact', $request);   // validates, checks spam, sends
 *   {{ form_spam('contact') }}                                  // in the form: the spam checks' fields
 */
final class Forms
{
    /** @var array<string, Form> */
    public readonly array $forms;

    /** @var array<string, array<string, list<\Closure(mixed, array<string, mixed>): ?string>>> form => field => rules */
    private array $rules = [];

    /** @var array<string, \Closure(mixed, Form): SpamCheck> name => factory */
    private array $spamChecks = [];

    private ?TransportInterface $transport = null;

    /**
     * @param array<string, array<mixed>>                    $config     config/forms.php
     * @param \Closure(string, array<string, mixed>): string $t          translates a UI text
     * @param \Closure(): void                               $noStore    marks the current response as not cacheable
     * @param \Closure(): int|null                           $clock      the current time (tests pass their own)
     */
    public function __construct(
        array $config,
        private readonly Environment $twig,
        private readonly \Closure $t,
        \Closure $noStore,
        #[\SensitiveParameter] string $secret,
        string $dataDir,
        private readonly ?string $mailerDsn,
        private readonly ?string $mailerFrom,
        private readonly string $siteName,
        ?\Closure $clock = null,
    ) {
        $forms = [];
        foreach ($config as $name => $definition) {
            $forms[(string) $name] = new Form((string) $name, (array) $definition);
        }
        $this->forms = $forms;
        $clock ??= time(...);

        // The built-in checks: everything stays on this server.
        $this->addSpamCheck('honeypot', static fn () => new Honeypot());
        $this->addSpamCheck('timing', static fn (mixed $seconds) => new Timing($secret, is_int($seconds) ? $seconds : 3, $noStore, $clock, $t));
        $this->addSpamCheck('max_links', static fn (mixed $max) => new MaxLinks(is_int($max) ? $max : 2, $t));
        $this->addSpamCheck('rate_limit', static function (mixed $rate) use ($dataDir, $secret, $clock, $t) {
            [$limit, $window] = RateLimit::parse(is_string($rate) ? $rate : '5/hour');

            return new RateLimit($limit, $window, $dataDir . '/rate-limits', $secret, $clock, $t);
        });
        foreach ($forms as $form) {
            $this->checks($form); // unknown check names and bad options fail at boot
        }
    }

    public function get(string $name): Form
    {
        return $this->forms[$name] ?? throw new \InvalidArgumentException(
            "Unknown form \"{$name}\". Forms: " . (implode(', ', array_keys($this->forms)) ?: 'none') . ' (config/forms.php).',
        );
    }

    /**
     * A custom rule for a field, e.g. from config/bootstrap.php:
     *   $app->forms->rule('contact', 'message', fn ($value) => str_contains($value, 'casino') ? $app->t('No, thanks.') : null);
     * It gets the field's value and all values, and returns an error message or null.
     *
     * @param \Closure(mixed, array<string, mixed>): ?string $rule
     */
    public function rule(string $form, string $field, \Closure $rule): void
    {
        if (!isset($this->get($form)->fields[$field])) {
            throw new \InvalidArgumentException("Form \"{$form}\" has no field \"{$field}\".");
        }
        $this->rules[$form][$field][] = $rule;
    }

    /**
     * Adds a spam check forms can list in `spam` (a self-hosted proof of work, a captcha from a package…).
     *
     * @param \Closure(mixed, Form): SpamCheck $factory gets the option from config/forms.php
     */
    public function addSpamCheck(string $name, \Closure $factory): void
    {
        $this->spamChecks[$name] = $factory;
    }

    /** The markup of a form's spam checks, for `{{ form_spam('contact') }}` inside the <form>. */
    public function spamMarkup(string $form): string
    {
        $form = $this->get($form);

        return implode('', array_map(static fn (SpamCheck $check) => $check->markup($form), $this->checks($form)));
    }

    /** A form's state before anything was sent: for the page that shows it. */
    public function blank(string $form): Submission
    {
        return new Submission($this->get($form)->name);
    }

    /**
     * Validates what was sent, runs the spam checks and, if everything is fine, sends the email.
     * Spam without a message for the visitor comes back as "sent", so bots learn nothing.
     */
    public function submit(string $name, Request $request): Submission
    {
        $form = $this->get($name);
        [$values, $errors] = $form->validate($request->request->all(), $this->t, $this->rules[$name] ?? []);
        if ($errors !== []) {
            return new Submission($name, $values, $errors);
        }
        foreach ($this->checks($form) as $check) {
            $result = $check->check($form, $request);
            if ($result !== null) {
                error_log("Form \"{$name}\" rejected as spam ({$result->reason}).");

                return $result->message === null
                    ? new Submission($name, $values, [], $result->reason, sent: true)
                    : new Submission($name, $values, ['_form' => $result->message], $result->reason);
            }
        }
        $this->send($form, $values);
        error_log("Form \"{$name}\" sent.");

        return new Submission($name, $values, sent: true);
    }

    /** For tests: deliver through this transport instead of MAILER_DSN. */
    public function useTransport(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /** @param array<string, string|bool> $values */
    private function send(Form $form, array $values): void
    {
        if ($form->to === []) {
            throw new \LogicException("Form \"{$form->name}\" has no recipient: set \"to\" in config/forms.php.");
        }
        if ($this->transport === null && ($this->mailerDsn === null || $this->mailerDsn === '')) {
            throw new \LogicException('No mailer: set MAILER_DSN in .env, e.g. smtp://user:pass@smtp.example.com:587.');
        }
        if ($this->mailerFrom === null || $this->mailerFrom === '') {
            throw new \LogicException('No sender: set MAILER_FROM in .env, an address of your own domain.');
        }
        $template = $this->twig->getLoader()->exists("_emails/{$form->name}.txt.twig") ? "_emails/{$form->name}.txt.twig" : '_emails/form.txt.twig';
        // Placeholders for the subject: one line each, whatever was typed.
        $placeholders = array_map(static fn ($value) => is_bool($value) ? ($value ? '✓' : '–') : (string) preg_replace('/\s+/u', ' ', $value), $values);

        $email = (new Email())
            ->from(new Address($this->mailerFrom, $this->siteName))
            ->to(...$form->to)
            ->subject(($this->t)($form->subject, ['form' => $form->name, 'site' => $this->siteName] + $placeholders))
            ->text($this->twig->render($template, ['form' => $form, 'values' => $values, 'site_name' => $this->siteName]));
        // The visitor goes in Reply-To, never From: the message comes from this site (SPF, DKIM, DMARC).
        $replyTo = $form->replyTo !== null ? $values[$form->replyTo] ?? '' : '';
        if (is_string($replyTo) && $replyTo !== '') {
            $email->replyTo($replyTo);
        }

        (new Mailer($this->transport ??= Transport::fromDsn((string) $this->mailerDsn)))->send($email);
    }

    /** @return list<SpamCheck> */
    private function checks(Form $form): array
    {
        $checks = [];
        foreach ($form->spam as $name => $option) {
            [$name, $option] = is_int($name) ? [(string) $option, null] : [$name, $option];
            $factory = $this->spamChecks[$name] ?? throw new \InvalidArgumentException(
                "config/forms.php \"{$form->name}\": unknown spam check \"{$name}\" (" . implode(', ', array_keys($this->spamChecks)) . ').',
            );
            $checks[] = $factory($option, $form);
        }

        return $checks;
    }
}
