<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

use InvalidArgumentException;

/**
 * The enabled tools, in a fixed order (tools/list SHOULD be deterministic).
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param list<Tool> $tools
     */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            $name = $tool->name();
            if (isset($this->tools[$name])) {
                throw new InvalidArgumentException(sprintf('Duplicate tool name: %s', $name));
            }
            $this->tools[$name] = $tool;
        }
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<array<string, mixed>> tool definitions for tools/list
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $name => $tool) {
            $definitions[] = ['name' => $name] + $tool->definition();
        }

        return $definitions;
    }
}
