<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

/**
 * Dummy tool for checking the protocol layer (M1). Remove when the mail tools arrive (M3).
 */
final class EchoTool implements Tool
{
    public const MAX_LENGTH = 1000;

    public function name(): string
    {
        return 'echo';
    }

    public function definition(): array
    {
        return [
            'title' => 'Echo (connection test)',
            'description' => 'Returns the given text unchanged. Use only to test the connection to this server.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text' => [
                        'type' => 'string',
                        'description' => 'Text to return',
                        'maxLength' => self::MAX_LENGTH,
                    ],
                ],
                'required' => ['text'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string'],
                ],
                'required' => ['text'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    public function call(array $arguments): ToolResult
    {
        $unknown = array_diff(array_keys($arguments), ['text']);
        if ($unknown !== []) {
            throw new InvalidToolArguments(sprintf('Unknown argument: %s', implode(', ', $unknown)));
        }
        $text = $arguments['text'] ?? null;
        if (!is_string($text)) {
            throw new InvalidToolArguments("Missing required string argument 'text'");
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidToolArguments(sprintf("'text' must be at most %d characters", self::MAX_LENGTH));
        }

        return ToolResult::success($text, ['text' => $text]);
    }
}
