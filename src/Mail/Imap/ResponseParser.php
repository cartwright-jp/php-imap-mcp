<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Imap;

/**
 * Parses one IMAP response line (with its literals already inlined as {n}\r\n<n bytes>) into tokens.
 *
 * Strings stay strings, NIL becomes null, parenthesised lists become arrays. Section specifiers such as
 * BODY[HEADER.FIELDS (DATE FROM)]<0> stay one atom. Used for FETCH, whose responses webklex's tokenizer
 * mangles (quoted values directly followed by ')' and NIL).
 */
final class ResponseParser
{
    private int $pos = 0;
    private readonly int $length;

    private function __construct(private readonly string $input)
    {
        $this->length = strlen($input);
    }

    /**
     * @return list<mixed>
     */
    public static function parse(string $line): array
    {
        $parser = new self(rtrim($line, "\r\n"));

        return $parser->items(false);
    }

    /**
     * Turns the list after "FETCH" into name => value (names uppercased; sections kept as sent).
     *
     * @param array<mixed> $list
     *
     * @return array<string, mixed>
     */
    public static function pairs(array $list): array
    {
        $map = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i])) {
                $map[strtoupper($list[$i])] = $list[$i + 1];
            }
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private function items(bool $inList): array
    {
        $items = [];
        while ($this->pos < $this->length) {
            $c = $this->input[$this->pos];
            if ($c === ' ') {
                $this->pos++;
            } elseif ($c === '(') {
                $this->pos++;
                $items[] = $this->items(true);
            } elseif ($c === ')') {
                $this->pos++;
                if ($inList) {
                    return $items;
                }
            } elseif ($c === '"') {
                $items[] = $this->quoted();
            } elseif ($c === '{' && preg_match('/\G\{(\d+)\+?\}\r\n/', $this->input, $m, 0, $this->pos) === 1) {
                $this->pos += strlen($m[0]);
                $items[] = substr($this->input, $this->pos, (int) $m[1]);
                $this->pos += (int) $m[1];
            } else {
                $atom = $this->atom();
                $items[] = strtoupper($atom) === 'NIL' ? null : $atom;
            }
        }

        return $items;
    }

    private function quoted(): string
    {
        $this->pos++; // opening quote
        $out = '';
        while ($this->pos < $this->length) {
            $c = $this->input[$this->pos++];
            if ($c === '\\' && $this->pos < $this->length) {
                $out .= $this->input[$this->pos++];
            } elseif ($c === '"') {
                return $out;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    private function atom(): string
    {
        $start = $this->pos;
        $bracket = 0;
        while ($this->pos < $this->length) {
            $c = $this->input[$this->pos];
            if ($c === '[') {
                $bracket++;
            } elseif ($c === ']') {
                $bracket = max(0, $bracket - 1);
            } elseif ($bracket === 0 && ($c === ' ' || $c === '(' || $c === ')' || $c === '{')) {
                // '{' too: some servers send "BODY[TEXT]<0>{100}" without a space before the literal.
                break;
            }
            $this->pos++;
        }

        return substr($this->input, $start, $this->pos - $start);
    }
}
