#!/usr/bin/env bash
# Devil's Rage: rebuild and restart SeAT with our plugins in one go.
#   bash ~/wormholesystems-containers/wormhole-systems/devilsrage/seat/deploy.sh
# SeAT runs its migrations by itself when it starts.
set -euo pipefail

REPO="$HOME/wormholesystems-containers/wormhole-systems"
MIN_FREE_GB=3

free_gb() { df --output=avail -BG / | tail -1 | tr -dc '0-9'; }

cd "$REPO" && git pull --ff-only
bash devilsrage/seat/setup-seat.sh

docker image prune -f >/dev/null
if [ "$(free_gb)" -lt "$MIN_FREE_GB" ]; then
    echo "!! Less than $MIN_FREE_GB GB free: not building. Try 'docker builder prune -af' first."
    exit 1
fi

cd "$HOME/seat-docker"
docker compose build
docker compose up -d
docker image prune -f >/dev/null
echo "== Done. Free disk: $(free_gb) GB. Give SeAT a minute to start."
