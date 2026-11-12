<?php

declare(strict_types=1);

/*
 * php-imap-mcp configuration (SPEC.md 8).
 *
 * Copy to config.php OUTSIDE public_html (e.g. ~/php-imap-mcp/config.php) and run: chmod 600 config.php
 * Check it with: php bin/check-config.php
 * Unknown keys are rejected, so a typo cannot silently turn a restriction off.
 */

return [
    // MVP uses one account; the structure allows several.
    'accounts' => [
        'default' => [
            'email' => 'you@example.com',
            'password' => 'CHANGE-ME', // The mail account password (removed by the IMAP OAuth of v0.3)
            'imap' => ['host' => 'svXXXXX.xserver.jp', 'port' => 993, 'encryption' => 'ssl'], // ssl or starttls
            'from_name' => 'Taro Yamada', // From display name of drafts
            'folders' => ['drafts' => null, 'sent' => null], // null = detect from special-use flags
        ],
    ],
    'auth' => [
        'mode' => 'token',
        // Create a token with: php bin/generate-token.php
        // Put only the hash here; the token itself goes into the connector's Authorization header.
        // Several tokens may be listed at once to rotate them without downtime.
        'tokens' => [
            ['hash' => 'sha256:REPLACE-WITH-OUTPUT-OF-generate-token', 'account' => 'default', 'label' => 'claude.ai'],
        ],
        // Source IPs or CIDR ranges allowed to connect. Empty = any IP (a warning is logged).
        // Use Anthropic's published outbound ranges, plus your own IP while developing.
        'allowed_ips' => ['203.0.113.0/24'],
        // Origin header values to accept (requests without Origin are not affected).
        'allowed_origins' => [],
        // Ban an IP for ban_sec after max_failures failed logins within window_sec.
        'lockout' => ['max_failures' => 10, 'window_sec' => 600, 'ban_sec' => 900],
    ],
    // Read tools and save_draft are on by default; write tools must be enabled one by one.
    'tools' => [
        'list_folders' => true,
        'search_emails' => true,
        'get_email' => true,
        'save_draft' => true,
        'set_flags' => false,
        'move_email' => false,
    ],
    'policy' => [
        'allowed_folders' => [], // empty = all folders
        'search_limit_max' => 50,
        'body_max_chars' => 20000,
        'deny_move_to_trash' => true,
    ],
    'runtime' => [
        'timezone' => 'Asia/Tokyo',
        'imap_timeout_sec' => 10,
        'request_budget_sec' => 25,
        'storage_dir' => __DIR__ . '/var', // audit log and lockout state; must be outside public_html
        'log_level' => 'info',
    ],
];
