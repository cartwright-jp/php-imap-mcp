<?php

declare(strict_types=1);

/*
 * Writes a throwaway config.php for the E2E smoke test and prints its fresh token.
 *
 *   TOKEN=$(php tests/e2e/make-config.php /tmp/php-imap-mcp-e2e)
 *   PHP_IMAP_MCP_CONFIG=/tmp/php-imap-mcp-e2e/config.php php -S 127.0.0.1:18080 -t public &
 *   MCP_TOKEN=$TOKEN composer test:e2e
 *
 * The account points at the local test mail server; the smoke test only checks that tools fail cleanly
 * without it (production code verifies certificates, so it cannot log in to the self-signed test server).
 */

use Cartwright\ImapMcp\Auth\TokenAuthenticator;
use Cartwright\ImapMcp\Auth\TokenEntry;

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "usage: php tests/e2e/make-config.php DIRECTORY\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dir = rtrim($argv[1], '/');
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "cannot create $dir\n");
    exit(1);
}

$token = TokenAuthenticator::generateToken();
$config = [
    'accounts' => [
        'default' => [
            'email' => 'test@example.com',
            'password' => 'test-password',
            'imap' => ['host' => '127.0.0.1', 'port' => 3993, 'encryption' => 'ssl'],
        ],
    ],
    'auth' => [
        'tokens' => [['hash' => TokenEntry::hashOf($token), 'account' => 'default', 'label' => 'e2e']],
        'allowed_ips' => ['127.0.0.1', '::1'],
    ],
    'runtime' => ['storage_dir' => $dir . '/var'],
];

$path = $dir . '/config.php';
file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
chmod($path, 0600);

echo $token, "\n";
