<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

use DateTimeImmutable;

/**
 * search_emails filters (SPEC.md 6). All conditions must match. Dates compare by day, as IMAP does.
 */
final class SearchCriteria
{
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $subject = null,
        public readonly ?string $text = null,
        /** On or after this day. */
        public readonly ?DateTimeImmutable $since = null,
        /** Before this day. */
        public readonly ?DateTimeImmutable $before = null,
        public readonly bool $unreadOnly = false,
    ) {
    }

    /**
     * A stable fingerprint, so a cursor cannot be reused with other criteria.
     */
    public function fingerprint(string $folder): string
    {
        return substr(hash('sha256', json_encode([
            $folder,
            $this->from,
            $this->to,
            $this->subject,
            $this->text,
            $this->since?->format('Y-m-d'),
            $this->before?->format('Y-m-d'),
            $this->unreadOnly,
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }
}
