#!/usr/bin/env bash
# End-to-end smoke test of the MCP endpoint with curl (SPEC.md 11).
# Usage: bash tests/e2e/smoke.sh [URL]   (default: http://127.0.0.1:18080/, see composer serve)
# Extra curl options (e.g. an Authorization header from M2 on) can be passed in E2E_CURL_OPTS.
set -u
url="${1:-http://127.0.0.1:18080/}"
read -r -a extra <<< "${E2E_CURL_OPTS:-}"
modern='2026-07-28'
meta='"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"smoke","version":"1"},"io.modelcontextprotocol/clientCapabilities":{}}'
failures=0

# check NAME EXPECTED_STATUS EXPECTED_BODY_SUBSTRING curl-args...
check() {
  local name="$1" status="$2" needle="$3"
  shift 3
  local out code body
  out="$(curl -sS -w $'\n%{http_code}' "${extra[@]}" "$@" "$url")" || { echo "FAIL $name: curl error"; failures=$((failures + 1)); return; }
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
check 'server/discover' 200 '"supportedVersions":["2026-07-28","2025-11-25"]' "${a[@]}"

mapfile -t a < <(post tools/list '' "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\",\"params\":{$meta}}")
check 'tools/list' 200 '"name":"echo"' "${a[@]}"

mapfile -t a < <(post tools/call echo "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"echo\",\"arguments\":{\"text\":\"日本語\"},$meta}}")
check 'tools/call echo' 200 '"text":"日本語"' "${a[@]}"

mapfile -t a < <(post tools/list '' "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"echo\",$meta}}")
check 'Mcp-Method mismatch' 400 '-32020' "${a[@]}"

mapfile -t a < <(post resources/list '' "{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"resources/list\",\"params\":{$meta}}")
check 'unknown method' 404 '-32601' "${a[@]}"

check 'legacy initialize' 200 '"protocolVersion":"2025-11-25"' -X POST -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":6,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}'
check 'legacy notifications/initialized' 202 '' -X POST -H 'Content-Type: application/json' \
  -H 'MCP-Protocol-Version: 2025-11-25' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'
check 'legacy tools/list' 200 '"name":"echo"' -X POST -H 'Content-Type: application/json' \
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
