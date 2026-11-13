# Courier-IMAP test server for integration tests (never use in production).
# XServer runs Courier-IMAP, and it is strict about the protocol (for example it refuses an APPEND to a folder
# that is open read-only), so the integration tests run against both. Built from Debian's own packages.
#
# Test account (login is the full address, as on XServer): test@example.com / test-password
# Folders as on XServer: INBOX with INBOX.Drafts, INBOX.Sent, INBOX.Trash, INBOX.Junk ("." delimiter).
FROM debian:bookworm-slim

RUN export DEBIAN_FRONTEND=noninteractive \
    && apt-get update \
    && apt-get install -y --no-install-recommends courier-imap courier-authlib-userdb \
    && rm -rf /var/lib/apt/lists/*

RUN useradd --uid 5000 --user-group --home-dir /home/vmail --create-home vmail \
    && mkdir -p /home/vmail/test \
    && maildirmake /home/vmail/test/Maildir \
    && for f in Drafts Sent Trash Junk; do maildirmake -f "$f" /home/vmail/test/Maildir; done \
    && chown -R vmail:vmail /home/vmail \
    && sed -i 's/^authmodulelist=.*/authmodulelist="authuserdb"/' /etc/courier/authdaemonrc \
    && userdb test@example.com set home=/home/vmail/test mail=/home/vmail/test/Maildir uid=5000 gid=5000 \
    && printf 'test-password\ntest-password\n' | userdbpw -md5 | userdb test@example.com set systempw \
    && makeuserdb

COPY start.sh /start.sh
EXPOSE 993
CMD ["sh", "/start.sh"]
