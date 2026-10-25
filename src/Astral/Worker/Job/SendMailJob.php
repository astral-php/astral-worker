<?php

declare(strict_types=1);

namespace Astral\Worker\Job;

use Core\Mailer\Mailer;

/**
 * Envoi d'un e-mail via le Mailer du framework.
 * Payload : ['to' => string, 'subject' => string, 'body' => string]
 */
final class SendMailJob implements JobInterface
{
    public const TRIES = 5;
    public const BACKOFF = 60;

    public function __construct(private Mailer $mailer)
    {
    }

    public function handle(array $payload): void
    {
        $to      = (string) ($payload['to'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $body    = (string) ($payload['body'] ?? '');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || $subject === '' || $body === '') {
            throw new \InvalidArgumentException('SendMailJob : payload invalide (to, subject, body requis).');
        }

        // Mailer::send() : void — échec → RuntimeException (astral-core ^1.2)
        $this->mailer->send($to, $subject, $body);
    }
}
