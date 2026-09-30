#!/usr/bin/env bash
# Devil's Rage: back up the mapper's database and server settings.
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

# Server settings needed to rebuild the stack.
tar czf "$DEST/config_$STAMP.tar.gz" -C "$STACK" .env dockerfiles/mysql/.env docker-compose.prod.yml

# Drop backups older than KEEP_DAYS.
find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete

echo "$(date -Is) backup ok: $(du -h "$DEST/db_$STAMP.sql.gz" | cut -f1) database"
