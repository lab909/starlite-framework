<?php

declare(strict_types=1);

namespace Starlite\Log;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\SymfonyMailerHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Emails log records, for Log's alerts. When the email can't be sent (SMTP down, wrong password), it
 * says so in the log file instead of failing: the error itself is already there.
 */
final class AlertMailHandler extends SymfonyMailerHandler
{
    public function __construct(TransportInterface $transport, Email $email, private readonly HandlerInterface $fallback)
    {
        // Debug: every line the fingers-crossed buffer passes on goes in the email, not just the error.
        parent::__construct($transport, $email, Level::Debug);
    }

    protected function send(string $content, array $records): void
    {
        try {
            parent::send($content, $records);
        } catch (\Throwable $e) {
            $this->fallback->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'The alert email could not be sent: ' . $e->getMessage()));
        }
    }
}
