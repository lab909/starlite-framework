<?php

declare(strict_types=1);

namespace Starlite;

use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * UI translations: translations/<language>.php returns [source text => translation].
 *
 *   {{ 'Load more'|t }}                                  Twig filter
 *   {{ t('{count} posts', {count: 3}) }}                 Twig function
 *   $this->t('Post not found.')                          controllers (Kernel::t)
 *
 * Messages use the ICU format ({name} placeholders, plurals, …) through symfony/translation.
 * A missing translation shows the source text itself (no fallback to the default language: on an
 * Italian-default site with English templates, a missing English text must stay English). So keys
 * are simply the texts as written in the templates, and that language's file may stay almost empty.
 * Compiled catalogues are cached in var/cache/translations (when not in debug).
 */
final class Translations extends AbstractExtension
{
    /** Symfony formats a domain with ICU MessageFormat when its name ends in "+intl-icu". */
    private const DOMAIN = 'messages';

    private readonly Translator $translator;
    private readonly IntlFormatter $formatter;

    public function __construct(
        string $dir,
        private readonly Site $site,
        ?string $cacheDir = null,
        bool $debug = false,
    ) {
        $this->translator = new Translator($site->defaultLanguage, null, $cacheDir, $debug);
        $this->translator->addLoader('php', new PhpFileLoader());
        foreach (array_keys($site->languages) as $code) {
            if (is_file($file = "{$dir}/{$code}.php")) {
                $this->translator->addResource('php', $file, $code, self::DOMAIN . '+intl-icu');
            }
        }
        $this->formatter = new IntlFormatter();
    }

    /** Translates into the current language (or the given one). */
    public function t(string $message, array $params = [], ?string $language = null): string
    {
        $language ??= $this->site->language();
        // Untranslated text is the source text: format it as ICU too, so {placeholders} still work.
        if (!$this->translator->getCatalogue($language)->has($message, self::DOMAIN)) {
            return $this->formatter->formatIntl($message, $language, $params);
        }

        return $this->translator->trans($message, $params, self::DOMAIN, $language);
    }

    /** Compiles every language's catalogue into the cache. Returns the number of languages. */
    public function warmup(): int
    {
        foreach (array_keys($this->site->languages) as $code) {
            $this->translator->getCatalogue($code);
        }

        return count($this->site->languages);
    }

    public function getFilters(): array
    {
        return [new TwigFilter('t', $this->t(...))];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('t', $this->t(...))];
    }
}
