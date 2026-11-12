<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Policy;

use Cartwright\ImapMcp\Tools\Tool;

/**
 * Which tools are enabled (config.php 'tools', SPEC.md 6, 8). Disabled tools are not listed and cannot be called.
 */
final class ToolPolicy
{
    /**
     * Secure defaults: reading and saving drafts only. Write tools must be enabled one by one.
     * A tool that is not listed here is never enabled.
     */
    public const DEFAULTS = [
        // Dummy connection test tool from M1; removed together with EchoTool in M3.
        'echo' => true,
        'list_folders' => true,
        'search_emails' => true,
        'get_email' => true,
        'save_draft' => true,
        'set_flags' => false,
        'move_email' => false,
    ];

    /** @var array<string, bool> */
    private readonly array $enabled;

    /**
     * @param array<string, bool> $overrides from config.php; names must be keys of DEFAULTS
     */
    public function __construct(array $overrides = [])
    {
        $this->enabled = $overrides + self::DEFAULTS;
    }

    public function isEnabled(string $tool): bool
    {
        return array_key_exists($tool, self::DEFAULTS) && ($this->enabled[$tool] ?? false);
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<Tool>
     */
    public function filter(array $tools): array
    {
        return array_values(array_filter($tools, fn (Tool $tool): bool => $this->isEnabled($tool->name())));
    }
}
