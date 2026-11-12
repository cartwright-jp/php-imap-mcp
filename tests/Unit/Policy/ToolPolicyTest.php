<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Policy;

use Cartwright\ImapMcp\Policy\ToolPolicy;
use Cartwright\ImapMcp\Tools\Tool;
use Cartwright\ImapMcp\Tools\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolPolicy::class)]
final class ToolPolicyTest extends TestCase
{
    public function testWriteToolsAreOffByDefault(): void
    {
        $policy = new ToolPolicy();

        foreach (['list_folders', 'search_emails', 'get_email', 'save_draft'] as $tool) {
            self::assertTrue($policy->isEnabled($tool), $tool);
        }
        self::assertFalse($policy->isEnabled('set_flags'));
        self::assertFalse($policy->isEnabled('move_email'));
    }

    public function testUnknownToolsAreNeverEnabled(): void
    {
        self::assertFalse((new ToolPolicy(['send_email' => true]))->isEnabled('send_email'));
    }

    public function testFilterKeepsOnlyEnabledToolsInOrder(): void
    {
        $policy = new ToolPolicy(['set_flags' => true, 'search_emails' => false]);
        $tools = [
            $this->tool('list_folders'),
            $this->tool('search_emails'),
            $this->tool('set_flags'),
            $this->tool('move_email'),
            $this->tool('delete_email'),
        ];

        $names = array_map(static fn (Tool $t): string => $t->name(), $policy->filter($tools));

        self::assertSame(['list_folders', 'set_flags'], $names);
    }

    private function tool(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function definition(): array
            {
                return [];
            }

            public function call(array $arguments): ToolResult
            {
                return ToolResult::success('');
            }

            public function auditArguments(array $arguments): array
            {
                return [];
            }
        };
    }
}
