#!/usr/bin/env bash
# Prepares SeAT in ~/seat-docker next to the mapper. Does NOT start anything.
# Safe to run again: an existing .env is never overwritten (SeAT's database
# password must stay the same after the first start); only our two files are
# refreshed.
set -euo pipefail

DIR="$HOME/seat-docker"
SRC="$(cd "$(dirname "$0")" && pwd)"
DOMAIN="seatdevilsrage.duckdns.org"
KIT="https://github.com/eveseat/seat-docker/archive/refs/heads/master.tar.gz"

set_var() {  # set_var KEY VALUE  -> replace the line, or add it
  if grep -q "^$1=" .env; then
    sed -i "s|^$1=.*|$1=$2|" .env
  else
    echo "$1=$2" >> .env
  fi
}

mkdir -p "$DIR/packages"
cd "$DIR"

if [ ! -f docker-compose.yml ]; then
  echo "Downloading SeAT's official Docker kit..."
  curl -fsSL "$KIT" | tar xz --strip-components=1
fi

cp "$SRC/docker-compose.devilsrage.yml" "$SRC/mariadb-small.cnf" .
mkdir -p build
cp "$SRC/build/Dockerfile" "$SRC/build/ConnectorPolicyManagement.php" build/

if [ -f .env ]; then
  echo ".env already exists: left as it is."
else
  cp .env.example .env
  chmod 600 .env
  echo >> .env  # the example file has no final newline
  set_var SEAT_DOMAIN "$DOMAIN"
  set_var APP_KEY "$(openssl rand -hex 16)"
  set_var DB_PASSWORD "$(openssl rand -hex 24)"
  set_var QUEUE_BALANCING_MODE false
  set_var QUEUE_WORKERS 2
  set_var COMPOSE_PROJECT_NAME seat
  set_var COMPOSE_FILE "docker-compose.yml:docker-compose.mariadb.yml:docker-compose.devilsrage.yml"
  echo "Created .env (random app key and database password, private to this server)."
fi

echo "Checking the compose files..."
docker compose config --quiet && echo "Compose files OK."

echo
echo "SeAT is prepared in $DIR for https://$DOMAIN (nothing started)."
echo "Still needed before the first start: EVE_CLIENT_ID and EVE_CLIENT_SECRET in .env"
