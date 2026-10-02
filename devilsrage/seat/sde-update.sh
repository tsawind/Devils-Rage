#!/usr/bin/env bash
# Points SeAT at a newer copy of EVE's static data (item names etc.) from
# Fuzzwork, because SeAT's own default can lag the game by months.
#
#   bash sde-update.sh            check only: newest copy + are all tables there
#   bash sde-update.sh --apply    also set SDE_VERSION in .env and import it
#   bash sde-update.sh --apply 3542233_20260924_133005   use a specific copy
#
# After the first --apply, switch off SeAT's monthly automatic SDE update in
# its admin settings (it would go back to SeAT's older default).
set -euo pipefail

DIR="$HOME/seat-docker"
BASE="https://www.fuzzwork.co.uk/dump"
TABLES="chrFactions dgmTypeAttributes dgmTypeEffects invCategories
invContrabandTypes invControlTowerResourcePurposes invControlTowerResources
invFlags invGroups invItems invMarketGroups invMetaGroups invMetaTypes invNames
invPositions invTypeMaterials invTypeReactions invTypes invUniqueNames
mapDenormalize ramActivities staStations"

APPLY=no
if [ "${1:-}" = "--apply" ]; then APPLY=yes; shift; fi
V="${1:-}"

if [ -z "$V" ]; then
  V=$(curl -fsS "$BASE/" | grep -o 'href="[0-9][^"/]*' | cut -d'"' -f2 | sort | tail -n 1)
fi
echo "Copy: $V"

missing=0
for t in $TABLES; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -I "$BASE/$V/$t.sql.bz2")
  if [ "$code" != "200" ]; then echo "  missing: $t ($code)"; missing=1; fi
done
if [ "$missing" = 1 ]; then
  echo "Some tables are missing in this copy. Not using it."
  exit 1
fi
echo "All 22 tables are there."

if [ "$APPLY" = no ]; then
  echo "Check only. To use it: bash $0 --apply $V"
  exit 0
fi

cd "$DIR"
if grep -q '^SDE_VERSION=' .env; then
  sed -i "s|^SDE_VERSION=.*|SDE_VERSION=$V|" .env
else
  echo "SDE_VERSION=$V" >> .env
fi
echo "Set SDE_VERSION=$V in .env. Restarting SeAT so it sees it..."
docker compose up -d
sleep 20
echo "Importing (a few minutes)..."
docker compose exec -T -e SDE_VERSION="$V" front php artisan eve:update:sde --local --force -n
echo "Done."
