<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mcp;

/**
 * JSON-RPC error codes used by this server (JSON-RPC 2.0 and MCP 2026-07-28 basic/index#error-codes).
 */
final class ErrorCode
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /** MCP: HTTP headers missing, malformed, or not matching the body. */
    public const HEADER_MISMATCH = -32020;
    /** MCP: the requested protocol version is not supported. */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32022;
}
