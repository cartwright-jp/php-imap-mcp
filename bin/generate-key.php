<?php

declare(strict_types=1);

/*
 * Creates the encryption key that seals the OAuth codes and tokens (config.php auth.oauth.key, SPEC.md 7).
 *
 *   php bin/generate-key.php
 *
 * The key seals every token. Replacing it signs every user out at once (the "revoke everything" switch).
 */

use Cartwright\ImapMcp\Auth\OAuth\Sealer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$key = Sealer::generateKey();

echo <<<TEXT
    Put this in config.php under auth.oauth (keep config.php at chmod 600):

      'key' => '{$key}',

    Anyone with this key and a user's token can read that user's mail password.
    Replacing the key signs every user out.

    TEXT;
