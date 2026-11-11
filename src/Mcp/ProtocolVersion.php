<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mcp;

/**
 * Supported MCP protocol versions (SPEC.md 5).
 *
 * Modern: stateless, metadata in every request's _meta (2026-07-28 and later).
 * Legacy: initialize handshake (2025-11-25 and earlier); served statelessly without sessions.
 */
final class ProtocolVersion
{
    public const MODERN = ['2026-07-28'];
    public const LEGACY = ['2025-11-25'];

    /**
     * @return list<string> newest first
     */
    public static function all(): array
    {
        return [...self::MODERN, ...self::LEGACY];
    }

    public static function isModern(string $version): bool
    {
        return in_array($version, self::MODERN, true);
    }

    public static function isLegacy(string $version): bool
    {
        return in_array($version, self::LEGACY, true);
    }

    public static function latestLegacy(): string
    {
        return self::LEGACY[0];
    }
}
