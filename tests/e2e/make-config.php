<?php

declare(strict_types=1);

/*
 * Writes a throwaway config.php for the E2E smoke tests and prints an access token for it.
 *
 *   TOKEN=$(php tests/e2e/make-config.php /tmp/php-imap-mcp-e2e)
 *   PHP_IMAP_MCP_CONFIG=/tmp/php-imap-mcp-e2e/config.php php -S 127.0.0.1:18080 -t public public/index.php &
 *   MCP_TOKEN=$TOKEN composer test:e2e
 *   bash tests/e2e/oauth-smoke.sh
 *
 * The token is sealed here with the config's key, as the token endpoint would issue it after a login, so the
 * MCP smoke test needs no browser. Its account points at the local test mail server; the smoke test only
 * checks that tools fail cleanly without it (production code verifies certificates, so it cannot log in to
 * the self-signed test server).
 */

use Cartwright\ImapMcp\Auth\OAuth\Sealer;

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

$resource = 'http://127.0.0.1:18080/';
$key = Sealer::generateKey();
$config = [
    'auth' => [
        'oauth' => [
            'resource' => $resource,
            'key' => $key,
            'imap' => ['host' => '127.0.0.1', 'port' => 3993],
            'allowed_email_domains' => ['example.com'],
            'clients' => [['id' => 'e2e', 'secret_hash' => null, 'redirect_uris' => ['http://localhost/cb']]],
        ],
        'allowed_ips' => ['127.0.0.1', '::1'],
    ],
    'runtime' => ['storage_dir' => $dir . '/var'],
];

$path = $dir . '/config.php';
file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n");
chmod($path, 0600);

$sealer = new Sealer((string) base64_decode(substr($key, 7), true));
echo $sealer->seal(Sealer::ACCESS_TOKEN, [
    'jti' => bin2hex(random_bytes(16)),
    'gid' => bin2hex(random_bytes(16)),
    'sub' => 'test@example.com',
    'pwd' => 'test-password',
    'cid' => 'e2e',
    'aud' => $resource,
    'scope' => 'mail',
    'iat' => time(),
    'exp' => time() + 3600,
]), "\n";
