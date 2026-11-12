<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

/**
 * Content-Transfer-Encoding. Also handles bodies cut off by a partial FETCH (BODY[n]<0.N>).
 */
final class TransferDecoder
{
    public static function decode(string $bytes, ?string $encoding, bool $truncated = false): string
    {
        switch (strtolower(trim((string) $encoding))) {
            case 'base64':
                $clean = (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $bytes);
                if ($truncated) {
                    $clean = substr($clean, 0, intdiv(strlen($clean), 4) * 4);
                }
                $decoded = base64_decode($clean, true);

                return $decoded === false ? '' : $decoded;
            case 'quoted-printable':
                if ($truncated) {
                    // Drop a soft break or escape cut in half at the end.
                    $bytes = (string) preg_replace('/=[0-9A-Fa-f]?$/', '', $bytes);
                }

                return quoted_printable_decode($bytes);
            default:
                return $bytes; // 7bit, 8bit, binary
        }
    }
}
