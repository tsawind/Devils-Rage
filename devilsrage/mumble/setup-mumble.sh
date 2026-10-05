#!/usr/bin/env bash
# Sets up the Mumble voice server in ~/mumble and builds the channel tree.
# Safe to run again: the SuperUser password in ~/mumble/.env is created once
# and never overwritten; the channel build only adds or updates.
set -euo pipefail

DIR="$HOME/mumble"
SRC="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$DIR"
cp "$SRC/docker-compose.yml" "$DIR/"
cd "$DIR"

if [ -f .env ]; then
  echo ".env already exists: SuperUser password left as it is."
else
  ( umask 077; echo "MUMBLE_SUPERUSER_PASSWORD=$(openssl rand -hex 20)" > .env )
  echo "Created .env with a random SuperUser password (private to this server)."
fi

echo "Starting Mumble..."
docker compose pull -q
docker compose up -d

echo "Waiting for it to answer..."
for i in $(seq 1 30); do
  if (exec 3<>/dev/tcp/127.0.0.1/64738) 2>/dev/null; then break; fi
  sleep 2
done
sleep 3

echo "Building channels and permissions (about a minute)..."
python3 "$SRC/build-channels.py" --env "$DIR/.env"

echo
docker compose ps
echo
echo "Pilots connect to: devilsrage.duckdns.org  port 64738"
