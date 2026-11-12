<?php

declare(strict_types=1);

/*
 * Creates a client secret for a pre-registered OAuth client (config.php auth.oauth.clients, SPEC.md 7).
 *
 *   php bin/generate-client-secret.php          human-readable output
 *   php bin/generate-client-secret.php --json   {"secret": "...", "hash": "sha256:..."}
 *
 * The secret is shown once and is not stored anywhere; config.php keeps only its hash.
 */

use Cartwright\ImapMcp\Auth\OAuth\ClientSecret;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$secret = ClientSecret::generate();
$hash = ClientSecret::hash($secret);

if (in_array('--json', $argv, true)) {
    echo json_encode(['secret' => $secret, 'hash' => $hash], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

echo <<<TEXT
    Client secret (enter it in the client together with the client id, e.g. in the claude.ai connector's
    advanced settings, then forget it):

      {$secret}

    Hash (put this in config.php under auth.oauth.clients):

      'secret_hash' => '{$hash}',

    The secret is not saved anywhere. If you lose it, generate a new one and update both sides.

    TEXT;
