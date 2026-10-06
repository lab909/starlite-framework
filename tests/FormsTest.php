<?php

declare(strict_types=1);

namespace Starlite\Tests;

use Starlite\Forms\Form;
use Starlite\Forms\Spam\Timing;
use Starlite\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class FormsTest extends FrameworkTestCase
{
    private const CONTACT = [
        'fields' => [
            'name' => ['type' => 'text', 'required' => true, 'max' => 20],
            'email' => ['type' => 'email', 'required' => true],
            'topic' => ['type' => 'choice', 'choices' => ['question', 'feedback']],
            'message' => ['type' => 'textarea', 'required' => true, 'min' => 5],
            'consent' => ['type' => 'checkbox', 'required' => true],
        ],
        'to' => 'owner@example.test, team@example.test',
        'subject' => 'Message from {name}',
        'spam' => ['honeypot', 'max_links' => 1],
    ];

    private const VALID = ['name' => 'Ada', 'email' => 'ada@example.test', 'topic' => 'question', 'message' => "Hello\nthere", 'consent' => '1'];

    /** @var \ArrayObject<int, Email> what the test transport received */
    private \ArrayObject $sent;

    protected function setUp(): void
    {
        $this->sent = new \ArrayObject();
    }

    /** @param array<string, array<mixed>> $forms */
    private function app(array $forms = ['contact' => self::CONTACT], string $from = 'site@example.test'): Kernel
    {
        $app = $this->kernel(overrides: ['forms' => $forms, 'mailer' => ['dsn' => 'null://null', 'from' => $from]]);
        $app->forms->useTransport(new class ($this->sent) implements TransportInterface {
            /** @param \ArrayObject<int, Email> $sent */
            public function __construct(private readonly \ArrayObject $sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
            {
                \assert($message instanceof Email);
                $this->sent->append($message);

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'memory://';
            }
        });

        return $app;
    }

    /** The email the test transport received. */
    private function email(): Email
    {
        $email = $this->sent[0] ?? null;
        self::assertInstanceOf(Email::class, $email, 'an email was sent');

        return $email;
    }

    /** @param array<string, string> $data */
    private static function post(array $data, string $ip = '203.0.113.7'): Request
    {
        return Request::create('/contact', 'POST', $data, server: ['REMOTE_ADDR' => $ip]);
    }

    public function testAValidSubmissionIsEmailed(): void
    {
        $submission = $this->app()->forms->submit('contact', self::post(self::VALID));

        self::assertTrue($submission->sent);
        self::assertSame([], $submission->errors);
        self::assertCount(1, $this->sent);
        $email = $this->email();
        self::assertSame('"Fixture" <site@example.test>', $email->getFrom()[0]->toString(), 'from this site');
        self::assertSame(['owner@example.test', 'team@example.test'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        self::assertSame('ada@example.test', $email->getReplyTo()[0]->getAddress(), 'the visitor in Reply-To');
        self::assertSame('Message from Ada', $email->getSubject());
        self::assertStringContainsString("message:\n\nHello\nthere", (string) $email->getTextBody());
        self::assertStringContainsString('consent: yes', (string) $email->getTextBody());

        // The log says that it was sent, never what: no name, address or message.
        self::assertStringContainsString('Form "contact" sent.', $this->logged());
        foreach (['Ada', 'ada@example.test', 'Hello'] as $personal) {
            self::assertStringNotContainsString($personal, $this->logged());
        }
    }

    public function testValuesAreCheckedWithMessagesInThePagesLanguage(): void
    {
        $app = $this->app();
        $invalid = ['name' => str_repeat('x', 21), 'email' => 'not-an-email', 'topic' => 'other', 'message' => 'hi'];

        $errors = $app->forms->submit('contact', self::post($invalid))->errors;
        self::assertSame([
            'name' => 'Use at most 20 characters.',
            'email' => 'Enter a valid email address.',
            'topic' => 'Choose one of the options.',
            'message' => 'Use at least 5 characters.',
            'consent' => 'This field is required.',
        ], $errors);
        self::assertCount(0, $this->sent);

        $app->site->enter('it', '/contact');
        $italian = $app->forms->submit('contact', self::post(['email' => 'ada@example.test'] + $invalid))->errors;
        self::assertSame(['Usa al massimo 20 caratteri.', 'Campo obbligatorio.'], [$italian['name'], $italian['consent']]);
    }

    public function testValuesAreCleanedUp(): void
    {
        $submission = $this->app()->forms->submit('contact', self::post(['name' => "  Ada\r\nBcc: x@evil.test ", 'message' => "Line 1\r\nLine 2\0"] + self::VALID));

        self::assertSame('AdaBcc: x@evil.test', $submission->values['name'], 'single-line fields lose their line breaks');
        self::assertSame("Line 1\nLine 2", $submission->values['message']);
        self::assertStringNotContainsString("\n", $this->email()->getSubject() ?? '', 'nothing can add a header');
        self::assertSame(['owner@example.test', 'team@example.test'], array_map(static fn ($a) => $a->getAddress(), array_merge($this->email()->getTo(), $this->email()->getBcc())));
    }

    public function testCustomRules(): void
    {
        $app = $this->app();
        $app->forms->rule('contact', 'message', static fn (mixed $value) => str_contains((string) $value, 'casino') ? 'No, thanks.' : null);

        self::assertSame(['message' => 'No, thanks.'], $app->forms->submit('contact', self::post(['message' => 'Visit my casino'] + self::VALID))->errors);
        $this->expectExceptionMessage('Form "contact" has no field "phone".');
        $app->forms->rule('contact', 'phone', static fn () => null);
    }

    public function testTheHoneypotLooksLikeSuccessToBots(): void
    {
        $app = $this->app();

        self::assertStringContainsString('name="website"', $app->forms->spamMarkup('contact'));
        $submission = $app->forms->submit('contact', self::post(['website' => 'https://spam.test'] + self::VALID));
        self::assertTrue($submission->sent, 'the bot sees "sent"');
        self::assertSame('honeypot', $submission->spam);
        self::assertCount(0, $this->sent, 'nothing was emailed');
        self::assertStringContainsString('Form "contact" rejected as spam (honeypot).', $this->logged());
        self::assertStringNotContainsString('spam.test', $this->logged());
    }

    public function testTooManyLinksAreExplained(): void
    {
        $submission = $this->app()->forms->submit('contact', self::post(['message' => 'See https://a.test and www.b.test'] + self::VALID));

        self::assertFalse($submission->sent);
        self::assertSame(['_form' => 'Please include at most 1 links.'], $submission->errors);
    }

    public function testTimingNeedsASignedTokenAFewSecondsOld(): void
    {
        $now = 1_700_000_000;
        $timing = new Timing('secret', 3, static fn () => null, static function () use (&$now): int {
            return $now;
        }, static fn (string $message) => $message);
        $form = new Form('contact', self::CONTACT);

        self::assertSame(1, preg_match('/value="([^"]+)"/', $timing->markup($form), $m));
        $token = html_entity_decode((string) ($m[1] ?? ''));
        $check = static fn (string $token) => $timing->check($form, self::post(['_token' => $token]))?->reason;

        self::assertSame('timing: sent after 0s', $check($token), 'too fast');
        $now += 5;
        self::assertNull($check($token));
        self::assertSame('timing: missing or forged token', $check(substr($token, 0, -1) . 'x'));
        self::assertSame('timing: missing or forged token', $check(str_replace('contact.', 'other.', $token)));
        self::assertSame('timing: missing or forged token', $check(''));
        $now += 86400;
        self::assertSame('timing: expired', $check($token));
    }

    public function testPagesThatPrintATokenAreNeverCached(): void
    {
        $app = $this->kernel(overrides: ['forms' => ['contact' => ['spam' => ['timing']] + self::CONTACT]]);
        $app->get('/form', fn () => $app->twig->createTemplate("<form>{{ form_spam('contact') }}</form>")->render(), 'form');

        $response = $this->request($app, '/form');
        self::assertStringContainsString('name="_token"', $this->body($response));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertNull($response->getEtag());
        self::assertNotNull($this->request($app, '/')->getEtag(), 'other pages are cached as usual');
    }

    public function testTheRateLimitStoresNoIpAddress(): void
    {
        $forms = ['contact' => ['spam' => ['rate_limit' => '2/hour']] + self::CONTACT];
        $cache = $this->tempDir('cache');
        $app = $this->kernel(overrides: ['forms' => $forms, 'cache_dir' => $cache, 'mailer' => ['dsn' => 'null://null', 'from' => 's@example.test']]);
        $app->forms->useTransport(new \Symfony\Component\Mailer\Transport\NullTransport());

        self::assertTrue($app->forms->submit('contact', self::post(self::VALID))->sent);
        self::assertTrue($app->forms->submit('contact', self::post(self::VALID))->sent);
        self::assertSame(['_form' => 'Too many messages: please try again later.'], $app->forms->submit('contact', self::post(self::VALID))->errors);
        self::assertTrue($app->forms->submit('contact', self::post(self::VALID, ip: '198.51.100.1'))->sent, 'another visitor');

        $stored = '';
        foreach (glob($cache . '/forms/rate-limits/contact/*') ?: [] as $file) {
            $stored .= basename($file) . file_get_contents($file);
        }
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('203.0.113.7', $stored);
        self::assertStringNotContainsString(hash('sha256', '203.0.113.7'), $stored, 'not a plain hash either: keyed with APP_SECRET');
    }

    public function testConfigurationErrorsAreClear(): void
    {
        foreach ([
            [['contact' => ['fields' => ['website' => 'text']]], '"website" can\'t be a field name'],
            [['contact' => ['fields' => ['x' => 'phone']]], 'field "x" needs a type: text, email, textarea, choice, checkbox'],
            [['contact' => ['fields' => ['x' => ['type' => 'choice']]]], 'field "x" is a choice: give it "choices"'],
            [['contact' => ['fields' => ['x' => 'text'], 'spam' => ['captcha']]], 'unknown spam check "captcha"'],
            [['contact' => ['fields' => ['x' => 'text'], 'spam' => ['rate_limit' => 'often']]], 'rate_limit must look like "5/hour"'],
            [['contact' => ['fields' => ['x' => 'text'], 'reply_to' => 'x']], '"reply_to" must name an email field'],
        ] as [$forms, $message]) {
            try {
                $this->kernel(overrides: ['forms' => $forms]);
                self::fail("Must be refused: {$message}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testSendingNeedsAMailerAndASender(): void
    {
        $app = $this->kernel(overrides: ['forms' => ['contact' => self::CONTACT], 'mailer' => ['dsn' => null, 'from' => 'site@example.test']]);

        $this->expectExceptionMessage('No mailer: set MAILER_DSN in .env');
        $app->forms->submit('contact', self::post(self::VALID));
    }
}
