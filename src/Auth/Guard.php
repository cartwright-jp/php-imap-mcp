<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Log\AuditLogger;
use Cartwright\ImapMcp\Mcp\ErrorCode;

/**
 * Runs before any JSON-RPC handling (SPEC.md 4 steps 1-2):
 * source IP → Origin → lockout → Bearer token. Only requests that pass reach the MCP server.
 */
final class Guard
{
    public function __construct(
        private readonly Config $config,
        private readonly BearerAuthenticator $authenticator,
        private readonly Lockout $lockout,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return Response|Principal the response to send when the request is refused, else who the request acts for
     */
    public function check(Request $request): Response|Principal
    {
        $refused = $this->checkSource($request);
        if ($refused !== null) {
            return $refused;
        }
        try {
            return $this->authenticator->authenticate($request->header('Authorization'));
        } catch (AuthenticationFailed $e) {
            // Logged, but not counted towards a lockout (M7 review, 2026-11-13): a sealed token cannot be
            // guessed, and the source is Anthropic's addresses, shared by every claude.ai user. Counting would
            // only let anyone lock the owner out, and claude.ai's own token-less discovery requests counted too.
            $this->audit->record('auth_failed', ['reason' => $e->reason]);

            return self::refuse(401, 'Unauthorized', ['WWW-Authenticate' => $this->authenticator->challenge($e)]);
        }
    }

    /**
     * Source IP, Origin and lockout only: for endpoints that authenticate in their own way (the OAuth token
     * endpoint) or need no authentication (metadata).
     */
    public function checkSource(Request $request): ?Response
    {
        $ip = $request->remoteAddr;

        // Double-checks the .htaccess restriction (SPEC.md 7).
        if (!$this->config->isIpAllowed($ip)) {
            return self::refuse(403, 'Forbidden');
        }

        // DNS rebinding protection: an Origin header, when present, must be on the allow list (SPEC.md 5).
        $origin = $request->header('Origin');
        if ($origin !== null && !in_array($origin, $this->config->allowedOrigins, true)) {
            return self::refuse(403, 'Origin not allowed');
        }

        // A banned IP is refused without looking at the token, so guessing is not possible while banned.
        if ($this->lockout->bannedFor($ip) !== null) {
            return self::refuse(403, 'Too many authentication failures; try again later');
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function refuse(int $status, string $message, array $headers = []): Response
    {
        return Response::json(
            $status,
            ['jsonrpc' => '2.0', 'error' => ['code' => ErrorCode::INVALID_REQUEST, 'message' => $message]],
            $headers,
        );
    }
}
