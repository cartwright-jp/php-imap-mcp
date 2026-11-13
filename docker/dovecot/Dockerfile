# Dovecot test server for integration tests (never use in production).
# Dovecot is the most common IMAP server (about 77% in the 2020 Open Email Survey). Unlike Courier it announces
# SPECIAL-USE flags and allows top-level folders, so it covers the other detection path. Built from Debian's
# own packages.
#
# Test account (login is the full address, as on XServer): test@example.com / test-password
# Folders: INBOX, Drafts, Sent, Trash, Junk with \Drafts, \Sent, \Trash, \Junk ("." delimiter).
FROM debian:bookworm-slim

RUN export DEBIAN_FRONTEND=noninteractive \
    && apt-get update \
    && apt-get install -y --no-install-recommends dovecot-imapd ssl-cert \
    && rm -rf /var/lib/apt/lists/*

RUN useradd --uid 5000 --user-group --home-dir /home/vmail --create-home vmail \
    && sed -i 's/^!include auth-system.conf.ext/#!include auth-system.conf.ext/' /etc/dovecot/conf.d/10-auth.conf \
    && echo 'test@example.com:{PLAIN}test-password' > /etc/dovecot/users \
    && chown root:dovecot /etc/dovecot/users && chmod 640 /etc/dovecot/users

COPY local.conf /etc/dovecot/local.conf
EXPOSE 993
CMD ["dovecot", "-F"]
