<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

use Cartwright\ImapMcp\Mail\Address;

/**
 * Parses address-list headers (From, To, Cc, Reply-To) leniently.
 *
 * Works on the raw header so that commas inside encoded or quoted display names do not split addresses.
 */
final class AddressParser
{
    /**
     * @return list<Address>
     */
    public static function parse(string $raw): array
    {
        $addresses = [];
        foreach (self::split(HeaderDecoder::unfold($raw)) as $item) {
            $address = self::parseOne($item);
            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Splits at commas outside quotes, comments and angle brackets. Group syntax "name: a, b;" is flattened.
     *
     * @return list<string>
     */
    private static function split(string $raw): array
    {
        $items = [];
        $current = '';
        $quoted = false;
        $depth = 0;
        $angle = false;
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $c = $raw[$i];
            if ($quoted) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $length) {
                    $current .= $raw[++$i];
                } elseif ($c === '"') {
                    $quoted = false;
                }
                continue;
            }
            if ($c === '"') {
                $quoted = true;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && $depth > 0) {
                $depth--;
            } elseif ($c === '<') {
                $angle = true;
            } elseif ($c === '>') {
                $angle = false;
            } elseif (($c === ',' || $c === ';') && $depth === 0 && !$angle) {
                $items[] = $current;
                $current = '';
                continue;
            } elseif ($c === ':' && $depth === 0 && !$angle && !str_contains($current, '@')) {
                $current = ''; // group name
                continue;
            }
            $current .= $c;
        }
        $items[] = $current;

        return array_values(array_filter(array_map('trim', $items), static fn (string $s): bool => $s !== ''));
    }

    private static function parseOne(string $item): ?Address
    {
        if (preg_match('/^(.*)<([^<>]*)>\s*(?:\(.*\))?$/s', $item, $m) === 1) {
            $email = trim($m[2]);
            $name = self::displayName($m[1]);
        } else {
            // "user@example.com (Name)" or a bare address
            $name = '';
            if (preg_match('/\(([^()]*)\)/', $item, $c) === 1) {
                $name = HeaderDecoder::decode($c[1]);
            }
            $email = trim((string) preg_replace('/\([^()]*\)/', '', $item));
        }
        $email = trim($email, " \t\"'");
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        return new Address(HeaderDecoder::decode($email), $name);
    }

    private static function displayName(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            $raw = stripcslashes(substr($raw, 1, -1));
        }

        return HeaderDecoder::decode($raw);
    }
}
