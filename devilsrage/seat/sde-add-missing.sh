#!/usr/bin/env bash
# Adds EVE items that SeAT's (outdated) static data is missing, taken from
# Fuzzwork's current dump. Only ADDS missing rows; existing rows are never
# changed or deleted. Stopgap until SeAT ships an importer for Fuzzwork's new
# format; SeAT's own SDE update replaces these tables completely anyway.
#
#   bash sde-add-missing.sh           check: download + safety checks, no changes
#   bash sde-add-missing.sh --apply   back up the tables, then add missing rows
set -euo pipefail

DIR="$HOME/seat-docker"
BASE="https://www.fuzzwork.co.uk/dump/latest/mysql_tables"
TABLES="invCategories invGroups invMarketGroups invTypes"
STAMP="$(date +%Y%m%d-%H%M)"
WORK="$DIR/sde-tmp"

APPLY=no
if [ "${1:-}" = "--apply" ]; then APPLY=yes; fi

cd "$DIR"
mkdir -p "$WORK" backups

db() {  # db [mariadb options...]  (SQL on stdin or via -e)
  docker compose exec -T mariadb sh -c 'mariadb -useat -p"$MYSQL_PASSWORD" seat "$@"' sh "$@"
}

for t in $TABLES; do
  echo "== $t"
  curl -fsS "$BASE/$t.sql.gz" | gunzip > "$WORK/$t.orig.sql"
  # Load under a different name so the dump can never touch SeAT's own table.
  sed "s/\`$t\`/\`${t}_fwnew\`/g" "$WORK/$t.orig.sql" > "$WORK/$t.sql"
  if grep -Eq "\`$t\`" "$WORK/$t.sql" \
     || grep -Eiq "(TABLE|INTO|EXISTS|TABLES)[[:space:]]+$t([[:space:]]|\(|;|$)" "$WORK/$t.sql" \
     || ! grep -q "CREATE TABLE \`${t}_fwnew\`" "$WORK/$t.sql"; then
    echo "   Safety check failed: the dump still refers to $t directly. Stopping."
    exit 1
  fi
  echo "   downloaded and renamed to ${t}_fwnew (safe)"
done

if [ "$APPLY" = no ]; then
  echo
  echo "Check only, nothing changed. To add the missing rows: bash $0 --apply"
  exit 0
fi

echo
echo "Backing up SeAT's current tables to $DIR/backups/sde-before-$STAMP.sql.gz"
docker compose exec -T mariadb sh -c 'mariadb-dump -useat -p"$MYSQL_PASSWORD" seat "$@"' sh $TABLES \
  | gzip > "backups/sde-before-$STAMP.sql.gz"

for t in $TABLES; do
  echo "== $t"
  db < "$WORK/$t.sql"
  cols=$(db -N -e "SELECT GROUP_CONCAT(CONCAT(CHAR(96),c.COLUMN_NAME,CHAR(96)) ORDER BY c.ORDINAL_POSITION)
    FROM information_schema.COLUMNS c
    JOIN information_schema.COLUMNS n
      ON n.TABLE_SCHEMA = c.TABLE_SCHEMA AND n.TABLE_NAME = '${t}_fwnew' AND n.COLUMN_NAME = c.COLUMN_NAME
    WHERE c.TABLE_SCHEMA = 'seat' AND c.TABLE_NAME = '$t'" | tr -d '\r')
  if [ -z "$cols" ] || [ "$cols" = "NULL" ]; then
    echo "   no shared columns found, skipping"
    db -e "DROP TABLE IF EXISTS ${t}_fwnew"
    continue
  fi
  before=$(db -N -e "SELECT COUNT(*) FROM $t" | tr -d '\r')
  db -e "INSERT IGNORE INTO $t ($cols) SELECT $cols FROM ${t}_fwnew; DROP TABLE ${t}_fwnew;"
  after=$(db -N -e "SELECT COUNT(*) FROM $t" | tr -d '\r')
  echo "   added $((after - before)) missing rows ($before -> $after)"
done

rm -rf "$WORK"
echo
echo "Done. Backup: $DIR/backups/sde-before-$STAMP.sql.gz"
