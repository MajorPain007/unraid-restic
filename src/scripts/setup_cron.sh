#!/bin/bash
# setup_cron.sh - Rebuild the plugin's .cron file on boot and hand it
# over to Unraid's update_cron so scheduled backups actually fire.
#
# Unraid's dcron does NOT pick up /etc/cron.d/ automatically. The correct
# flow is:
#   1. Drop entries (without user column) in
#      /boot/config/plugins/restic-backup/restic-backup.cron
#   2. Call /usr/local/sbin/update_cron — it concatenates every plugin's
#      *.cron into /var/spool/cron/crontabs/root and HUPs crond.
set -u

CONFIG="/boot/config/plugins/restic-backup/restic-backup.json"
BOOT_CRON="/boot/config/plugins/restic-backup/restic-backup.cron"
SCRIPT="/usr/local/emhttp/plugins/restic-backup/scripts/restic-backup.py"
QUEUE_LOCK="/tmp/restic-backup.queue"

# Start fresh every boot
rm -f "$BOOT_CRON"

if [ ! -f "$CONFIG" ]; then
    echo "Restic: no config found, cron not set."
    [ -x /usr/local/sbin/update_cron ] && /usr/local/sbin/update_cron
    exit 0
fi

python3 << PYEOF
import json, shlex, sys, os

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
        lines.append("{} /usr/bin/flock -w 21600 $QUEUE_LOCK {}".format(
            s["cron"], cmd))

if lines:
    os.makedirs(os.path.dirname("$BOOT_CRON"), exist_ok=True)
    with open("$BOOT_CRON", "w") as f:
        f.write("\n".join(lines) + "\n")
    os.chmod("$BOOT_CRON", 0o644)
    print("Restic: {} cron job(s) restored".format(len(lines)))
else:
    print("Restic: no scheduled jobs configured")
PYEOF

if [ -x /usr/local/sbin/update_cron ]; then
    /usr/local/sbin/update_cron && echo "Restic: update_cron applied."
else
    killall -HUP crond 2>/dev/null && echo "Restic: HUP'd crond directly."
fi

exit 0
