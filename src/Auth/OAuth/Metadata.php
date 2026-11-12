<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

/**
 * The discovery documents (SPEC.md 7; MCP authorization server discovery).
 */
final class Metadata
{
    /**
     * OAuth 2.0 Protected Resource Metadata (RFC 9728). Served at <issuer>/.well-known/oauth-protected-resource,
     * which the 401 challenge points to.
     *
     * @return array<string, mixed>
     */
    public static function protectedResource(OAuthSettings $settings): array
    {
        return [
            'resource' => $settings->resource,
            'authorization_servers' => [$settings->issuer()],
            'scopes_supported' => [OAuthSettings::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'php-imap-mcp',
        ];
    }

    /**
     * OAuth 2.0 Authorization Server Metadata (RFC 8414). Served at <issuer>/.well-known/openid-configuration
     * (inside the MCP directory, the third place MCP clients try) and, if the site root forwards it, at
     * /.well-known/oauth-authorization-server/<path>.
     *
     * @return array<string, mixed>
     */
    public static function authorizationServer(OAuthSettings $settings): array
    {
        $public = false;
        foreach ($settings->clients as $client) {
            $public = $public || !$client->isConfidential();
        }

        return [
            'issuer' => $settings->issuer(),
            'authorization_endpoint' => $settings->authorizationEndpoint(),
            'token_endpoint' => $settings->tokenEndpoint(),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => $public
                ? ['client_secret_basic', 'client_secret_post', 'none']
                : ['client_secret_basic', 'client_secret_post'],
            'scopes_supported' => [OAuthSettings::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
            // Clients are pre-registered in config.php: no registration_endpoint (Dynamic Client Registration
            // is deprecated) and no Client ID Metadata Documents yet (SPEC.md 7).
        ];
    }
}
