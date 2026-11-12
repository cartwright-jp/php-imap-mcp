<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

use DateTimeImmutable;

final class MessageSummary
{
    /**
     * @param list<Address> $from
     * @param list<Address> $to
     */
    public function __construct(
        public readonly int $uid,
        public readonly ?DateTimeImmutable $date,
        public readonly array $from,
        public readonly array $to,
        public readonly string $subject,
        public readonly bool $seen,
        public readonly bool $flagged,
        public readonly bool $hasAttachments,
        public readonly int $size,
        /** Start of the readable body, whitespace collapsed. Untrusted. */
        public readonly string $snippet,
    ) {
    }
}
