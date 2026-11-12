<?php

declare(strict_types=1);

/*
 * Validates config.php and shows a summary without any secrets (SPEC.md 8).
 *
 *   php bin/check-config.php [--login user@example.com] [path/to/config.php]
 *
 * With --login, it asks for that mailbox's password, logs in to the IMAP server with it and finds the
 * Drafts folder. The password is used once and not stored.
 * Default path: the PHP_IMAP_MCP_CONFIG environment variable, else config.php next to bin/.
 * Exit code 0 = usable (warnings may be shown), 1 = the server would refuse to start or the login failed.
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
$login = null;
$rest = [];
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--login') {
        $login = $args[++$i] ?? '';
        continue;
    }
    $rest[] = $args[$i];
}
$path = $rest[0] ?? ConfigLoader::resolvePath(dirname(__DIR__) . '/config.php');
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

$oauth = $config->oauth;
printf("MCP URL to register in the client: %s\n", $oauth->resource);
printf("Issuer: %s\n", $oauth->issuer());
printf("Login page (open to every IP): %s\n", $oauth->authorizationEndpoint());
printf("Token endpoint: %s\n", $oauth->tokenEndpoint());
printf("Protected resource metadata: %s\n", $oauth->protectedResourceMetadataUrl());
printf("IMAP: %s:%d (%s)\n", $oauth->imapHost, $oauth->imapPort, $oauth->imapEncryption);
printf("Mail domains that may sign in: %s\n", implode(', ', $oauth->allowedEmailDomains));
printf(
    "Access token: %ds; refresh token: extended by %d days on each use\n",
    $oauth->accessTtlSec,
    $oauth->refreshTtlDays,
);
echo "Clients:\n";
foreach ($oauth->clients as $client) {
    printf(
        "  - %s (%s, %s): %s\n",
        $client->id,
        $client->name,
        $client->isConfidential() ? 'with secret' : 'public',
        implode(', ', $client->redirectUris),
    );
}
$ranges = array_map(static fn (IpRange $range): string => $range->notation, $config->allowedIps);
printf("Allowed IPs (all but the login page): %s\n", $ranges === [] ? '(any)' : implode(', ', $ranges));
$lockout = $config->lockout;
printf("Lockout: %d failures in %ds -> %ds ban\n", $lockout->maxFailures, $lockout->windowSec, $lockout->banSec);
$enabled = array_filter(array_keys(ToolPolicy::DEFAULTS), static fn (string $t): bool => $config->tools->isEnabled($t));
printf("Enabled tools: %s\n", implode(', ', $enabled));
printf("Storage: %s\n", $storage);

// Reads a line from the terminal without echoing it when possible.
$readPassword = static function (string $prompt): string {
    echo $prompt;
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN) && function_exists('shell_exec');
    if ($tty) {
        @shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        @shell_exec('stty echo');
        echo "\n";
    }

    return rtrim($line === false ? '' : $line, "\r\n");
};

$loginFailed = false;
if ($login === null) {
    echo "\nIMAP: not checked (no password is stored; run with --login user@example.com to test a mailbox)\n";
} else {
    $password = $readPassword(sprintf('Password for %s: ', $login));
    $account = $oauth->account($login, $password);
    $mailbox = new ImapMailbox($account, $config->runtime->imapTimeoutSec);
    echo "\nIMAP:\n";
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
        printf("  - login OK, %d folders, Drafts: %s\n", count($folders), $drafts ?? 'NOT FOUND');
        if ($drafts === null) {
            $warnings[] = 'no Drafts folder found; set auth.oauth.folders.drafts in config.php';
        }
        if (!$oauth->isEmailAllowed($login)) {
            $warnings[] = 'this address is not in auth.oauth.allowed_email_domains, so it cannot sign in';
        }
    } catch (MailException $e) {
        printf("  - ERROR: %s\n", $e->getMessage());
        $loginFailed = true;
    } finally {
        $mailbox->close();
    }
}

if ($warnings !== []) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "  - {$warning}\n";
    }
}
if ($loginFailed) {
    echo "\nERROR: the configuration is valid, but the IMAP login failed.\n";
    exit(1);
}
echo "\nOK: the configuration is valid.\n";
