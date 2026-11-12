<?php

declare(strict_types=1);

/*
 * Signs one login out (SPEC.md 7): its access and refresh tokens stop working.
 *
 *   php bin/revoke-grant.php GRANT_ID [path/to/config.php]
 *
 * The grant id is in the audit log ("grant" of oauth_login, oauth_token and tools/call records).
 * To sign everyone out, replace auth.oauth.key instead (php bin/generate-key.php).
 */

use Cartwright\ImapMcp\Auth\OAuth\IdStore;
use Cartwright\ImapMcp\Config\ConfigException;
use Cartwright\ImapMcp\Config\ConfigLoader;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$grant = $argv[1] ?? '';
if (preg_match('/^[0-9a-f]{32}$/', $grant) !== 1) {
    fwrite(STDERR, "usage: php bin/revoke-grant.php GRANT_ID [path/to/config.php]\n");
    exit(1);
}
$path = $argv[2] ?? ConfigLoader::resolvePath(dirname(__DIR__) . '/config.php');

try {
    $config = ConfigLoader::load($path);
} catch (ConfigException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

// Kept until every refresh token of the grant would have expired (they are extended on use).
$until = time() + $config->oauth->refreshTtlSec();
(new IdStore($config->runtime->oauthDir()))->markOnce(IdStore::REVOKED_GRANTS, $grant, $until);
printf("Revoked grant %s (recorded until %s).\n", $grant, date(DATE_ATOM, $until));
