<?php

declare(strict_types=1);

/*
 * Validates config.php and shows a summary without any secrets (SPEC.md 8).
 *
 *   php bin/check-config.php [path/to/config.php]
 *
 * Default path: the PHP_IMAP_MCP_CONFIG environment variable, else config.php next to bin/.
 * Exit code 0 = usable (warnings may be shown), 1 = the server would refuse to start.
 */

use Cartwright\ImapMcp\Auth\IpRange;
use Cartwright\ImapMcp\Config\ConfigException;
use Cartwright\ImapMcp\Config\ConfigLoader;
use Cartwright\ImapMcp\Policy\ToolPolicy;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? ConfigLoader::resolvePath(dirname(__DIR__) . '/config.php');
echo "Checking {$path}\n\n";

try {
    $config = ConfigLoader::load($path);
} catch (ConfigException $e) {
    echo "ERROR: the server will refuse to start:\n";
    foreach ($e->errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

$warnings = $config->warnings;
$storage = $config->runtime->storageDir;
if (!is_dir($storage) && !@mkdir($storage, 0700, true) && !is_dir($storage)) {
    $warnings[] = 'runtime.storage_dir does not exist and cannot be created';
} elseif (!is_writable($storage)) {
    $warnings[] = 'runtime.storage_dir is not writable';
}

echo "Accounts:\n";
foreach ($config->accounts as $id => $account) {
    printf(
        "  - %s: %s via %s:%d (%s)\n",
        $id,
        $account->email,
        $account->imapHost,
        $account->imapPort,
        $account->imapEncryption,
    );
}
printf("Tokens: %d\n", count($config->tokens));
foreach ($config->tokens as $token) {
    printf("  - %s -> %s\n", $token->label !== '' ? $token->label : '(no label)', $token->accountId);
}
$ranges = array_map(static fn (IpRange $range): string => $range->notation, $config->allowedIps);
printf("Allowed IPs: %s\n", $ranges === [] ? '(any)' : implode(', ', $ranges));
$lockout = $config->lockout;
printf("Lockout: %d failures in %ds -> %ds ban\n", $lockout->maxFailures, $lockout->windowSec, $lockout->banSec);
$enabled = array_filter(array_keys(ToolPolicy::DEFAULTS), static fn (string $t): bool => $config->tools->isEnabled($t));
printf("Enabled tools: %s\n", implode(', ', $enabled));
printf("Storage: %s\n", $storage);
echo "IMAP connection test: not available yet (added with the mail tools in M3)\n";

if ($warnings !== []) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "  - {$warning}\n";
    }
}
echo "\nOK: the configuration is valid.\n";
