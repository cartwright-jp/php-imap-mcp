<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit;

use Cartwright\ImapMcp\ServerInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerInfo::class)]
final class ServerInfoTest extends TestCase
{
    public function testToArrayHasNameAndVersion(): void
    {
        self::assertSame(
            ['name' => 'php-imap-mcp', 'version' => ServerInfo::VERSION],
            ServerInfo::toArray(),
        );
    }

    public function testVersionIsSemVer(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/',
            ServerInfo::VERSION,
        );
    }
}
