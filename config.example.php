<?php

declare(strict_types=1);

/*
 * php-imap-mcp configuration (SPEC.md 7, 8).
 *
 * Users sign in on the server's login page with their mail address and password; the server checks them with
 * an actual IMAP login and seals them into the tokens it issues. No mail password is stored in this file.
 *
 * Copy to config.php OUTSIDE public_html (e.g. ~/php-imap-mcp/config.php) and run: chmod 600 config.php
 * Check it with: php bin/check-config.php --login you@example.com
 * Unknown keys are rejected, so a typo cannot silently turn a restriction off.
 */

return [
    'auth' => [
        'oauth' => [
            // The MCP endpoint URL exactly as registered in the client (keep the trailing slash).
            'resource' => 'https://example.com/mcp/',
            // Create with: php bin/generate-key.php. Replacing it signs every user out.
            'key' => 'base64:REPLACE-WITH-OUTPUT-OF-generate-key',
            'imap' => ['host' => 'svXXXXX.xserver.jp', 'port' => 993, 'encryption' => 'ssl'],
            // The login page is open to the Internet: only these mail domains may sign in.
            'allowed_email_domains' => ['example.com'],
            'access_ttl_sec' => 3600,
            // Each refresh extends the sign-in by this many days; unused longer, the user signs in again.
            'refresh_ttl_days' => 30,
            // Pre-registered clients. Enter id and secret in the client (claude.ai: connector advanced settings).
            // Create a secret with php bin/generate-client-secret.php and put only its hash here.
            'clients' => [
                [
                    'id' => 'claude',
                    'secret_hash' => 'sha256:REPLACE-WITH-OUTPUT-OF-generate-client-secret',
                    // Must match exactly. claude.ai (web, desktop and mobile apps) uses this one
                    // (https://claude.com/docs/connectors/building/authentication).
                    'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
                    'name' => 'Claude',
                ],
            ],
            'folders' => ['drafts' => null, 'sent' => null], // null = detect from special-use flags
        ],
        // The login page is not IP-restricted; this applies to the MCP, token and metadata endpoints.
        // claude.ai connects from Anthropic's outbound ranges: https://platform.claude.com/docs/en/api/ip-addresses
        // Empty = no restriction (a warning is shown). Clients that connect from your own device (Claude Code)
        // need your IP here instead.
        'allowed_ips' => ['160.79.104.0/21', '153.61.208.0/20'],
        'allowed_origins' => [],
        // Counts failed logins (per IP and per mail address) and failed client authentication at the token
        // endpoint. Bad tokens at the MCP endpoint are only logged, not counted.
        'lockout' => ['max_failures' => 10, 'window_sec' => 600, 'ban_sec' => 900],
    ],
    'tools' => [
        'list_folders' => true,
        'search_emails' => true,
        'get_email' => true,
        'save_draft' => true,
        'set_flags' => false,
        'move_email' => false,
    ],
    'policy' => [
        'allowed_folders' => [],
        'search_limit_max' => 50,
        'body_max_chars' => 20000,
        'deny_move_to_trash' => true,
    ],
    'runtime' => [
        'timezone' => 'Asia/Tokyo',
        'imap_timeout_sec' => 10,
        'request_budget_sec' => 25,
        'storage_dir' => __DIR__ . '/var', // audit log, lockout, used OAuth codes; outside public_html
        'log_level' => 'info',
    ],
];
