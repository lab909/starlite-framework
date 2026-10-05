<?php

declare(strict_types=1);

namespace Starlite\Forms;

/**
 * One form from config/forms.php, and the validation of what a visitor sent.
 *
 *   'contact' => [
 *       'fields' => [
 *           'name' => ['type' => 'text', 'required' => true, 'max' => 100],
 *           'email' => ['type' => 'email', 'required' => true],
 *           'topic' => ['type' => 'choice', 'choices' => ['question', 'feedback']],
 *           'message' => ['type' => 'textarea', 'required' => true, 'min' => 10],
 *           'consent' => ['type' => 'checkbox', 'required' => true],
 *       ],
 *       'to' => getenv('CONTACT_TO'),          // recipients, comma-separated
 *       'subject' => 'Message from {name}',     // translated, with the values as placeholders
 *       'reply_to' => 'email',                  // default: the first email field
 *       'spam' => ['honeypot', 'timing' => 3, 'max_links' => 2, 'rate_limit' => '5/hour'],
 *   ],
 *
 * Validation messages are UI texts (translations/<code>.php), so they follow the page's language.
 */
final class Form
{
    public const TYPES = ['text', 'email', 'textarea', 'choice', 'checkbox'];

    /** Names the spam checks use: forms can't have fields called like that. */
    public const RESERVED = ['website', '_token'];

    /** Upper limits even when `max` isn't set: nobody needs more, and they bound what's emailed. */
    private const MAX = ['text' => 200, 'email' => 254, 'textarea' => 10000, 'choice' => 200, 'checkbox' => 1];

    /** @var array<string, array{type: string, required: bool, min: ?int, max: int, choices: list<string>}> */
    public readonly array $fields;

    /** @var list<string> */
    public readonly array $to;

    public readonly string $subject;
    public readonly ?string $replyTo;

    /** @var array<string|int, mixed> spam check name => option (or a list of names) */
    public readonly array $spam;

    /** @param array<mixed> $config */
    public function __construct(public readonly string $name, array $config)
    {
        $where = "config/forms.php \"{$name}\"";
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException("{$where}: form names use lowercase letters, digits, dashes and underscores.");
        }
        $unknown = array_diff(array_keys($config), ['fields', 'to', 'subject', 'reply_to', 'spam']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$where}: unknown option \"" . implode('", "', $unknown) . '" (fields, to, subject, reply_to, spam).');
        }
        if (!is_array($config['fields'] ?? null) || $config['fields'] === []) {
            throw new \InvalidArgumentException("{$where}: needs \"fields\".");
        }

        $fields = [];
        foreach ($config['fields'] as $field => $definition) {
            $definition = is_string($definition) ? ['type' => $definition] : $definition;
            if (!is_string($field) || !preg_match('/^[a-z][a-z0-9_]*$/', $field) || in_array($field, self::RESERVED, true)) {
                throw new \InvalidArgumentException("{$where}: \"{$field}\" can't be a field name (lowercase letters, digits, underscores; not " . implode(', ', self::RESERVED) . ').');
            }
            if (!is_array($definition) || !in_array($definition['type'] ?? null, self::TYPES, true)) {
                throw new \InvalidArgumentException("{$where}: field \"{$field}\" needs a type: " . implode(', ', self::TYPES) . '.');
            }
            $type = $definition['type'];
            $extra = array_diff(array_keys($definition), ['type', 'required', 'min', 'max', 'choices']);
            if ($extra !== []) {
                throw new \InvalidArgumentException("{$where}: field \"{$field}\" has unknown option \"" . implode('", "', $extra) . '" (type, required, min, max, choices).');
            }
            $choices = $definition['choices'] ?? [];
            if ($type === 'choice' && (!is_array($choices) || $choices === [] || !array_is_list($choices))) {
                throw new \InvalidArgumentException("{$where}: field \"{$field}\" is a choice: give it \"choices\", a list of values.");
            }
            $fields[$field] = [
                'type' => $type,
                'required' => (bool) ($definition['required'] ?? false),
                'min' => isset($definition['min']) ? (int) $definition['min'] : null,
                'max' => min((int) ($definition['max'] ?? self::MAX[$type]), self::MAX[$type]),
                'choices' => array_values(array_map('strval', (array) $choices)),
            ];
        }
        $this->fields = $fields;

        $this->to = array_values(array_filter(array_map('trim', explode(',', (string) ($config['to'] ?? '')))));
        $this->subject = (string) ($config['subject'] ?? 'Message from the {form} form');

        $replyTo = $config['reply_to'] ?? null;
        if ($replyTo !== null && ($fields[$replyTo]['type'] ?? null) !== 'email') {
            throw new \InvalidArgumentException("{$where}: \"reply_to\" must name an email field.");
        }
        $emails = array_keys(array_filter($fields, static fn (array $f) => $f['type'] === 'email'));
        $this->replyTo = $replyTo ?? ($emails[0] ?? null);

        $this->spam = (array) ($config['spam'] ?? ['honeypot', 'timing' => 3, 'max_links' => 2, 'rate_limit' => '5/hour']);
    }

    /**
     * Normalises and checks the submitted values.
     *
     * @param array<mixed>                                                  $input the request's form data
     * @param \Closure(string, array<string, mixed>): string                $t     translates a message
     * @param array<string, list<\Closure(mixed, array<string, mixed>): ?string>> $rules custom rules per field
     *
     * @return array{array<string, string|bool>, array<string, string>} values, field => error message
     */
    public function validate(array $input, \Closure $t, array $rules = []): array
    {
        $values = $errors = [];
        foreach ($this->fields as $field => $definition) {
            $raw = $input[$field] ?? null;
            if ($definition['type'] === 'checkbox') {
                $values[$field] = in_array($raw, ['1', 'on', 'true', 'yes'], true);
                if ($definition['required'] && !$values[$field]) {
                    $errors[$field] = $t('This field is required.', []);
                }
                continue;
            }
            // One string, without control characters (newlines only in a textarea).
            $value = is_string($raw) ? str_replace(["\r\n", "\r"], "\n", $raw) : '';
            $value = trim((string) preg_replace($definition['type'] === 'textarea' ? '/[^\P{C}\n\t]/u' : '/\p{C}/u', '', $value));
            $values[$field] = $value;

            $error = match (true) {
                $value === '' => $definition['required'] ? $t('This field is required.', []) : null,
                $definition['type'] === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false => $t('Enter a valid email address.', []),
                $definition['type'] === 'choice' && !in_array($value, $definition['choices'], true) => $t('Choose one of the options.', []),
                $definition['min'] !== null && mb_strlen($value) < $definition['min'] => $t('Use at least {min} characters.', ['min' => $definition['min']]),
                mb_strlen($value) > $definition['max'] => $t('Use at most {max} characters.', ['max' => $definition['max']]),
                default => null,
            };
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }
        foreach ($rules as $field => $checks) {
            foreach ($checks as $check) {
                if (!isset($errors[$field]) && ($error = $check($values[$field] ?? null, $values)) !== null) {
                    $errors[$field] = $error;
                }
            }
        }

        return [$values, $errors];
    }
}
