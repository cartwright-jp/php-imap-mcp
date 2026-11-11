<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

/**
 * One MCP tool: its definition for tools/list and its execution for tools/call (SPEC.md 6).
 */
interface Tool
{
    public function name(): string;

    /**
     * Tool definition without the name (title, description, inputSchema, outputSchema, annotations).
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws InvalidToolArguments when the arguments do not satisfy inputSchema
     */
    public function call(array $arguments): ToolResult;
}
