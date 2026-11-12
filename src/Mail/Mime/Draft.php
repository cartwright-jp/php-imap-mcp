<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

final class Draft
{
    public function __construct(
        /** The complete message with CRLF line endings. */
        public readonly string $message,
        /** Without angle brackets. */
        public readonly string $messageId,
    ) {
    }
}
