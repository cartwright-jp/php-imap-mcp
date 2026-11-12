<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Policy;

/**
 * Limits on what the mail tools may access and return (config.php 'policy', SPEC.md 8). Used from M3 on.
 */
final class MailPolicy
{
    /**
     * @param list<string> $allowedFolders empty = all folders
     */
    public function __construct(
        public readonly array $allowedFolders = [],
        public readonly int $searchLimitMax = 50,
        public readonly int $bodyMaxChars = 20000,
        public readonly bool $denyMoveToTrash = true,
    ) {
    }

    public function isFolderAllowed(string $folder): bool
    {
        return $this->allowedFolders === [] || in_array($folder, $this->allowedFolders, true);
    }
}
