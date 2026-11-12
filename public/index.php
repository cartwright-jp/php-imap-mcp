<?php

declare(strict_types=1);

/*
 * php-imap-mcp entry point. Copy this file and .htaccess to public_html/mcp/ (SPEC.md 10).
 * Edit APP_ROOT to the directory outside public_html where php-imap-mcp is installed,
 * e.g. '/home/USER/php-imap-mcp'.
 */

const APP_ROOT = __DIR__ . '/..';

// config.php must be outside public_html. The PHP_IMAP_MCP_CONFIG environment variable overrides this.
const CONFIG_PATH = APP_ROOT . '/config.php';

require APP_ROOT . '/vendor/autoload.php';

\Cartwright\ImapMcp\Application::run(CONFIG_PATH);
