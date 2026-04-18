#!/bin/bash
# setup_cron.sh - Restore restic backup cron jobs from saved config on boot.
# Lines are wrapped in flock so jobs scheduled at the same minute run one
# after another instead of colliding on the restic-backup PID lock.
CONFIG="/boot/config/plugins/restic-backup/restic-backup.json"
CRON_FILE="/etc/cron.d/restic-backup"
SCRIPT="/usr/local/emhttp/plugins/restic-backup/scripts/restic-backup.py"
QUEUE_LOCK="/tmp/restic-backup.queue"

rm -f "$CRON_FILE"

if [ ! -f "$CONFIG" ]; then
    echo "Restic: no config found, cron not set."
    exit 0
fi

python3 << PYEOF
import json, shlex, sys

try:
    c = json.load(open("$CONFIG"))
except Exception as e:
    print("Restic: config parse error:", e)
    sys.exit(0)

lines = []
for job in c.get("jobs", []):
    s = job.get("schedule", {})
    if job.get("enabled", True) and s.get("enabled") and s.get("cron"):
        job_id = shlex.quote(str(job["id"]))
        cmd = "/usr/bin/python3 $SCRIPT --backup --job {}".format(job_id)
        lines.append("{} root /usr/bin/flock -w 21600 $QUEUE_LOCK {}".format(
            s["cron"], cmd))

if lines:
    with open("$CRON_FILE", "w") as f:
        f.write("\n".join(lines) + "\n")
    print("Restic: {} cron job(s) restored".format(len(lines)))
else:
    print("Restic: no scheduled jobs configured")
PYEOF
