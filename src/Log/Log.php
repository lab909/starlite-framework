<?php

declare(strict_types=1);

namespace Starlite\Log;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The app's logger (Monolog, config/app.php `log`), built to respect visitors' privacy:
 *
 * - a file per day in var/log/ (app-2026-10-06.log), deleted after `days` (14): logs aren't kept forever;
 *   or a stream such as php://stderr for platforms that collect it;
 * - each entry carries the request's method, path and route (Kernel adds them), never the visitor's IP
 *   address, browser or query string;
 * - exception traces without function arguments in production, where a password or an email address
 *   could be;
 * - optional email alerts (`alert_to`) for errors, through the site's own mailer: one email per distinct
 *   error per hour, with the request's earlier log lines.
 *
 * No third-party service: add a Monolog handler from a package for that ($app->logger->pushHandler()).
 */
final class Log
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** How long an error's alert keeps others like it from being sent, in seconds. */
    public const ALERT_INTERVAL = 3600;

    /**
     * @param array<string, mixed>                $config config/app.php `log`: level, path, days, alert_to
     * @param array{dsn?: ?string, from?: ?string} $mailer    for the alerts
     * @param TransportInterface|null              $transport sends the alerts instead of MAILER_DSN (tests)
     */
    public static function create(string $root, array $config, bool $debug, array $mailer = [], string $siteName = 'Starlite', ?TransportInterface $transport = null): Logger
    {
        $unknown = array_diff(array_keys($config), ['level', 'path', 'days', 'alert_to']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('config/app.php log: unknown option "' . implode('", "', $unknown) . '" (level, path, days, alert_to).');
        }
        $level = (string) ($config['level'] ?? null ?: ($debug ? 'debug' : 'info'));
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException('config/app.php log: "level" (LOG_LEVEL) is one of ' . implode(', ', self::LEVELS) . " (got \"{$level}\").");
        }
        $days = $config['days'] ?? 14;
        if (!is_int($days) || $days < 1) {
            throw new \InvalidArgumentException('config/app.php log: "days" is how many days of logs to keep, at least 1.');
        }
        $path = (string) ($config['path'] ?? null ?: $root . '/var/log/app.log');

        // A stream (php://stderr) as is; a file gets the date in its name, and old ones are deleted.
        $file = str_contains($path, '://')
            ? new StreamHandler($path, $level)
            : new RotatingFileHandler($path, $days, $level);
        $file->setFormatter(self::formatter($root));

        $logger = new Logger('app', [$file], [new PsrLogMessageProcessor(removeUsedContextFields: true)]);

        $alertTo = self::addresses($config['alert_to'] ?? null);
        if ($alertTo !== []) {
            $logger->pushHandler(self::alerts($alertTo, $mailer, $siteName, $root, dirname($path), $file, $transport));
        }

        // A log that can't be written (permissions, full disk) never takes the page down with it.
        $logger->setExceptionHandler(static function (\Throwable $e, LogRecord $record): void {
            error_log("Starlite could not write its log ({$e->getMessage()}): {$record->level->getName()} {$record->message}");
        });

        return $logger;
    }

    /**
     * Errors are emailed with the lines logged before them in the same request (fingers crossed), and
     * an error already emailed in the last hour isn't emailed again (deduplication, in var/log/).
     *
     * @param list<string>                         $to
     * @param array{dsn?: ?string, from?: ?string} $mailer
     */
    private static function alerts(array $to, array $mailer, string $siteName, string $root, string $logDir, HandlerInterface $fallback, ?TransportInterface $transport): HandlerInterface
    {
        $dsn = $mailer['dsn'] ?? null;
        $from = $mailer['from'] ?? null;
        if (($transport === null && ($dsn === null || $dsn === '')) || $from === null || $from === '') {
            throw new \InvalidArgumentException('config/app.php log: "alert_to" (LOG_ALERT_TO) sends email: set MAILER_DSN and MAILER_FROM too.');
        }
        $email = (new Email())
            ->from(new Address($from, $siteName))
            ->to(...$to)
            ->subject("[{$siteName}] %level_name%: %message%");
        $mail = new AlertMailHandler($transport ?? Transport::fromDsn((string) $dsn), $email, $fallback);
        $mail->setFormatter(self::formatter($root));
        $store = str_contains($logDir, '://') ? sys_get_temp_dir() . '/starlite-alerts-' . hash('xxh128', $root) : $logDir . '/alerts.dedup';

        return new FingersCrossedHandler(
            new DeduplicationHandler($mail, $store, Level::Error, self::ALERT_INTERVAL),
            Level::Error,
            bufferSize: 50,
        );
    }

    private static function formatter(string $root): LineFormatter
    {
        $formatter = new LineFormatter(null, 'Y-m-d H:i:s', ignoreEmptyContextAndExtra: true);
        $formatter->includeStacktraces();
        $formatter->setBasePath($root); // src/Foo.php:12 rather than /var/www/html/src/Foo.php:12

        return $formatter;
    }

    /** @return list<string> */
    private static function addresses(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(static fn ($a) => trim((string) $a), $list), static fn ($a) => $a !== ''));
    }
}
