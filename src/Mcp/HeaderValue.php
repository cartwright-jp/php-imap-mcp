<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mcp;

/**
 * Decodes MCP header values (Streamable HTTP "Value Encoding").
 */
final class HeaderValue
{
    private const PREFIX = '=?base64?';
    private const SUFFIX = '?=';

    /**
     * Returns the decoded value, or null when the value contains invalid characters or broken Base64.
     */
    public static function decode(string $value): ?string
    {
        // Visible ASCII, space and horizontal tab only (RFC 9110 field values).
        if (preg_match('/^[\x20-\x7E\t]*$/', $value) !== 1) {
            return null;
        }
        if (!str_starts_with($value, self::PREFIX) || !str_ends_with($value, self::SUFFIX)) {
            return $value;
        }
        $encoded = substr($value, strlen(self::PREFIX), -strlen(self::SUFFIX));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        return $decoded;
    }
}
