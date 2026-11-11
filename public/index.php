<?php

declare(strict_types=1);

/*
 * php-imap-mcp entry point. Copy this file and .htaccess to public_html/mcp/ (SPEC.md 10).
 * Edit APP_ROOT to the directory outside public_html where php-imap-mcp is installed.
 */

const APP_ROOT = __DIR__ . '/..';

require APP_ROOT . '/vendor/autoload.php';

\Cartwright\ImapMcp\Application::run();
