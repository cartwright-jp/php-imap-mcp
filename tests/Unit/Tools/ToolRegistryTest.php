<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Tools;

use Cartwright\ImapMcp\Tests\Support\EchoTool;
use Cartwright\ImapMcp\Tools\ToolRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolRegistry::class)]
final class ToolRegistryTest extends TestCase
{
    public function testDuplicateNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry([new EchoTool(), new EchoTool()]);
    }

    public function testDefinitionsStartWithName(): void
    {
        $definitions = (new ToolRegistry([new EchoTool()]))->definitions();

        self::assertSame('echo', $definitions[0]['name']);
        self::assertSame('name', array_key_first($definitions[0]));
        self::assertArrayHasKey('inputSchema', $definitions[0]);
    }
}
