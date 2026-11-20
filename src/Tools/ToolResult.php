<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

/**
 * Result of tools/call. Execution failures use isError so the AI can decide what to do next (SPEC.md 5).
 */
final class ToolResult
{
    /**
     * @param array<string, mixed>|null $structured
     * @param array<string, mixed> $audit see withAudit()
     */
    private function __construct(
        public readonly string $text,
        public readonly ?array $structured,
        public readonly bool $isError,
        public readonly array $audit = [],
    ) {
    }

    /**
     * Adds audit-log fields that are known only after the call (SPEC.md 7), such as the number of quoted
     * characters. They are merged into Tool::auditArguments() and never sent to the client. Never include
     * message bodies, passwords or tokens.
     *
     * @param array<string, mixed> $fields
     */
    public function withAudit(array $fields): self
    {
        return new self($this->text, $this->structured, $this->isError, $fields + $this->audit);
    }

    /**
     * @param array<string, mixed>|null $structured must match the tool's outputSchema when it has one
     */
    public static function success(string $text, ?array $structured = null): self
    {
        return new self($text, $structured, false);
    }

    public static function error(string $text): self
    {
        return new self($text, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['content' => [['type' => 'text', 'text' => $this->text]]];
        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }
        $result['isError'] = $this->isError;

        return $result;
    }
}
