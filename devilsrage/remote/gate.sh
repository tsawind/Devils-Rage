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
        # The real errors of the last 6 hours (not ESI's [http …] or market-history noise) with a
        # few lines of their trace, oldest first, plus PHP / web server errors from the container
        # output. Anything that looks like a key or token is masked.
        since=$(date -u -d '-6 hours' '+%Y-%m-%d %H:%M:%S')
        if [ "$ACTION" = seat-trace ]; then
            cd "$HOME/seat-docker"
            logs=$(docker compose exec -T front sh -c 'find storage/logs -name "*.log" -mtime -1 -print0 2>/dev/null | xargs -0 -r cat' || true)
            raw=$(docker compose logs --no-color --since 6h front 2>&1 || true)
        else
            cd "$STACK"
            logs=$("${C[@]}" exec -T app sh -c 'find storage/logs -name "*.log" -mtime -1 -print0 2>/dev/null | xargs -0 -r cat' || true)
            raw=$("${C[@]}" logs --no-color --since 6h app 2>&1 || true)
        fi
        mask() { sed -E 's/[A-Za-z0-9_+\/=-]{32,}/[masked]/g; s/(password|secret|token|key)=[^ &"]*/\1=[masked]/gi' | cut -c1-300; }
        echo "== Errors since $since UTC (oldest first)"
        printf '%s\n' "$logs" \
            | awk -v since="$since" '/^\[[0-9-]+ [0-9:]+\] / { ts = substr($0, 2, 19); keep = (ts >= since && $0 ~ /\.(ERROR|CRITICAL|EMERGENCY)/ && $0 !~ /\[http [0-9]+|not found on market|RedisException|Connection refused/); if (keep) { print "@@" ts } n = 0 } keep && n < 40 { print; n++ }' \
            | awk '/^@@/ { ts = substr($0, 3); next } { print ts "\t" $0 }' | sort -s -t "$(printf '\t')" -k1,1 | cut -f2- \
            | tail -n 120 | mask || echo "(none)"
        echo "== PHP / web server errors in the container output"
        printf '%s\n' "$raw" | grep -iE 'php (fatal|warning|parse)|fatal error|exception|\] \[(php|proxy_fcgi):error\]|AH0[0-9]+' | tail -n 15 | mask || echo "(none)"
        ;;
    seat-srp-check)
        # Which SRP code SeAT really runs: its version and every place it calls ESI. Code only.
        cd "$HOME/seat-docker"
        docker compose exec -T front sh -c '
            echo "== seat-srp version"; composer show cryptatech/seat-srp 2>/dev/null | grep -E "^(versions|source)" || true
            echo "== Files defining srpPopulateSlots"; grep -rln "function srpPopulateSlots" vendor 2>/dev/null
            echo "== ESI calls in the SRP plugin"; grep -rn "invoke(\|Eseye\|EsiClient\|killmails/" vendor/cryptatech/seat-srp/src --include=*.php 2>/dev/null | cut -c1-220
            f=$(grep -rln "function srpPopulateSlots" vendor 2>/dev/null | head -1)
        ' 2>&1 | cut -c1-240
        echo "== Pricing our losses of the last 3 days (dry run, nothing filed)"
        docker compose exec -T front php artisan tinker --execute='
            echo "SRP mode advanced=", json_encode(setting("cryptatech_seat_srp_advanced_srp", true)), " simple_source=", json_encode(setting("cryptatech_seat_srp_simple_source", true)), " advanced_default_source=", json_encode(setting("cryptatech_seat_srp_advrule_def_source", true)), PHP_EOL;
            echo "Price providers: ", json_encode(RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance::query()->get(["id", "name", "backend"])), PHP_EOL;
            echo "Rules: ", Seat\Eveapi\Models\Killmails\Killmail::query()->getConnection()->table("cryptatech_seat_srp_advrule")->count(), PHP_EOL;
            $ids = Seat\Eveapi\Models\Killmails\KillmailDetail::query()->where("killmail_time", ">=", now()->subDays(3))->pluck("killmail_id");
            $ours = Seat\Eveapi\Models\RefreshToken::query()->pluck("character_id")->all();
            $p = new class { use CryptaTech\Seat\SeatSrp\Helpers\SrpManager; public function run($k) { return $this->srpPopulateSlots($k); } };
            foreach (Seat\Eveapi\Models\Killmails\KillmailVictim::query()->whereIn("killmail_id", $ids)->whereIn("character_id", $ours)->limit(12)->get() as $v) {
                $k = Seat\Eveapi\Models\Killmails\Killmail::query()->find($v->killmail_id);
                $line = $v->killmail_id." hash=".strlen((string) $k?->killmail_hash)." chars ";
                try { $s = $p->run($k); $line .= "ok ".($s["shipType"] ?? "?")." ".json_encode($s["price"]["error"] ?? null); }
                catch (Throwable $e) { $line .= "FAILS ".get_class($e).": ".$e->getMessage()." @ ".basename($e->getFile()).":".$e->getLine()." | ".implode(" < ", array_map(fn ($f) => basename($f["file"] ?? "?").":".($f["line"] ?? "?"), array_slice($e->getTrace(), 0, 8))); }
                echo $line, PHP_EOL;
            }
        ' 2>&1 | cut -c1-400
        ;;
    backup-check)
        # Is the nightly backup scheduled, did it run, and does the mapper know? File names and sizes only.
        echo "== Cron line"; crontab -l 2>/dev/null | grep 'devilsrage/backup.sh' || echo "(none: backup is not scheduled)"
        echo "== Last lines of nightly.log"; tail -n 15 "$HOME/backups/nightly.log" 2>/dev/null | cut -c1-200 || echo "(no log)"
        echo "== Newest backup files"; ls -lt "$HOME/backups/nightly" 2>/dev/null | head -10 || echo "(no folder)"
        echo "== What the mapper has recorded"
        cd "$STACK" && "${C[@]}" exec -T app php artisan tinker --execute="echo App\Actions\Discord\ShowHealthAction::lastBackup()?->toIso8601String() ?? 'never', PHP_EOL;" 2>&1 | cut -c1-200
        echo "== The report call backup.sh makes (output shown this time)"
        ( cd "$STACK" && docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan devilsrage:backup-done ) 2>&1 | tail -n 8 | cut -c1-200 || true
        ;;
    seat-failed-jobs)
        # SeAT's failed jobs of the last day, grouped by job and the first line of the error.
        cd "$HOME/seat-docker"
        docker compose exec -T front php artisan tinker --execute='
            $rows = DB::table("failed_jobs")->where("failed_at", ">=", now()->subDay())->get(["payload", "exception"]);
            echo "Failed in the last day: ", $rows->count(), PHP_EOL;
            $rows->map(fn ($r) => (json_decode($r->payload, true)["displayName"] ?? "?")." | ".mb_strimwidth(strtok((string) $r->exception, "\n"), 0, 170))
                ->countBy()->sortDesc()->take(25)->each(function ($n, $k) { echo str_pad((string) $n, 4), " ", $k, PHP_EOL; });
            $kills = $rows->filter(fn ($r) => str_contains((string) $r->exception, "Invalid killmail_id"))
                ->map(fn ($r) => preg_match("/killmail_id\\\\?\";i:(\\d+)/", (string) $r->payload, $m) ? (int) $m[1] : 0)->countBy()->sortDesc();
            echo "Bad-hash killmails: ", $kills->count(), " distinct", PHP_EOL;
            foreach ($kills->take(8) as $id => $n) {
                $k = Seat\Eveapi\Models\Killmails\Killmail::query()->find($id);
                $hasDetail = Seat\Eveapi\Models\Killmails\KillmailDetail::query()->find($id) !== null;
                echo "  ", $id, " x", $n, " still-in-seat=", $k ? "yes" : "no", " stored-hash=", strlen((string) $k?->killmail_hash), " chars detail=", $hasDetail ? "yes" : "no", " created=", $k?->created_at, PHP_EOL;
            }
        ' 2>&1 | sed -E 's/[A-Za-z0-9_+\/=-]{32,}/[masked]/g' | cut -c1-260
        ;;
    mapper-migrations)
        # The newest migrations and whether they ran.
        cd "$STACK" && "${C[@]}" exec -T app php artisan migrate:status 2>&1 | tail -n 6 | cut -c1-160
        ;;
    deploy-cameras)
        # The MediaMTX video relay for cameras (its own folder, like SeAT). Run deploy-mapper after
        # the first run so the mapper picks up the camera pass secret.
        bash "$REPO/devilsrage/cameras/setup-cameras.sh"
        ;;
    capacity)
        # Cameras plan: what the VPS has for a video relay. No addresses or secrets (public log).
        echo "== CPU"
        echo "cores: $(nproc) · $(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2- | sed 's/^ *//')"
        echo "load (1/5/15 min): $(cut -d' ' -f1-3 /proc/loadavg)"
        echo "== Memory and disk"
        free -h | head -2
        df -h / | tail -1
        echo "== Network link"
        for nic in /sys/class/net/*; do
            name=$(basename "$nic"); [ "$name" = lo ] && continue
            case "$name" in docker*|br-*|veth*) continue;; esac
            echo "$name: $(cat "$nic/speed" 2>/dev/null || echo '?') Mb/s link"
        done
        if command -v vnstat >/dev/null 2>&1; then
            echo "== Traffic this month (vnstat)"; vnstat -m 2>/dev/null | tail -n 5
        else
            echo "== Traffic: vnstat not installed (monthly totals unknown)"
        fi
        echo "== Firewall"
        (sudo -n ufw status 2>/dev/null | head -n 1) || echo "ufw: not readable without a password"
        echo "== Busiest containers right now"
        docker stats --no-stream --format '{{.Name}}	{{.CPUPerc}}	{{.MemUsage}}' 2>/dev/null | sort -k2 -t$'\t' -rh | head -n 8
        ;;
    update-gate)
        # Only fetches the newest scripts (this file included): no build, nothing restarts.
        cd "$REPO" && git pull --ff-only && git log --oneline -1
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
        echo "   Allowed: status, capacity, deploy-cameras, deploy-mapper, deploy-mapper-offline, deploy-seat, app-admins, restart-mapper, seat-errors, mapper-errors, mapper-timing, seat-trace, mapper-trace, update-gate, seat-sde, seat-srp-check, backup-check, seat-failed-jobs, mapper-migrations"
        exit 2
        ;;
esac
echo "== Done"
