#!/usr/bin/env bash
# Devil's Rage: back up the mapper's and SeAT's databases and server settings.
# Run by cron every night (see install-backup-cron.sh). Keeps the last 7 days.
# The files contain passwords (.env): they stay on the server / your PC only.
set -euo pipefail

STACK="$HOME/wormholesystems-containers"
DEST="$HOME/backups/nightly"
KEEP_DAYS=7
STAMP=$(date +%Y-%m-%d_%H%M)

mkdir -p "$DEST"

# Database dump, using the credentials the database container already has.
docker exec wormholesystems-containers-mysql-1 sh -c \
    'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines -B "$MYSQL_DATABASE"' \
    | gzip > "$DEST/db_$STAMP.sql.gz"

# SeAT database (characters, accounts, Omega dates, skill plans, fits, Connector settings),
# with the credentials from SeAT's own .env.
SEAT="$HOME/seat-docker"
if [ -f "$SEAT/.env" ]; then
    seat_env() { grep -E "^$1=" "$SEAT/.env" | tail -1 | cut -d= -f2- | tr -d '"'; }
    ( cd "$SEAT" && docker compose exec -T -e MYSQL_PWD="$(seat_env DB_PASSWORD)" mariadb \
        mariadb-dump -u"$(seat_env DB_USERNAME)" --single-transaction --routines --no-tablespaces "$(seat_env DB_DATABASE)" ) \
        | gzip > "$DEST/seat_db_$STAMP.sql.gz"
fi

# Server settings needed to rebuild the stacks.
tar czf "$DEST/config_$STAMP.tar.gz" -C "$STACK" .env dockerfiles/mysql/.env docker-compose.prod.yml
if [ -f "$SEAT/.env" ]; then
    tar czf "$DEST/seat_config_$STAMP.tar.gz" -C "$SEAT" .env $(cd "$SEAT" && ls docker-compose*.yml mariadb-small.cnf 2>/dev/null)
fi

# Drop backups older than KEEP_DAYS.
find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete

# Tell the mapper (its /health check warns when backups stop).
( cd "$STACK" && docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan devilsrage:backup-done >/dev/null ) || true

echo "$(date -Is) backup ok: mapper $(du -h "$DEST/db_$STAMP.sql.gz" | cut -f1), SeAT $(du -h "$DEST/seat_db_$STAMP.sql.gz" 2>/dev/null | cut -f1 || echo none)"
