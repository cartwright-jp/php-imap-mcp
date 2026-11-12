<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

final class SearchResult
{
    /**
     * @param list<int> $uids ascending
     */
    public function __construct(
        public readonly int $uidValidity,
        public readonly array $uids,
    ) {
    }
}
