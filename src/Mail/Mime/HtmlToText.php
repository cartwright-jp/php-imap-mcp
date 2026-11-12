<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

/**
 * Turns an HTML mail body into readable plain text (SPEC.md 6 get_email: "HTMLのみの場合はテキスト化").
 *
 * Scripts, styles and comments are dropped; links keep their URL so the reader can judge where they lead.
 */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        $text = (string) preg_replace(
            [
                '/<!--.*?(-->|$)/s',
                '/<(script|style|head|title|template|noscript)\b[^>]*>.*?(<\/\1\s*>|$)/is',
            ],
            '',
            $html,
        );
        $text = (string) preg_replace_callback(
            '/<a\b[^>]*\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)<\/a\s*>/is',
            static function (array $m): string {
                $href = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
                $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(strip_tags($m[5]));
                if ($label === '' || str_starts_with(strtolower($url), 'mailto:') || $label === $url) {
                    return $label !== '' ? $label : $url;
                }

                return $label . ' (' . $url . ')';
            },
            $text,
        );
        $text = (string) preg_replace(
            [
                '/<br\b[^>]*>/i',
                '/<li\b[^>]*>/i',
                '/<\/(p|div|h[1-6]|li|tr|table|blockquote|pre|ul|ol|section|article|header|footer)\s*>/i',
                '/<(p|div|h[1-6]|tr|table|blockquote|pre|ul|ol|hr)\b[^>]*>/i',
                '/<\/(td|th)\s*>/i',
            ],
            ["\n", "\n- ", "\n", "\n", "\t"],
            $text,
        );
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('/[ \t\x{3000}]*\n[ \t\x{3000}]*/u', "\n", $text);
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        // Block tags open and close with a newline each; one line break per block reads best.
        $text = (string) preg_replace('/\n{2,}/', "\n", $text);

        return trim($text);
    }

    /**
     * The charset declared in <meta>, for HTML parts whose Content-Type has none.
     */
    public static function declaredCharset(string $html): ?string
    {
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9_\-]+)/i', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
