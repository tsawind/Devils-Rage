#!/usr/bin/env bash
# Devil's Rage: schedule backup.sh every night at 10:17 UTC (4:17 am Mountain).
# Safe to run more than once: it replaces its own line instead of adding another.
set -euo pipefail

SCRIPT="$(cd "$(dirname "$0")" && pwd)/backup.sh"
LOG="$HOME/backups/nightly.log"
LINE="17 10 * * * $SCRIPT >> $LOG 2>&1"

mkdir -p "$HOME/backups"
chmod +x "$SCRIPT"

( crontab -l 2>/dev/null | grep -v 'devilsrage/backup.sh' || true; echo "$LINE" ) | crontab -

echo "Nightly backup scheduled:"
crontab -l | grep 'devilsrage/backup.sh'
