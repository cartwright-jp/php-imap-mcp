<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp;

/**
 * Server identity returned to MCP clients (serverInfo in server/discover and initialize).
 */
final class ServerInfo
{
    public const NAME = 'php-imap-mcp';
    public const VERSION = '0.1.0-dev';

    /**
     * @return array{name: string, version: string}
     */
    public static function toArray(): array
    {
        return [
            'name' => self::NAME,
            'version' => self::VERSION,
        ];
    }
}
