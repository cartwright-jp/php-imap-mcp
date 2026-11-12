<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

use Cartwright\ImapMcp\Auth\Account;
use DateTimeImmutable;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Email;

/**
 * Builds the RFC 5322 message saved by save_draft (SPEC.md 6, 9): UTF-8, subject and names encoded per
 * RFC 2047, threading headers for replies. symfony/mime only assembles the message; nothing is sent.
 */
final class DraftBuilder
{
    /**
     * @param list<MimeAddress> $to
     * @param list<MimeAddress> $cc
     * @param list<string> $references Message-IDs without angle brackets, oldest first
     */
    public static function build(
        Account $account,
        array $to,
        array $cc,
        string $subject,
        string $body,
        ?string $inReplyTo,
        array $references,
        DateTimeImmutable $date,
    ): Draft {
        $messageId = bin2hex(random_bytes(16)) . '@' . self::domain($account->email);
        $email = (new Email())
            ->from(new MimeAddress($account->email, $account->fromName ?? ''))
            ->to(...$to)
            ->subject($subject)
            ->date($date)
            ->text(str_replace(["\r\n", "\r"], "\n", $body), 'utf-8');
        if ($cc !== []) {
            $email->cc(...$cc);
        }
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);
        if ($inReplyTo !== null) {
            $headers->addIdHeader('In-Reply-To', $inReplyTo);
        }
        if ($references !== []) {
            $headers->addIdHeader('References', $references);
        }

        return new Draft($email->toString(), $messageId);
    }

    private static function domain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? 'localhost' : substr($email, $at + 1);
    }
}
