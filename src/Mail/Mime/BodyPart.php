<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Mime;

/**
 * One node of a message's MIME tree, built from the IMAP BODYSTRUCTURE (RFC 3501 7.4.2).
 *
 * Only the structure is known; part contents are fetched separately, so attachments are never downloaded.
 */
final class BodyPart
{
    /**
     * @param array<string, string> $params Content-Type parameters (lowercase names)
     * @param array<string, string> $dispositionParams Content-Disposition parameters (lowercase names)
     * @param list<BodyPart> $children
     */
    public function __construct(
        /** IMAP section: '1', '1.2', ...; 'TEXT' for the body of a single-part message. */
        public readonly string $section,
        public readonly string $type,
        public readonly string $subtype,
        public readonly array $params = [],
        public readonly ?string $encoding = null,
        public readonly int $size = 0,
        public readonly ?string $disposition = null,
        public readonly array $dispositionParams = [],
        public readonly array $children = [],
    ) {
    }

    /**
     * @param array<mixed> $tokens parsed BODYSTRUCTURE list
     */
    public static function fromBodyStructure(array $tokens): self
    {
        return self::build($tokens, '', true);
    }

    public function mimeType(): string
    {
        return $this->type . '/' . $this->subtype;
    }

    public function isMultipart(): bool
    {
        return $this->type === 'multipart';
    }

    public function charset(): ?string
    {
        return $this->params['charset'] ?? null;
    }

    public function filename(): ?string
    {
        $name = self::extendedParam($this->dispositionParams, 'filename') ?? self::extendedParam($this->params, 'name');

        return $name === null || $name === '' ? null : $name;
    }

    public function isAttachment(): bool
    {
        if ($this->isMultipart()) {
            return false;
        }
        if ($this->disposition === 'attachment') {
            return true;
        }
        if ($this->type === 'text' && in_array($this->subtype, ['plain', 'html'], true)) {
            return $this->filename() !== null && $this->disposition !== 'inline';
        }

        return true; // images, PDFs, message/rfc822 and so on are not readable body text
    }

    /**
     * The parts that make up the readable body: for multipart/alternative the plain part (else HTML),
     * and every inline text part of mixed/related containers, in order.
     *
     * @return list<BodyPart>
     */
    public function textParts(): array
    {
        if (!$this->isMultipart()) {
            return $this->isAttachment() ? [] : [$this];
        }
        if ($this->subtype === 'alternative') {
            $best = null;
            foreach ($this->children as $child) {
                $parts = $child->textParts();
                if ($parts === []) {
                    continue;
                }
                $plain = count($parts) === 1 && $parts[0]->subtype === 'plain';
                if ($best === null || $plain) {
                    $best = $parts;
                }
                if ($plain) {
                    break;
                }
            }

            return $best ?? [];
        }
        $parts = [];
        foreach ($this->children as $child) {
            array_push($parts, ...$child->textParts());
        }

        return $parts;
    }

    /**
     * @return list<BodyPart>
     */
    public function attachments(): array
    {
        if (!$this->isMultipart()) {
            return $this->isAttachment() ? [$this] : [];
        }
        $found = [];
        foreach ($this->children as $child) {
            array_push($found, ...$child->attachments());
        }

        return $found;
    }

    /**
     * @param array<mixed> $tokens
     */
    private static function build(array $tokens, string $section, bool $top): self
    {
        if (isset($tokens[0]) && is_array($tokens[0])) {
            $children = [];
            $i = 0;
            while (isset($tokens[$i]) && is_array($tokens[$i])) {
                $children[] = self::build($tokens[$i], ltrim($section . '.' . ($i + 1), '.'), false);
                $i++;
            }
            $subtype = self::lower($tokens[$i] ?? 'mixed');
            $params = self::params($tokens[$i + 1] ?? null);
            [$disposition, $dispositionParams] = self::disposition($tokens[$i + 2] ?? null);

            return new self(
                $section === '' ? 'TEXT' : $section,
                'multipart',
                $subtype,
                $params,
                null,
                0,
                $disposition,
                $dispositionParams,
                $children,
            );
        }

        $type = self::lower($tokens[0] ?? 'application');
        $subtype = self::lower($tokens[1] ?? 'octet-stream');
        // Disposition position: text/* has a line count, message/rfc822 has envelope, body and lines.
        $extension = 7;
        if ($type === 'text') {
            $extension = 8;
        } elseif ($type === 'message' && $subtype === 'rfc822') {
            $extension = 10;
        }
        [$disposition, $dispositionParams] = self::disposition($tokens[$extension + 1] ?? null);
        $size = $tokens[6] ?? 0;

        return new self(
            $top ? 'TEXT' : $section,
            $type,
            $subtype,
            self::params($tokens[2] ?? null),
            is_string($tokens[5] ?? null) ? strtolower($tokens[5]) : null,
            is_string($size) && ctype_digit($size) ? (int) $size : 0,
            $disposition,
            $dispositionParams,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function params(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $params = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i]) && is_string($list[$i + 1])) {
                $params[strtolower($list[$i])] = $list[$i + 1];
            }
        }

        return $params;
    }

    /**
     * @return array{0: ?string, 1: array<string, string>}
     */
    private static function disposition(mixed $value): array
    {
        if (!is_array($value) || !is_string($value[0] ?? null)) {
            return [null, []];
        }

        return [strtolower($value[0]), self::params($value[1] ?? null)];
    }

    /**
     * A parameter that may be RFC 2231 encoded (name*, name*0*, name*1*, ...) or RFC 2047 encoded.
     *
     * @param array<string, string> $params
     */
    private static function extendedParam(array $params, string $name): ?string
    {
        if (isset($params[$name . '*'])) {
            return HeaderDecoder::decodeRfc2231($params[$name . '*']);
        }
        if (isset($params[$name . '*0*']) || isset($params[$name . '*0'])) {
            $joined = '';
            $encoded = isset($params[$name . '*0*']);
            for ($i = 0; isset($params["$name*$i*"]) || isset($params["$name*$i"]); $i++) {
                $joined .= $params["$name*$i*"] ?? $params["$name*$i"];
            }

            return $encoded ? HeaderDecoder::decodeRfc2231($joined) : HeaderDecoder::decode($joined);
        }

        return isset($params[$name]) ? HeaderDecoder::decode($params[$name]) : null;
    }

    private static function lower(mixed $value): string
    {
        return is_string($value) ? strtolower($value) : '';
    }
}
