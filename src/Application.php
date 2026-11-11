<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp;

use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Mcp\Server;
use Cartwright\ImapMcp\Tools\EchoTool;
use Cartwright\ImapMcp\Tools\ToolRegistry;
use Throwable;

/**
 * Wires the server together and handles one HTTP request (called from public/index.php).
 */
final class Application
{
    public static function createServer(): Server
    {
        // M1: only the dummy tool. Tool selection moves to config.php in M2 (SPEC.md 8).
        return new Server(new ToolRegistry([new EchoTool()]));
    }

    public static function run(): void
    {
        try {
            $response = self::createServer()->handle(Request::fromGlobals(Server::MAX_BODY_BYTES));
        } catch (Throwable $e) {
            error_log(sprintf('php-imap-mcp: unhandled %s', $e::class));
            $response = Response::json(500, [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32603, 'message' => 'Internal error'],
            ]);
        }
        $response->send();
    }
}
