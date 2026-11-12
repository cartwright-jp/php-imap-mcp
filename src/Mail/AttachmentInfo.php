<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

/**
 * Attachment metadata only; the content is never fetched (SPEC.md 6).
 */
final class AttachmentInfo
{
    public function __construct(
        public readonly ?string $filename,
        public readonly string $contentType,
        /** Encoded size in bytes as reported by the server (base64 is about 4/3 of the file). */
        public readonly int $size,
    ) {
    }
}
