<?php

declare(strict_types=1);

namespace Starlite;

use starfederation\datastar\events\EventInterface;
use starfederation\datastar\ServerSentEventGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig integration modelled on the Craft Datastar plugin:
 *
 *   {{ datastar.get('_partials/search', {limit: 10}) }}   -> @get(...) to render a template
 *   {{ datastar.action('post', '/clock') }}               -> @post(...) to a custom route
 *   {% apply patch_elements %}...{% endapply %}           -> patch elements
 *   {% do patch_signals({foo: 1}) %}                      -> patch signals
 *   {% do remove_elements('#old') %}
 *   {% do execute_script('alert(1)') %}
 *   {% do location('/somewhere') %}
 *
 * A template that queues no events has its whole output patched as elements.
 */
final class Datastar extends AbstractExtension implements GlobalsInterface
{
    /** @var list<\Closure(ServerSentEventGenerator): mixed> */
    private array $queue = [];

    public function __construct(
        private readonly string $secret,
        private readonly ?Site $site = null,
        private readonly string $endpoint = '/datastar',
    ) {
    }

    public function getGlobals(): array
    {
        return ['datastar' => $this];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('patch_elements', $this->patchElements(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('patch_signals', $this->patchSignals(...)),
            new TwigFunction('remove_elements', $this->removeElements(...)),
            new TwigFunction('execute_script', $this->executeScript(...)),
            new TwigFunction('location', $this->location(...)),
        ];
    }

    // --- Backend actions that render a template -----------------------------

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     */
    public function get(string $template, array $vars = [], array $options = []): string
    {
        return $this->action('get', $this->templateUrl($template, $vars), $options);
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     */
    public function post(string $template, array $vars = [], array $options = []): string
    {
        return $this->action('post', $this->templateUrl($template, $vars), $options);
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     */
    public function put(string $template, array $vars = [], array $options = []): string
    {
        return $this->action('put', $this->templateUrl($template, $vars), $options);
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     */
    public function patch(string $template, array $vars = [], array $options = []): string
    {
        return $this->action('patch', $this->templateUrl($template, $vars), $options);
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $options
     */
    public function delete(string $template, array $vars = [], array $options = []): string
    {
        return $this->action('delete', $this->templateUrl($template, $vars), $options);
    }

    /**
     * Any backend action to any URL. CSRF needs no token: the kernel checks the browser's origin headers.
     *
     * @param array<string, mixed> $options
     */
    public function action(string $method, string $url, array $options = []): string
    {
        $method = strtolower($method);
        $args = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($options !== []) {
            $args .= ', ' . json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return "@{$method}({$args})";
    }

    // --- Signed, tamper-proof template config -------------------------------

    /** @param array<string, mixed> $vars */
    private function templateUrl(string $template, array $vars): string
    {
        $payload = self::base64url(json_encode(['t' => $template, 'v' => $vars], JSON_THROW_ON_ERROR));

        // Prefixed with the current language (/it/datastar), so the partial renders in that language.
        return ($this->site?->prefix() ?? '') . $this->endpoint . '?config=' . $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    /** @return array{0: string, 1: array<string, mixed>}|null */
    public function decode(string $config): ?array
    {
        $dot = strrpos($config, '.');
        if ($dot === false) {
            return null;
        }
        $payload = substr($config, 0, $dot);
        if (!hash_equals(hash_hmac('sha256', $payload, $this->secret), substr($config, $dot + 1))) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($data) || !is_string($data['t'] ?? null) || !is_array($data['v'] ?? null)) {
            return null;
        }

        return [$data['t'], $data['v']];
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // --- Queued events (run in template order) ------------------------------

    /** @param array<string, mixed> $options */
    public function patchElements(string $html, array $options = []): string
    {
        $this->queue[] = static fn (ServerSentEventGenerator $sse) => $sse->patchElements($html, $options);

        return '';
    }

    /**
     * @param array<string, mixed> $signals
     * @param array<string, mixed> $options
     */
    public function patchSignals(array $signals, array $options = []): string
    {
        $this->queue[] = static fn (ServerSentEventGenerator $sse) => $sse->patchSignals($signals, $options);

        return '';
    }

    /** @param array<string, mixed> $options */
    public function removeElements(string $selector, array $options = []): string
    {
        $this->queue[] = static fn (ServerSentEventGenerator $sse) => $sse->removeElements($selector, $options);

        return '';
    }

    /** @param array<string, mixed> $options */
    public function executeScript(string $script, array $options = []): string
    {
        $this->queue[] = static fn (ServerSentEventGenerator $sse) => $sse->executeScript($script, $options);

        return '';
    }

    /** @param array<string, mixed> $options */
    public function location(string $uri, array $options = []): string
    {
        $this->queue[] = static fn (ServerSentEventGenerator $sse) => $sse->location($uri, $options);

        return '';
    }

    /** The queued events (or the whole output, if nothing was queued) as an SSE response. */
    public function response(string $output): StreamedResponse
    {
        $events = $this->events($output);

        return new StreamedResponse(static function () use ($events): void {
            ignore_user_abort(false);
            echo $events;
            flush();
        }, 200, ServerSentEventGenerator::headers());
    }

    /**
     * The queued events (or the whole output, if nothing was queued) rendered as SSE text. Rendering
     * up front keeps template errors inside the normal error handling and makes responses testable.
     */
    public function events(string $output): string
    {
        $queue = $this->queue;
        $this->queue = [];
        if ($queue === [] && trim($output) !== '') {
            $queue[] = static fn (ServerSentEventGenerator $sse) => $sse->patchElements($output);
        }

        // Collects the SDK's event text instead of echoing it (sendEvent() echoes and flushes buffers).
        $sse = new class() extends ServerSentEventGenerator {
            public string $output = '';

            public function __construct()
            {
            }

            protected function sendEvent(EventInterface $event): string
            {
                $output = $event->getOutput();
                $this->output .= $output;

                return $output;
            }
        };
        foreach ($queue as $event) {
            $event($sse);
        }

        return $sse->output;
    }
}
