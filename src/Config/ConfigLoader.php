<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Config;

use Throwable;

/**
 * Finds, loads and checks config.php (SPEC.md 8).
 */
final class ConfigLoader
{
    public const ENV_VAR = 'PHP_IMAP_MCP_CONFIG';

    /**
     * The config path: the PHP_IMAP_MCP_CONFIG environment variable, else $default.
     */
    public static function resolvePath(string $default): string
    {
        $fromEnv = getenv(self::ENV_VAR);

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : $default;
    }

    /**
     * @param string|null $documentRoot the web server's document root, to warn when config or storage is inside it
     *
     * @throws ConfigException
     */
    public static function load(string $path, ?string $documentRoot = null): Config
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException(['config.php was not found or is not readable']);
        }
        try {
            $raw = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable $e) {
            throw new ConfigException([sprintf('config.php could not be loaded (%s)', $e::class)]);
        }

        $config = ConfigParser::parse($raw, dirname($path));

        return $config->withWarnings(self::fileWarnings($path, $config->runtime->storageDir, $documentRoot));
    }

    /**
     * @return list<string>
     */
    private static function fileWarnings(string $path, string $storageDir, ?string $documentRoot): array
    {
        $warnings = [];
        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = @fileperms($path);
            if ($perms !== false && ($perms & 0o077) !== 0) {
                $warnings[] = 'config.php can be read by other users: run chmod 600 on it';
            }
        }
        $root = $documentRoot !== null && $documentRoot !== '' ? realpath($documentRoot) : false;
        if ($root !== false) {
            if (self::isInside((string) realpath($path), $root)) {
                $warnings[] = 'config.php is inside the public document root: move it out of public_html';
            }
            if (self::isInside(self::nearestExisting($storageDir), $root)) {
                $warnings[] = 'runtime.storage_dir is inside the public document root: move it out of public_html';
            }
        }

        return $warnings;
    }

    private static function isInside(string $path, string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';

        return $path !== '/' && str_starts_with($path, $root);
    }

    /**
     * realpath() of the directory or its closest existing parent (storage_dir may not exist yet).
     */
    private static function nearestExisting(string $dir): string
    {
        $suffix = '';
        $real = realpath($dir);
        while ($real === false) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return '';
            }
            $suffix = '/' . basename($dir) . $suffix;
            $dir = $parent;
            $real = realpath($dir);
        }

        return $real . $suffix;
    }
}
