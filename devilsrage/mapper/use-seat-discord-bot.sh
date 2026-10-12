#!/usr/bin/env bash
# Lets the mapper use the SAME Discord bot as SeAT ("The Devil's Rage SeAT").
# Copies the bot's application ID, client secret and bot token from SeAT's
# Connector settings into ~/wormholesystems-containers/.env. The values are
# never printed. A backup of the old .env is kept next to it.
# Run again any time the token is reset in SeAT.
set -euo pipefail

MAPPER_ENV="$HOME/wormholesystems-containers/.env"
[ -f "$MAPPER_ENV" ] || { echo "Mapper .env not found at $MAPPER_ENV"; exit 1; }

cd "$HOME/seat-docker"
RAW=$(docker compose exec -T front php artisan tinker --execute='$s = setting("seat-connector.drivers.discord", true); echo "DRV:", $s->client_id ?? "", "\nDRV:", $s->client_secret ?? "", "\nDRV:", $s->bot_token ?? "", "\n";' | grep '^DRV:' | sed 's/^DRV://')

APP_ID=$(sed -n 1p <<< "$RAW")
SECRET=$(sed -n 2p <<< "$RAW")
TOKEN=$(sed -n 3p <<< "$RAW")

if ! [[ "$APP_ID" =~ ^[0-9]+$ ]] || [ -z "$SECRET" ] || [ -z "$TOKEN" ]; then
  echo "Could not read the Discord settings from SeAT (Connector > Settings > Discord). Nothing changed."
  exit 1
fi

cp "$MAPPER_ENV" "$MAPPER_ENV.bak-discord"

set_var() {  # set_var KEY VALUE: drop any old line for KEY, then add the new one
  local tmp
  tmp=$(mktemp)
  grep -v "^$1=" "$MAPPER_ENV" > "$tmp" || true
  printf '%s=%s\n' "$1" "$2" >> "$tmp"
  cat "$tmp" > "$MAPPER_ENV"
  rm -f "$tmp"
}

set_var DISCORD_APPLICATION_ID "$APP_ID"
set_var DISCORD_CLIENT_ID "$APP_ID"
set_var DISCORD_CLIENT_SECRET "$SECRET"
set_var DISCORD_BOT_TOKEN "$TOKEN"

APP_URL=$(grep '^APP_URL=' "$MAPPER_ENV" | head -1 | cut -d= -f2- | tr -d '"' | sed 's:/*$::')

echo "Done: the mapper now uses SeAT's Discord bot (values hidden; old file saved as .env.bak-discord)."
echo
echo "Add this redirect in the Discord developer portal (your bot's app > OAuth2 > Redirects):"
echo "  ${APP_URL}/discord/callback"
