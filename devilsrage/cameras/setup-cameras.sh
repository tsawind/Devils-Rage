#!/usr/bin/env bash
# Devil's Rage cameras: install or update the video relay (MediaMTX) on the server.
#   bash ~/wormholesystems-containers/wormhole-systems/devilsrage/cameras/setup-cameras.sh
# (or the Server workflow's "deploy-cameras" action, which runs exactly this).
#
# - Copies the compose file and settings to ~/devilsrage-cameras.
# - Writes ~/devilsrage-cameras/.env (mapper address for the "may they?" check, public name).
# - First run only: makes the secret the mapper signs camera passes with (mapper's .env).
# - Starts or updates the relay and says whether its port is open.
# Prints no secrets (the workflow log is public).
set -euo pipefail

DIR="$HOME/devilsrage-cameras"
STACK="$HOME/wormholesystems-containers"
SRC="$STACK/wormhole-systems/devilsrage/cameras"
MAPPER_ENV="$STACK/.env"

mkdir -p "$DIR"
before=$(sha256sum "$DIR/mediamtx.yml" 2>/dev/null | cut -d' ' -f1 || true)
cp -p "$SRC/docker-compose.yml" "$SRC/mediamtx.yml" "$DIR/"
after=$(sha256sum "$DIR/mediamtx.yml" | cut -d' ' -f1)

domain=$(grep -E '^APP_URL=' "$MAPPER_ENV" | head -1 | cut -d= -f2- | tr -d '"' | sed -E 's#^https?://##; s#/.*$##')
if [ -z "$domain" ]; then
    echo "!! Couldn't read APP_URL from the mapper's .env. Stopping."
    exit 1
fi

umask 077
{
    echo "CAMERAS_DOMAIN=$domain"
    echo "MTX_AUTHHTTPADDRESS=https://$domain/api/cameras/relay-auth"
    echo "MTX_WEBRTCADDITIONALHOSTS=$domain"
} > "$DIR/.env"

if ! grep -q '^CAMERAS_SECRET=' "$MAPPER_ENV"; then
    echo "CAMERAS_SECRET=$(openssl rand -hex 32)" >> "$MAPPER_ENV"
    echo "== Made the camera pass secret (mapper's .env). The mapper uses it after its next deploy (deploy-mapper)."
fi
sed -i '/^CAMERAS_RTC_URL=/d' "$MAPPER_ENV"
echo "CAMERAS_RTC_URL=https://$domain/rtc" >> "$MAPPER_ENV"

if [ ! -s /etc/ssl/certs/ca-certificates.crt ]; then
    echo "!! /etc/ssl/certs/ca-certificates.crt is missing: the relay can't check the mapper's certificate (sudo apt install ca-certificates)."
fi

cd "$DIR"
docker compose pull -q
docker compose up -d
# The settings file is mounted, so a change to it alone doesn't restart the relay: do it here
# (live cameras drop for a few seconds and reconnect by themselves).
started=$(docker inspect -f '{{.State.StartedAt}}' "$(docker compose ps -q mediamtx)" 2>/dev/null | cut -c1-19 | tr 'T' ' ')
changed_since_start=0
if [ -n "$started" ] && [ "$(date -u -r "$DIR/mediamtx.yml" '+%F %T')" \> "$started" ]; then changed_since_start=1; fi
if { [ -n "$before" ] && [ "$before" != "$after" ]; } || [ "$changed_since_start" = 1 ]; then
    echo "== Relay settings changed: restarting the relay"
    docker compose restart mediamtx >/dev/null
fi
sleep 3
echo "== Relay"
docker compose ps --format 'table {{.Name}}\t{{.Status}}'

echo "== Firewall (video needs port 8189, UDP and TCP, open to everyone)"
if sudo -n true 2>/dev/null && command -v ufw >/dev/null 2>&1; then
    if sudo -n ufw status | grep -q '^Status: active'; then
        sudo -n ufw allow 8189/udp >/dev/null && sudo -n ufw allow 8189/tcp >/dev/null
        echo "ufw: 8189/udp and 8189/tcp allowed"
    else
        echo "ufw: not active (nothing to change here)"
    fi
else
    echo "Can't change the server firewall without a password. On the server run:"
    echo "   sudo ufw allow 8189/udp && sudo ufw allow 8189/tcp"
fi
echo "If the VPS provider has its own firewall / security list in its web panel, open 8189 UDP and TCP there too."
