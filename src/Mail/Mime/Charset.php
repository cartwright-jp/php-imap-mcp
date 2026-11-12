<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

/**
 * Converts mail text in any charset to valid UTF-8 (SPEC.md 9 文字コード).
 *
 * Japanese charsets are mapped to their Microsoft variants, which are what Japanese mailers actually send
 * (①, ～, ㈱ and so on are outside plain Shift_JIS / ISO-2022-JP). Bytes that cannot be converted become
 * U+FFFD; conversion never fails.
 */
final class Charset
{
    private const ALIASES = [
        'shift_jis' => 'SJIS-win',
        'shift-jis' => 'SJIS-win',
        'sjis' => 'SJIS-win',
        'x-sjis' => 'SJIS-win',
        'ms_kanji' => 'SJIS-win',
        'windows-31j' => 'SJIS-win',
        'cp932' => 'SJIS-win',
        'iso-2022-jp' => 'ISO-2022-JP-MS',
        'iso-2022-jp-1' => 'ISO-2022-JP-MS',
        'iso-2022-jp-2' => 'ISO-2022-JP-MS',
        'iso-2022-jp-3' => 'ISO-2022-JP-MS',
        'csiso2022jp' => 'ISO-2022-JP-MS',
        'euc-jp' => 'eucJP-win',
        'x-euc-jp' => 'eucJP-win',
        'eucjp' => 'eucJP-win',
        'cp51932' => 'eucJP-win',
        'utf8' => 'UTF-8',
        'us-ascii' => 'UTF-8',
        'ascii' => 'UTF-8',
        'ks_c_5601-1987' => 'UHC',
        'gb2312' => 'CP936',
        'gbk' => 'CP936',
    ];

    private const NOT_CHARSETS = [
        'base64', 'uuencode', 'html-entities', 'quoted-printable', 'qprint', '7bit', '8bit', 'pass',
    ];

    public static function toUtf8(string $bytes, ?string $charset): string
    {
        if ($bytes === '') {
            return '';
        }
        $from = self::resolve($charset);
        // Undeclared ISO-2022-JP is plain 7-bit, so it would pass as UTF-8: recognise its escape sequences first.
        if ($from === null && self::guess($bytes) === 'ISO-2022-JP-MS') {
            return self::convert($bytes, 'ISO-2022-JP-MS');
        }
        if ($from === null || $from === 'UTF-8') {
            if (mb_check_encoding($bytes, 'UTF-8')) {
                return $bytes;
            }
            if ($from === null) {
                $from = self::guess($bytes);
            }
        }

        return self::convert($bytes, $from);
    }

    /**
     * For text without a declared charset (raw 8-bit headers): UTF-8 if valid, else a Japanese guess.
     */
    public static function guess(string $bytes): string
    {
        if (str_contains($bytes, "\x1b\$B") || str_contains($bytes, "\x1b\$@")) {
            return 'ISO-2022-JP-MS';
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return 'UTF-8';
        }
        foreach (['SJIS-win', 'eucJP-win'] as $candidate) {
            if (mb_check_encoding($bytes, $candidate)) {
                return $candidate;
            }
        }

        return 'Windows-1252';
    }

    /**
     * The mbstring name for a MIME charset label, or null when the label is missing or unknown.
     */
    public static function resolve(?string $charset): ?string
    {
        if ($charset === null) {
            return null;
        }
        $label = strtolower(trim($charset, " \t\"'"));
        if ($label === '') {
            return null;
        }
        if (isset(self::ALIASES[$label])) {
            return self::ALIASES[$label];
        }
        if ($label === 'utf-8') {
            return 'UTF-8';
        }
        foreach (mb_list_encodings() as $encoding) {
            // Not text charsets (and deprecated in mbstring).
            if (in_array(strtolower($encoding), self::NOT_CHARSETS, true)) {
                continue;
            }
            if (strtolower($encoding) === $label) {
                return $encoding;
            }
            foreach (mb_encoding_aliases($encoding) as $alias) {
                if (strtolower($alias) === $label) {
                    return $encoding;
                }
            }
        }

        return null;
    }

    private static function convert(string $bytes, string $from): string
    {
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', $from);
        } finally {
            mb_substitute_character($previous);
        }
        if (!is_string($converted)) {
            $converted = '';
        }

        return mb_scrub($converted, 'UTF-8');
    }
}
