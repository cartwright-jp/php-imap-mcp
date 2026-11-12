<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A fresh directory under the system temp dir, removed with remove().
 */
final class TempDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/php-imap-mcp-test-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0700, true);
    }

    /**
     * @return list<string> paths matching the pattern relative to this directory
     */
    public function glob(string $pattern): array
    {
        $files = glob($this->path . '/' . $pattern);

        return $files === false ? [] : $files;
    }

    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->path);
    }
}
