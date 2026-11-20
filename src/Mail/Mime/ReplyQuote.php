<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

use Cartwright\ImapMcp\Mail\Address;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Quotes the original email below a reply for save_draft's quote "full" (SPEC.md 6), the way Gmail does in
 * Japanese: a header line such as "2026年11月19日(木) 10:51 山田 太郎 <taro@example.com>:", then each line of the
 * original prefixed with "> ". The server copies the original text as is, so the AI never rewrites it.
 */
final class ReplyQuote
{
    public const TRUNCATED_MARK = '（以下省略）';
    private const WEEKDAYS = ['月', '火', '水', '木', '金', '土', '日'];

    /**
     * @param string $original the original body as get_email returns it (untrusted)
     * @param bool $truncated the original was cut, so the quote ends with TRUNCATED_MARK
     * @param list<Address> $from the original's From; only the first address is shown
     *
     * @return string|null the reply followed by the quote, with LF line endings; null when the original is empty
     */
    public static function apply(
        string $reply,
        string $original,
        bool $truncated,
        ?DateTimeImmutable $date,
        array $from,
        DateTimeZone $timezone,
    ): ?string {
        $lines = self::lines($original);
        if ($lines === []) {
            return null;
        }

        $out = rtrim(str_replace(["\r\n", "\r"], "\n", $reply), "\n");
        $out .= $out === '' ? '' : "\n\n";
        $header = self::header($date, $from, $timezone);
        if ($header !== null) {
            $out .= $header . "\n\n";
        }
        foreach ($lines as $line) {
            $out .= ($line === '' ? '>' : '> ' . $line) . "\n";
        }
        if ($truncated) {
            $out .= self::TRUNCATED_MARK . "\n";
        }

        return $out;
    }

    /**
     * @param list<Address> $from
     *
     * @return string|null null when neither the date nor a sender is known
     */
    public static function header(?DateTimeImmutable $date, array $from, DateTimeZone $timezone): ?string
    {
        $parts = [];
        if ($date !== null) {
            $local = $date->setTimezone($timezone);
            $parts[] = sprintf(
                '%s(%s) %s',
                $local->format('Y年n月j日'),
                self::WEEKDAYS[(int) $local->format('N') - 1],
                $local->format('G:i'),
            );
        }
        if ($from !== []) {
            $parts[] = $from[0]->toString();
        }

        return $parts === [] ? null : implode(' ', $parts) . ':';
    }

    /**
     * @return list<string> lines without trailing blank lines; [] when the text is blank
     */
    private static function lines(string $text): array
    {
        // The draft is text/plain: drop control characters other than tab and newline (as for 'body').
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
