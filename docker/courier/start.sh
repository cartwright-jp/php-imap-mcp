#!/bin/sh
# Starts the authentication daemon and IMAPS (port 993), then waits until the container is stopped.
set -e
/etc/init.d/courier-authdaemon start
/etc/init.d/courier-imap-ssl start
trap 'exit 0' TERM INT
while :; do sleep 3600 & wait $!; done
