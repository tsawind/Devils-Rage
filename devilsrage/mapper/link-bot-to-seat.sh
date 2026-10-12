#!/usr/bin/env bash
# Lets the mapper's Discord bot ask SeAT about a pilot's capitals, assets and fits
# (/caps, /assets, /fit, /canfly). Makes one random key and writes it to both sides:
#   ~/seat-docker/.env                  DR_BOT_API_KEY
#   ~/wormholesystems-containers/.env   SEAT_BOT_KEY (and SEAT_BOT_URL if no SEAT_URL is set)
# The key is never printed. Old files are kept as .env.bak-botkey. Safe to run again
# (it makes a new key; restart both stacks afterwards).
set -euo pipefail

SEAT_ENV="$HOME/seat-docker/.env"
MAPPER_ENV="$HOME/wormholesystems-containers/.env"
SEAT_SITE="https://seatdevilsrage.duckdns.org"

for f in "$SEAT_ENV" "$MAPPER_ENV"; do
  [ -f "$f" ] || { echo "Not found: $f"; exit 1; }
done

set_var() {  # set_var FILE KEY VALUE: drop any old line for KEY, then add the new one
  local tmp
  tmp=$(mktemp)
  grep -v "^$2=" "$1" > "$tmp" || true
  printf '%s=%s\n' "$2" "$3" >> "$tmp"
  cat "$tmp" > "$1"
  rm -f "$tmp"
}

KEY=$(openssl rand -hex 32)
cp "$SEAT_ENV" "$SEAT_ENV.bak-botkey"
cp "$MAPPER_ENV" "$MAPPER_ENV.bak-botkey"

set_var "$SEAT_ENV" DR_BOT_API_KEY "$KEY"
set_var "$MAPPER_ENV" SEAT_BOT_KEY "$KEY"
if ! grep -q '^SEAT_URL=.\+' "$MAPPER_ENV" && ! grep -q '^SEAT_BOT_URL=.\+' "$MAPPER_ENV"; then
  set_var "$MAPPER_ENV" SEAT_BOT_URL "$SEAT_SITE"
fi

echo "Done: SeAT and the mapper share a new bot key (hidden)."
echo "Restart both so they read it (commands in the deploy notes)."
