#!/usr/bin/env bash
# End-to-end smoke test of the MCP endpoint with curl (SPEC.md 11).
# Usage: MCP_TOKEN=... bash tests/e2e/smoke.sh [URL]   (default: http://127.0.0.1:18080/, see composer serve)
# MCP_TOKEN is the server's Bearer token; tests/e2e/make-config.php creates a throwaway config with one.
# Extra curl options can be passed in E2E_CURL_OPTS.
set -u
url="${1:-http://127.0.0.1:18080/}"
if [[ -z "${MCP_TOKEN:-}" ]]; then
  echo 'MCP_TOKEN is not set (see tests/e2e/make-config.php)' >&2
  exit 2
fi
read -r -a extra <<< "${E2E_CURL_OPTS:-}"
auth=(-H "Authorization: Bearer $MCP_TOKEN")
modern='2026-07-28'
meta='"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"smoke","version":"1"},"io.modelcontextprotocol/clientCapabilities":{}}'
failures=0

# check NAME EXPECTED_STATUS EXPECTED_BODY_SUBSTRING curl-args...   (sends the token)
# check_noauth ...                                                   (same, without the token)
check() { run_check 1 "$@"; }
check_noauth() { run_check 0 "$@"; }
run_check() {
  local with_auth="$1" name="$2" status="$3" needle="$4"
  shift 4
  local out code body
  local args=("${extra[@]}")
  if (( with_auth )); then args+=("${auth[@]}"); fi
  out="$(curl -sS -w $'\n%{http_code}' "${args[@]}" "$@" "$url")" || { echo "FAIL $name: curl error"; failures=$((failures + 1)); return; }
  code="${out##*$'\n'}"
  body="${out%$'\n'*}"
  if [[ "$code" == "$status" && "$body" == *"$needle"* ]]; then
    echo "ok   $name ($code)"
  else
    echo "FAIL $name: expected $status containing '$needle', got $code: $body"
    failures=$((failures + 1))
  fi
}

post() { # post METHOD-HEADER [NAME-HEADER] BODY
  local method="$1" name="$2" body="$3"
  local args=(-X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream'
    -H "MCP-Protocol-Version: $modern" -H "Mcp-Method: $method")
  [[ -n "$name" ]] && args+=(-H "Mcp-Name: $name")
  printf '%s\n' "${args[@]}" "-d" "$body"
}

mapfile -t a < <(post server/discover '' "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"server/discover\",\"params\":{$meta}}")
check_noauth 'no token' 401 'Unauthorized' "${a[@]}"
check_noauth 'wrong token' 401 'Unauthorized' -H 'Authorization: Bearer wrong-token' "${a[@]}"
check 'server/discover' 200 '"supportedVersions":["2026-07-28","2025-11-25"]' "${a[@]}"

mapfile -t a < <(post tools/list '' "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\",\"params\":{$meta}}")
check 'tools/list' 200 '"name":"search_emails"' "${a[@]}"

# The throwaway config points at a mail server this test does not need: the tool must fail cleanly
# (isError with a short message, no stack trace or path).
mapfile -t a < <(post tools/call list_folders "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"list_folders\",\"arguments\":{},$meta}}")
check 'tools/call without mail server' 200 '"isError":true' "${a[@]}"

mapfile -t a < <(post tools/call get_email "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"get_email\",\"arguments\":{\"uid\":\"x\"},$meta}}")
check 'tools/call invalid arguments' 200 '-32602' "${a[@]}"

mapfile -t a < <(post tools/list '' "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"list_folders\",$meta}}")
check 'Mcp-Method mismatch' 400 '-32020' "${a[@]}"

mapfile -t a < <(post resources/list '' "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"resources/list\",\"params\":{$meta}}")
check 'unknown method' 404 '-32601' "${a[@]}"

check 'legacy initialize' 200 '"protocolVersion":"2025-11-25"' -X POST -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":6,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}'
check 'legacy notifications/initialized' 202 '' -X POST -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2025-11-25' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'
check 'legacy tools/list' 200 '"name":"get_email"' -X POST -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2025-11-25' -d '{"jsonrpc":"2.0","id":7,"method":"tools/list"}'
check 'legacy ping' 200 '"result":{}' -X POST -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2025-11-25' -d '{"jsonrpc":"2.0","id":8,"method":"ping"}'

check 'GET is not allowed' 405 '' -X GET
check 'DELETE is not allowed' 405 '' -X DELETE
check 'foreign Origin' 403 'Origin not allowed' -X POST -H 'Content-Type: application/json' -H 'Origin: https://evil.example' -d '{}'
check 'invalid JSON' 400 '-32700' -X POST -H 'Content-Type: application/json' -d '{"jsonrpc":'

if (( failures > 0 )); then
  echo "$failures check(s) failed"
  exit 1
fi
echo "all checks passed"
