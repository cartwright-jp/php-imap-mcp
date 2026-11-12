<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

final class FolderInfo
{
    public const SPECIAL_USES = ['inbox', 'sent', 'drafts', 'trash', 'junk', 'archive', 'all', 'flagged'];

    public function __construct(
        /** UTF-8 name, as the tools accept it. */
        public readonly string $name,
        public readonly ?string $delimiter,
        /** One of SPECIAL_USES, or null. */
        public readonly ?string $specialUse,
        public readonly bool $selectable,
        public readonly ?int $total = null,
        public readonly ?int $unread = null,
    ) {
    }
}
