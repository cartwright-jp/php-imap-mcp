<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

/**
 * Decodes header values: RFC 2047 encoded-words and raw 8-bit text, to UTF-8.
 */
final class HeaderDecoder
{
    private const ENCODED_WORD = '/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/';

    public static function decode(string $raw): string
    {
        $raw = self::unfold($raw);
        if (!str_contains($raw, '=?')) {
            return self::cleanText(Charset::toUtf8($raw, null));
        }

        $out = '';
        $pending = null; // [charset, bytes] of adjacent encoded-words, decoded together
        $offset = 0;
        preg_match_all(self::ENCODED_WORD, $raw, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $m) {
            [$whole, $start] = $m[0];
            $between = substr($raw, $offset, $start - $offset);
            // Whitespace between two encoded-words is not part of the text (RFC 2047 6.2).
            if ($pending === null || trim($between) !== '') {
                if ($pending !== null) {
                    $out .= Charset::toUtf8($pending[1], $pending[0]);
                    $pending = null;
                }
                $out .= Charset::toUtf8($between, null);
            }
            $charset = explode('*', $m[1][0], 2)[0]; // RFC 2231 language suffix
            $bytes = self::decodeWord($m[2][0], $m[3][0]);
            // Multibyte characters may be split across words (common with Shift_JIS): join same-charset words.
            if ($pending !== null && strcasecmp($pending[0], $charset) === 0) {
                $pending[1] .= $bytes;
            } else {
                if ($pending !== null) {
                    $out .= Charset::toUtf8($pending[1], $pending[0]);
                }
                $pending = [$charset, $bytes];
            }
            $offset = $start + strlen($whole);
        }
        if ($pending !== null) {
            $out .= Charset::toUtf8($pending[1], $pending[0]);
        }
        $out .= Charset::toUtf8(substr($raw, $offset), null);

        return self::cleanText($out);
    }

    /**
     * RFC 2231 extended parameter value: charset'language'percent-encoded.
     */
    public static function decodeRfc2231(string $value): string
    {
        $parts = explode("'", $value, 3);
        if (count($parts) !== 3) {
            return self::cleanText(Charset::toUtf8(rawurldecode($value), null));
        }

        return self::cleanText(Charset::toUtf8(rawurldecode($parts[2]), $parts[0] !== '' ? $parts[0] : null));
    }

    public static function unfold(string $raw): string
    {
        return (string) preg_replace('/\r?\n[ \t]+/', ' ', rtrim($raw, "\r\n"));
    }

    private static function decodeWord(string $encoding, string $text): string
    {
        if (strtoupper($encoding) === 'B') {
            $decoded = base64_decode($text, true);

            return $decoded === false ? '' : $decoded;
        }

        return quoted_printable_decode(str_replace('_', ' ', $text));
    }

    /**
     * Removes control characters (they could break the JSON consumer's display or hide text).
     */
    private static function cleanText(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
    }
}
