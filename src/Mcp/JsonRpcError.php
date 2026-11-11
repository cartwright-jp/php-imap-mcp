<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mcp;

use RuntimeException;

/**
 * A JSON-RPC error to send back, with the HTTP status the transport requires.
 */
final class JsonRpcError extends RuntimeException
{
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly int $httpStatus = 200,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message);
    }

    public static function parseError(): self
    {
        return new self(ErrorCode::PARSE_ERROR, 'Parse error: invalid JSON', 400);
    }

    public static function invalidRequest(string $message): self
    {
        return new self(ErrorCode::INVALID_REQUEST, $message, 400);
    }

    public static function invalidParams(string $message, int $httpStatus = 200): self
    {
        return new self(ErrorCode::INVALID_PARAMS, $message, $httpStatus);
    }

    public static function methodNotFound(string $method, int $httpStatus): self
    {
        return new self(ErrorCode::METHOD_NOT_FOUND, sprintf('Method not found: %s', $method), $httpStatus);
    }

    public static function headerMismatch(string $message): self
    {
        return new self(ErrorCode::HEADER_MISMATCH, 'Header mismatch: ' . $message, 400);
    }

    public static function unsupportedVersion(?string $requested): self
    {
        return new self(
            ErrorCode::UNSUPPORTED_PROTOCOL_VERSION,
            sprintf('Unsupported protocol version. Supported: %s', implode(', ', ProtocolVersion::all())),
            400,
            ['supported' => ProtocolVersion::all(), 'requested' => $requested],
        );
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    public function toArray(): array
    {
        $error = ['code' => $this->rpcCode, 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
