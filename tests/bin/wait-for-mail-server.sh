#!/usr/bin/env bash
# Wait until the test mail server (docker-compose.yml) answers with an IMAP greeting over TLS.
# An open port is not enough: Docker accepts connections before the server is ready.
# GreenMail is on 3993 (the default), Courier-IMAP on 3994, Dovecot on 3995 (IMAP_TEST_PORT=...).
set -u
host="${IMAP_TEST_HOST:-127.0.0.1}"
port="${IMAP_TEST_PORT:-3993}"
for _ in $(seq 1 30); do
  if timeout 3 openssl s_client -connect "${host}:${port}" -quiet </dev/null 2>/dev/null | grep -q '^\* OK'; then
    echo "Test mail server is ready at ${host}:${port}"
    exit 0
  fi
  sleep 2
done
echo "Test mail server did not become ready at ${host}:${port}" >&2
exit 1
