#!/usr/bin/env bash
# End-to-end smoke test of the OAuth endpoints (SPEC.md 7) with curl.
# Usage: bash tests/e2e/oauth-smoke.sh [BASE_URL]   (default: http://127.0.0.1:18080)
# Start the server with a config from: php tests/e2e/make-config.php DIR
# The login itself needs a real IMAP server and is covered by the unit and integration tests.
set -u
base="${1:-http://127.0.0.1:18080}"
failures=0

# check NAME EXPECTED_STATUS EXPECTED_SUBSTRING_IN_HEADERS_OR_BODY curl-args... URL
check() {
  local name="$1" status="$2" needle="$3"
  shift 3
  local out code
  out="$(curl -sS -D - -o - -w $'\n%{http_code}' "$@")" || { echo "FAIL $name: curl error"; failures=$((failures + 1)); return; }
  code="${out##*$'\n'}"
  if [[ "$code" == "$status" && "$out" == *"$needle"* ]]; then
    echo "ok   $name ($code)"
  else
    echo "FAIL $name: expected $status containing '$needle', got $code"
    printf '%s\n' "$out" | head -20
    failures=$((failures + 1))
  fi
}

meta='"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}'
check 'MCP without token points to the metadata' 401 "resource_metadata=\"$base/.well-known/oauth-protected-resource\"" \
  -X POST -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: tools/list' \
  -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\",\"params\":{$meta}}" "$base/"
check 'MCP with a forged token' 401 'error="invalid_token"' \
  -X POST -H 'Content-Type: application/json' -H 'Authorization: Bearer pim_at_forged' -d '{}' "$base/"
check 'protected resource metadata' 200 "\"authorization_servers\":[\"$base\"]" "$base/.well-known/oauth-protected-resource"
check 'authorization server metadata' 200 '"code_challenge_methods_supported":["S256"]' \
  "$base/.well-known/oauth-authorization-server"
check 'OpenID-style metadata path' 200 "\"issuer\":\"$base\"" "$base/.well-known/openid-configuration"

challenge='E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'
query="response_type=code&client_id=e2e&redirect_uri=http%3A%2F%2Flocalhost%2Fcb&state=s&code_challenge=$challenge&code_challenge_method=S256&resource=${base//:/%3A}%2F"
check 'login page' 200 'name="password"' "$base/authorize?$query"
check 'login page cannot be framed' 200 "frame-ancestors 'none'" "$base/authorize?$query"
check 'unknown client is not redirected' 400 'ログインを続けられません' "$base/authorize?client_id=evil&redirect_uri=https%3A%2F%2Fevil.example%2F"
check 'plain PKCE is refused' 302 'error=invalid_request' "$base/authorize?${query/S256/plain}"
check 'token endpoint needs a known client' 401 '"error":"invalid_client"' \
  -X POST -H 'Content-Type: application/x-www-form-urlencoded' -d 'grant_type=authorization_code&client_id=evil' "$base/token"
check 'forged code' 400 '"error":"invalid_grant"' \
  -X POST -H 'Content-Type: application/x-www-form-urlencoded' \
  -d "grant_type=authorization_code&client_id=e2e&code=pim_ac_forged&redirect_uri=http%3A%2F%2Flocalhost%2Fcb&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk" "$base/token"

if (( failures > 0 )); then
  echo "$failures check(s) failed"
  exit 1
fi
echo "all checks passed"
