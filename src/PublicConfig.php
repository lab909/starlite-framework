<?php

declare(strict_types=1);

namespace Starlite;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Config values the browser may read: only what config/app.php lists under `public`.
 *
 * `{{ public_config() }}` prints them as a JSON data block, read in JavaScript with `publicConfig()`
 * from the 'starlite' module. It's an allowlist: nothing else from the config ever reaches the page,
 * and a value containing APP_SECRET is refused outright. A data block isn't executed, so it needs no
 * Content Security Policy hash, and it's the same for every visitor, so pages stay cacheable.
 */
final class PublicConfig extends AbstractExtension
{
    public const ELEMENT_ID = 'starlite-config';

    /** @param array<string, mixed> $values */
    public function __construct(
        public readonly array $values,
        #[\SensitiveParameter] string $secret,
    ) {
        self::assertNoSecret($values, $secret);
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('public_config', $this->tag(...), ['is_safe' => ['html']])];
    }

    /** The `<script type="application/json">` block, or nothing when no value is public. */
    public function tag(): string
    {
        if ($this->values === []) {
            return '';
        }
        // JSON_HEX_*: `</script>`, `<!--` and quotes can't end the block or break out of it.
        $json = json_encode(
            $this->values,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return '<script type="application/json" id="' . self::ELEMENT_ID . '">' . $json . '</script>';
    }

    /** @param array<mixed> $values */
    private static function assertNoSecret(array $values, string $secret, string $path = 'public'): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                self::assertNoSecret($value, $secret, $path . '.' . $key);
            } elseif ($secret !== '' && is_string($value) && str_contains($value, $secret)) {
                throw new \LogicException("config/app.php \"{$path}.{$key}\" contains APP_SECRET: it would be sent to every browser.");
            }
        }
    }
}
