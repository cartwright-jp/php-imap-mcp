<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Http;

final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $payload, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            json_encode($payload, self::JSON_FLAGS),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(int $status, string $html, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $html);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function redirect(string $location, array $headers = []): self
    {
        return new self(302, ['Location' => $location] + $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function empty(int $status, array $headers = []): self
    {
        return new self($status, $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
