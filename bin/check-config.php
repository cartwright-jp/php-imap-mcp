<?php

declare(strict_types=1);

/*
 * Validates config.php and shows a summary without any secrets (SPEC.md 8).
 *
 *   php bin/check-config.php [--no-imap] [path/to/config.php]
 *
 * It also logs in to each account's IMAP server and finds the Drafts folder (skip with --no-imap).
 * Default path: the PHP_IMAP_MCP_CONFIG environment variable, else config.php next to bin/.
 * Exit code 0 = usable (warnings may be shown), 1 = the server would refuse to start or the IMAP check failed.
 */

use Cartwright\ImapMcp\Auth\IpRange;
use Cartwright\ImapMcp\Config\ConfigException;
use Cartwright\ImapMcp\Config\ConfigLoader;
use Cartwright\ImapMcp\Mail\FolderInfo;
use Cartwright\ImapMcp\Mail\Imap\ImapMailbox;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Policy\ToolPolicy;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$checkImap = !in_array('--no-imap', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--no-imap'));
$path = $args[0] ?? ConfigLoader::resolvePath(dirname(__DIR__) . '/config.php');
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

$imapFailed = false;
if ($checkImap) {
    echo "\nIMAP:\n";
    foreach ($config->accounts as $id => $account) {
        $mailbox = new ImapMailbox($account, $config->runtime->imapTimeoutSec);
        try {
            $folders = $mailbox->listFolders();
            $drafts = $account->draftsFolder;
            if ($drafts === null) {
                $found = array_values(array_filter(
                    $folders,
                    static fn (FolderInfo $f): bool => $f->specialUse === 'drafts',
                ));
                $drafts = $found[0]->name ?? null;
            }
            printf("  - %s: login OK, %d folders, Drafts: %s\n", $id, count($folders), $drafts ?? 'NOT FOUND');
            if ($drafts === null) {
                $warnings[] = sprintf('accounts.%s: no Drafts folder found; set folders.drafts in config.php', $id);
            }
        } catch (MailException $e) {
            printf("  - %s: ERROR: %s\n", $id, $e->getMessage());
            $imapFailed = true;
        } finally {
            $mailbox->close();
        }
    }
}

if ($warnings !== []) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "  - {$warning}\n";
    }
}
if ($imapFailed) {
    echo "\nERROR: the configuration is valid, but the IMAP check failed.\n";
    exit(1);
}
echo "\nOK: the configuration is valid.\n";
