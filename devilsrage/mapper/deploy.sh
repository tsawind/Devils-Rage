#!/usr/bin/env bash
# Devil's Rage: rebuild and restart the mapper in one go.
#   bash ~/wormholesystems-containers/wormhole-systems/devilsrage/mapper/deploy.sh
# Pulls the code, clears old Docker images and build cache (the disk filled up once),
# stops if there still isn't room, then builds, starts and migrates.
# SeAT is stopped during the build (RAM) and started again afterwards, even if the build fails.
set -euo pipefail

STACK="$HOME/wormholesystems-containers"
REPO="$STACK/wormhole-systems"
MIN_FREE_GB=5
C=(docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.discord.yml)

free_gb() { df --output=avail -BG / | tail -1 | tr -dc '0-9'; }

echo "== Free disk before: $(free_gb) GB"
cd "$REPO" && git pull --ff-only
cp devilsrage/mapper/docker-compose.discord.yml "$STACK/"

echo "== Clearing old images and build cache"
docker image prune -f >/dev/null
docker builder prune -af >/dev/null
echo "== Free disk now: $(free_gb) GB"
if [ "$(free_gb)" -lt "$MIN_FREE_GB" ]; then
    echo "!! Less than $MIN_FREE_GB GB free: not building. Check 'docker system df' and old backups in ~/backups."
    exit 1
fi

SEAT="$HOME/seat-docker"
if [ -d "$SEAT" ]; then
    echo "== Stopping SeAT for the build"
    (cd "$SEAT" && docker compose stop >/dev/null)
    trap 'echo "== Starting SeAT"; (cd "$SEAT" && docker compose up -d >/dev/null)' EXIT
fi

cd "$STACK"
echo "== Building"
"${C[@]}" build app
echo "== Starting"
"${C[@]}" up -d
echo "== Migrating"
"${C[@]}" exec -T app php artisan migrate --force
"${C[@]}" exec -T scheduler php artisan schedule:clear-cache

docker image prune -f >/dev/null
echo "== Done. Free disk: $(free_gb) GB"
