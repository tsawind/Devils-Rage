#!/usr/bin/env bash
# Devil's Rage: the only thing the GitHub "Server" workflow's SSH key may run.
#
# The key's line in ~/.ssh/authorized_keys starts with
#   command="bash /home/ubuntu/wormholesystems-containers/wormhole-systems/devilsrage/remote/gate.sh",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty
# so whatever the workflow asks for arrives here as $SSH_ORIGINAL_COMMAND, and only the
# actions below run. No shell, no other commands. One action at a time.
#
# Its output ends up in the workflow log, which is public (the repo is public): nothing
# here may print secrets.
set -euo pipefail

STACK="$HOME/wormholesystems-containers"
REPO="$STACK/wormhole-systems"
C=(docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.discord.yml)
ACTION="${SSH_ORIGINAL_COMMAND:-status}"

exec 9>"$HOME/.devilsrage-gate.lock"
if ! flock -n 9; then
    echo "!! Another server action is still running. Try again when it's done."
    exit 1
fi

echo "== $(date -u '+%Y-%m-%d %H:%M UTC') · $ACTION"
case "$ACTION" in
    status)
        echo "== Mapper code on the server"
        git -C "$REPO" log -1 --format='%h %s (%cr)'
        echo "== Containers"
        docker ps --format 'table {{.Names}}\t{{.Status}}'
        echo "== Disk and memory"
        df -h / | tail -1
        free -h | head -2
        echo "== Nightly backups (names and sizes only)"
        crontab -l 2>/dev/null | grep -q 'devilsrage/backup.sh' && echo "scheduled: yes ($(crontab -l | grep 'devilsrage/backup.sh' | cut -d' ' -f1-5))" || echo "scheduled: NO"
        ls -lt --time-style=+%Y-%m-%dT%H:%M "$HOME/backups/nightly" 2>/dev/null | awk 'NR>1 && NR<=7 {print $6, $5, $7}' || echo "no backups folder"
        tail -n 2 "$HOME/backups/nightly.log" 2>/dev/null || true
        echo "== PHP versions (php2shell is fixed in 8.4.27 / 8.3.36 / 8.5.12 and newer)"
        echo "mapper: $(cd "$STACK" && "${C[@]}" exec -T app php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
        echo "SeAT:   $(cd "$HOME/seat-docker" && docker compose exec -T front php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
        ;;
    deploy-mapper)
        bash "$REPO/devilsrage/mapper/deploy.sh"
        ;;
    deploy-mapper-offline)
        bash "$REPO/devilsrage/mapper/deploy.sh" --offline
        ;;
    seat-errors|mapper-errors)
        # The newest errors' first lines only (no stack traces, no request data); anything
        # that looks like a key or token is masked, because this log is public.
        if [ "$ACTION" = seat-errors ]; then
            cd "$HOME/seat-docker"
            logs=$(docker compose exec -T front sh -c 'ls -t storage/logs/*.log 2>/dev/null | head -2 | xargs -r tail -n 3000' || true)
        else
            cd "$STACK"
            logs=$("${C[@]}" exec -T app sh -c 'ls -t storage/logs/*.log 2>/dev/null | head -2 | xargs -r tail -n 3000' || true)
        fi
        echo "== Newest errors (first line each)"
        printf '%s\n' "$logs" \
            | grep -E '^\[[0-9-]+ [0-9:]+\] [a-z]+\.(ERROR|CRITICAL|EMERGENCY)' \
            | grep -vE '/markets/[0-9]+/history|404 Not Found' \
            | tail -n 12 \
            | sed -E 's/[A-Za-z0-9_+\/=-]{32,}/[masked]/g; s/(password|secret|token|key)=[^ &"]*/\1=[masked]/gi' \
            | cut -c1-400 || echo "(none)"
        ;;
    seat-trace|mapper-trace)
        # The newest real errors (not ESI's [http …] noise) with a few lines of their stack trace,
        # from every log file of the last day; anything that looks like a key or token is masked.
        if [ "$ACTION" = seat-trace ]; then
            cd "$HOME/seat-docker"
            logs=$(docker compose exec -T front sh -c 'find storage/logs -name "*.log" -mtime -1 -print0 2>/dev/null | xargs -0 -r tail -q -n 4000' || true)
        else
            cd "$STACK"
            logs=$("${C[@]}" exec -T app sh -c 'find storage/logs -name "*.log" -mtime -1 -print0 2>/dev/null | xargs -0 -r tail -q -n 4000' || true)
        fi
        echo "== Newest errors with their trace"
        printf '%s\n' "$logs" \
            | awk '/^\[[0-9-]+ [0-9:]+\] / { keep = ($0 ~ /^\[[0-9-]+ [0-9:]+\] [a-z]+\.(ERROR|CRITICAL|EMERGENCY)/ && $0 !~ /\[http [0-9]+/); n = 0 } keep && n < 14 { print; n++ }' \
            | tail -n 45 \
            | sed -E 's/[A-Za-z0-9_+\/=-]{32,}/[masked]/g; s/(password|secret|token|key)=[^ &"]*/\1=[masked]/gi' \
            | cut -c1-300 || echo "(none)"
        ;;
    mapper-timing)
        # Patch 54: how long ESI location rounds take, when jumps were noticed (ESI or the app's
        # chat logs), and the queue's backlog. Character ids and seconds only.
        cd "$STACK"
        echo "== Containers"
        docker ps --format '{{.Names}}\t{{.Status}}' | grep -iE 'wormhole|app|queue|worker|scheduler|reverb' || true
        "${C[@]}" exec -T app php artisan tinker --execute="
            echo '== Queue: '.config('queue.default').(config('queue.default') === 'database' ? ', waiting jobs: '.DB::table('jobs')->count() : '').PHP_EOL;
            echo '== Location rounds (newest last)'.PHP_EOL.implode(PHP_EOL, array_slice(App\Support\LocationTiming::lines(App\Support\LocationTiming::ROUNDS), -15)).PHP_EOL;
            echo '== Jumps noticed (newest last)'.PHP_EOL.implode(PHP_EOL, array_slice(App\Support\LocationTiming::lines(App\Support\LocationTiming::MOVES), -25)).PHP_EOL;
        " 2>&1 | cut -c1-200
        ;;
    deploy-seat)
        bash "$REPO/devilsrage/seat/deploy.sh"
        ;;
    seat-sde)
        # Adds missing ships/items to SeAT's static data (character sheets 500 "Undefined array key").
        bash "$REPO/devilsrage/seat/sde-add-missing.sh" --apply
        ;;
    app-admins)
        echo "== APP_ADMIN_CHARACTER_IDS as the mapper sees it"
        cd "$STACK" && "${C[@]}" exec -T app php artisan tinker --execute="echo json_encode(config('access.app_admin_character_ids')), PHP_EOL;"
        echo "== Lines in .env (count): $(grep -c '^APP_ADMIN_CHARACTER_IDS=' "$STACK/.env" || true)"
        ;;
    restart-mapper)
        cd "$STACK" && "${C[@]}" up -d --force-recreate app scheduler
        ;;
    *)
        echo "!! Unknown action: $ACTION"
        echo "   Allowed: status, deploy-mapper, deploy-mapper-offline, deploy-seat, app-admins, restart-mapper, seat-errors, mapper-errors, mapper-timing, seat-trace, mapper-trace, seat-sde"
        exit 2
        ;;
esac
echo "== Done"
