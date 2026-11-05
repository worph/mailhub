#!/usr/bin/env sh
# Create the SQLite database first and hand it to the web server, then run the
# upstream entrypoint (which would otherwise leave the file owned by root, and
# every login would fail).
set -e
cd /usr/local/share/cypht
if [ "${DB_DRIVER:-}" = "sqlite" ] && [ -n "${DB_NAME:-}" ]; then
    mkdir -p "$(dirname "$DB_NAME")"
    ./scripts/setup_database.php
    chown -R www-data:www-data "$(dirname "$DB_NAME")"
fi
exec docker-entrypoint.sh "$@"
