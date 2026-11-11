<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Http;

/**
 * Minimal HTTP request: method, case-insensitive headers and raw body.
 */
final class Request
{
    /** @var array<string, string> header name (lowercase) => value */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        array $headers,
        public readonly string $body,
        public readonly bool $bodyTooLarge = false,
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    /**
     * Builds the request from PHP globals. Works under mod_php, CGI and FastCGI.
     *
     * @param positive-int $maxBodyBytes
     */
    public static function fromGlobals(int $maxBodyBytes): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }
        // Some hosts (e.g. XServer with CGI) pass Authorization only via .htaccess SetEnvIf / RewriteRule.
        if (!isset($headers['AUTHORIZATION'])) {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'HTTP_AUTHORIZATION'] as $key) {
                $value = $_SERVER[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $headers['AUTHORIZATION'] = $value;
                    break;
                }
            }
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $body = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        $body = $body === false ? '' : $body;
        $tooLarge = strlen($body) > $maxBodyBytes;

        return new self(
            is_string($method) ? strtoupper($method) : 'GET',
            $headers,
            $tooLarge ? '' : $body,
            $tooLarge,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }
}
