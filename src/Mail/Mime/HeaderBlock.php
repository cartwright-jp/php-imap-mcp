<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

use Cartwright\ImapMcp\Mail\Address;
use DateTimeImmutable;
use Exception;

/**
 * A raw RFC 5322 header block, with typed accessors that decode to UTF-8.
 */
final class HeaderBlock
{
    /** @var array<string, list<string>> lowercase name => raw (still folded) values */
    private array $fields = [];

    public function __construct(string $raw)
    {
        $name = null;
        $lines = preg_split('/\r?\n/', $raw);
        foreach ($lines === false ? [] : $lines as $line) {
            if ($line === '') {
                break; // end of the header
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $name !== null) {
                $values = $this->fields[$name];
                $values[] = array_pop($values) . "\r\n" . $line;
                $this->fields[$name] = $values;
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                $name = null;
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $this->fields[$name][] = ltrim(substr($line, $colon + 1));
        }
    }

    public function raw(string $name): ?string
    {
        return $this->fields[strtolower($name)][0] ?? null;
    }

    public function text(string $name): string
    {
        $raw = $this->raw($name);

        return $raw === null ? '' : HeaderDecoder::decode($raw);
    }

    /**
     * @return list<Address>
     */
    public function addresses(string $name): array
    {
        $all = [];
        foreach ($this->fields[strtolower($name)] ?? [] as $raw) {
            array_push($all, ...AddressParser::parse($raw));
        }

        return $all;
    }

    /**
     * Message-IDs in angle brackets (Message-ID, In-Reply-To, References), without the brackets.
     *
     * @return list<string>
     */
    public function messageIds(string $name): array
    {
        $raw = $this->raw($name);
        if ($raw === null) {
            return [];
        }
        preg_match_all('/<([^<>\s]+)>/', $raw, $m);

        return array_values(array_unique($m[1]));
    }

    public function date(): ?DateTimeImmutable
    {
        $raw = $this->raw('Date');
        if ($raw === null) {
            return null;
        }
        // Drop comments such as "(JST)" that DateTimeImmutable does not understand.
        $value = trim((string) preg_replace('/\([^()]*\)/', '', HeaderDecoder::unfold($raw)));
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
