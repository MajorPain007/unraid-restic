#!/bin/bash
# setup_cron.sh - Restore restic backup cron jobs from saved config on boot
CONFIG="/boot/config/plugins/restic-backup/restic-backup.json"
CRON_FILE="/etc/cron.d/restic-backup"
SCRIPT="/usr/local/emhttp/plugins/restic-backup/scripts/restic-backup.py"

rm -f "$CRON_FILE"

if [ ! -f "$CONFIG" ]; then
    echo "Restic: no config found, cron not set."
    exit 0
fi

python3 << PYEOF
import json, sys

try:
    c = json.load(open("$CONFIG"))
except Exception as e:
    print("Restic: config parse error:", e)
    sys.exit(0)

lines = []
for job in c.get("jobs", []):
    s = job.get("schedule", {})
    if job.get("enabled", True) and s.get("enabled") and s.get("cron"):
        lines.append("{} root /usr/bin/python3 $SCRIPT --backup --job {}".format(
            s["cron"], job["id"]))

if lines:
    with open("$CRON_FILE", "w") as f:
        f.write("\n".join(lines) + "\n")
    print("Restic: {} cron job(s) restored".format(len(lines)))
else:
    print("Restic: no scheduled jobs configured")
PYEOF
