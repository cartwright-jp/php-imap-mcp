<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

use DateTimeImmutable;

final class MessageDetail
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_HTML = 'html_converted';

    /**
     * @param list<Address> $from
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $replyTo
     * @param list<string> $references
     * @param list<AttachmentInfo> $attachments
     */
    public function __construct(
        public readonly int $uid,
        public readonly int $uidValidity,
        public readonly ?DateTimeImmutable $date,
        public readonly array $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $replyTo,
        public readonly string $subject,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly bool $seen,
        public readonly bool $flagged,
        /** Readable body as plain text. Untrusted. */
        public readonly string $body,
        public readonly string $bodyFormat,
        public readonly bool $bodyTruncated,
        public readonly array $attachments,
    ) {
    }
}
