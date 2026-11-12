<?php

declare(strict_types=1);

/*
 * Creates a Bearer token for config.php (SPEC.md 7).
 *
 *   php bin/generate-token.php          human-readable output
 *   php bin/generate-token.php --json   {"token": "...", "hash": "sha256:..."}
 *
 * The token is shown once and is not stored anywhere; config.php keeps only its hash.
 */

use Cartwright\ImapMcp\Auth\TokenAuthenticator;
use Cartwright\ImapMcp\Auth\TokenEntry;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$token = TokenAuthenticator::generateToken();
$hash = TokenEntry::hashOf($token);

if (in_array('--json', $argv, true)) {
    echo json_encode(['token' => $token, 'hash' => $hash], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

echo <<<TEXT
    Token (set this in the connector's request header, then forget it):

      Authorization: Bearer {$token}

    Hash (put this in config.php under auth.tokens):

      ['hash' => '{$hash}', 'account' => 'default', 'label' => 'claude.ai'],

    The token is not saved anywhere. If you lose it, generate a new one.

    TEXT;
