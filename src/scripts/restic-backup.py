#!/usr/bin/python3
"""
Restic Backup Plugin for Unraid - Backend Script
Reads config from /boot/config/plugins/restic-backup/restic-backup.json
"""
import os
import sys
import json
import shutil
import subprocess
import datetime
import signal
import time
import argparse

# ==============================================================================
# CONSTANTS
# ==============================================================================

CONFIG_FILE = "/boot/config/plugins/restic-backup/restic-backup.json"
MOUNT_ROOT = "/tmp/restic-backup-root"
LOCK_FILE = "/tmp/restic-backup.lock"
PID_FILE = "/tmp/restic-backup.pid"
LOG_DIR = "/tmp"

# ==============================================================================
# LOGGER
# ==============================================================================

class Logger:
    def __init__(self):
        self.logfile = os.path.join(LOG_DIR, f"restic-backup-{datetime.datetime.now():%Y%m%d}.log")
        self.notifications_enabled = True

    def log(self, level, msg):
        timestamp = datetime.datetime.now().strftime("%H:%M:%S")
        formatted = f"[{timestamp}] [{level}] {msg}"
        print(formatted, flush=True)
        try:
            with open(self.logfile, "a") as f:
                f.write(formatted + "\n")
        except Exception:
            pass

    def info(self, msg):
        self.log("INFO", msg)

    def warn(self, msg):
        self.log("WARN", msg)

    def error(self, msg):
        self.log("ERROR", msg)

    def notify(self, title, msg, severity="normal"):
        if not self.notifications_enabled:
            return
        notify_script = "/usr/local/emhttp/webGui/scripts/notify"
        if os.path.exists(notify_script):
            subprocess.call([notify_script, "-s", title, "-d", msg, "-i", severity])

logger = Logger()

# ==============================================================================
# CONFIG
# ==============================================================================

def load_config():
    if not os.path.exists(CONFIG_FILE):
        logger.error(f"Config file not found: {CONFIG_FILE}")
        logger.error("Please configure the plugin via the Unraid web interface first.")
        sys.exit(1)
    with open(CONFIG_FILE, "r") as f:
        return json.load(f)

def setup_env(config):
    """Set up environment variables from config."""
    general = config.get("general", {})
    password_mode = general.get("password_mode", "file")

    if password_mode == "file":
        pw_file = general.get("password_file", "")
        if pw_file:
            os.environ["RESTIC_PASSWORD_FILE"] = pw_file
    elif password_mode == "inline":
        pw_inline = general.get("password_inline", "")
        if pw_inline:
            os.environ["RESTIC_PASSWORD"] = pw_inline

    logger.notifications_enabled = config.get("notifications", {}).get("enabled", True)

# ==============================================================================
# HELPERS
# ==============================================================================

def run_cmd(cmd_list, check=True, cwd=None, env=None, capture_output=False):
    if env is None:
        env = os.environ.copy()
    try:
        stdout_target = subprocess.PIPE if capture_output else subprocess.DEVNULL
        result = subprocess.run(
            cmd_list, check=check, cwd=cwd, env=env,
            stdout=stdout_target, stderr=subprocess.PIPE, text=True
        )
        if capture_output:
            return result.stdout
        return True
    except subprocess.CalledProcessError as e:
        return e.stderr

def format_duration(seconds):
    m, s = divmod(int(seconds), 60)
    return f"{m}m {s}s"

# ==============================================================================
# LOCK / PID
# ==============================================================================

def acquire_lock():
    if os.path.exists(LOCK_FILE):
        try:
            with open(LOCK_FILE, "r") as f:
                content = f.read().strip()
                if content:
                    pid = int(content)
                    os.kill(pid, 0)
                    logger.error(f"Backup already running (PID {pid}). Aborting.")
                    return False
        except (ProcessLookupError, ValueError):
            logger.warn("Found stale lock file. Removing...")
            try:
                os.remove(LOCK_FILE)
            except OSError:
                return False
        except Exception as e:
            logger.error(f"Error checking lock file: {e}")
            return False

    try:
        with open(LOCK_FILE, "w") as f:
            f.write(str(os.getpid()))
        with open(PID_FILE, "w") as f:
            f.write(str(os.getpid()))
        return True
    except Exception as e:
        logger.error(f"Could not create lock file: {e}")
        return False

def release_lock():
    for f in [LOCK_FILE, PID_FILE]:
        if os.path.exists(f):
            try:
                os.remove(f)
            except Exception:
                pass

# ==============================================================================
# ZFS SNAPSHOTS
# ==============================================================================

def create_zfs_snapshots(config):
    """Create ZFS snapshots if enabled. Returns list of (dataset, snap_name) created."""
    zfs_conf = config.get("zfs", {})
    if not zfs_conf.get("enabled", False):
        return []

    datasets = zfs_conf.get("datasets", [])
    recursive = zfs_conf.get("recursive", True)
    prefix = zfs_conf.get("snapshot_prefix", "restic-backup")
    snap_name = f"{prefix}-{datetime.datetime.now():%Y%m%d-%H%M%S}"

    created = []

    for parent_ds in datasets:
        if recursive:
            # Get all child datasets
            try:
                output = subprocess.check_output(
                    ["zfs", "list", "-H", "-r", "-o", "name", parent_ds],
                    text=True
                ).splitlines()
            except subprocess.CalledProcessError as e:
                logger.warn(f"Could not list datasets under {parent_ds}: {e}")
                continue

            for ds in output:
                ds = ds.strip()
                if not ds:
                    continue
                full_snap = f"{ds}@{snap_name}"
                result = run_cmd(["zfs", "snapshot", full_snap], check=False)
                if result is True:
                    created.append((ds, snap_name))
                    logger.info(f"Snapshot created: {full_snap}")
                else:
                    logger.warn(f"Could not snapshot {ds}: {result}")
        else:
            full_snap = f"{parent_ds}@{snap_name}"
            result = run_cmd(["zfs", "snapshot", full_snap], check=False)
            if result is True:
                created.append((parent_ds, snap_name))
                logger.info(f"Snapshot created: {full_snap}")
            else:
                logger.warn(f"Could not snapshot {parent_ds}: {result}")

    return created

def mount_zfs_snapshots(snapshots):
    """Bind-mount ZFS snapshots read-only into the backup root."""
    for ds, snap_name in snapshots:
        real_path = f"/mnt/{ds}/.zfs/snapshot/{snap_name}"
        target = os.path.join(MOUNT_ROOT, "zfs-snapshots", ds.replace("/", "_"))

        if not os.path.exists(real_path):
            logger.warn(f"Snapshot path does not exist: {real_path}")
            continue

        os.makedirs(target, exist_ok=True)
        result = run_cmd(["mount", "--bind", "-o", "ro", real_path, target], check=False)
        if result is not True:
            logger.warn(f"Could not mount {real_path}: {result}")

def destroy_zfs_snapshots(snapshots):
    """Clean up ZFS snapshots."""
    for ds, snap_name in snapshots:
        full_snap = f"{ds}@{snap_name}"
        run_cmd(["zfs", "destroy", full_snap], check=False)

# ==============================================================================
# BACKUP ENVIRONMENT
# ==============================================================================

def cleanup(snapshots=None):
    """Clean up mounts, snapshots, and lock files."""
    logger.info("Cleaning up...")

    if os.path.exists(MOUNT_ROOT):
        try:
            mounts = subprocess.getoutput("cat /proc/mounts").splitlines()
            active = [line.split()[1] for line in mounts if MOUNT_ROOT in line.split()[1]]
            for m in sorted(active, key=len, reverse=True):
                run_cmd(["umount", "-l", m], check=False)
        except Exception:
            pass
        try:
            shutil.rmtree(MOUNT_ROOT)
        except Exception:
            pass

    if snapshots:
        destroy_zfs_snapshots(snapshots)

    release_lock()

def prepare_env(config):
    """Prepare the backup mount structure from config."""
    logger.info("Preparing backup structure...")

    os.makedirs(MOUNT_ROOT, exist_ok=True)

    # Mount configured source directories
    sources = config.get("sources", [])
    for src in sources:
        if not src.get("enabled", True):
            continue

        path = src.get("path", "")
        label = src.get("label", "") or os.path.basename(path)

        if not path or not os.path.exists(path):
            logger.warn(f"Source path does not exist, skipping: {path}")
            continue

        target = os.path.join(MOUNT_ROOT, label)
        os.makedirs(target, exist_ok=True)

        if src.get("readonly_mount", True):
            result = run_cmd(["mount", "--bind", "-o", "ro", path, target], check=False)
        else:
            result = run_cmd(["mount", "--bind", path, target], check=False)

        if result is True:
            logger.info(f"Mounted: {path} -> {label}")
        else:
            logger.warn(f"Could not mount {path}: {result}")

    # ZFS Snapshots
    snapshots = create_zfs_snapshots(config)
    if snapshots:
        mount_zfs_snapshots(snapshots)

    return snapshots

# ==============================================================================
# BACKUP COMMAND
# ==============================================================================

def do_backup(config):
    """Run backup to all configured targets."""
    sys.stdout.reconfigure(line_buffering=True)
    t_start = time.time()

    logger.info("--- Starting Backup ---")

    if not acquire_lock():
        sys.exit(1)

    setup_env(config)
    general = config.get("general", {})
    targets = config.get("targets", [])
    excludes = config.get("excludes", {})

    max_retries = general.get("max_retries", 3)
    retry_wait = general.get("retry_wait", 30)
    check_enabled = general.get("check_enabled", False)
    check_pct = general.get("check_percentage", "2%")
    check_schedule = general.get("check_schedule", "sunday")

    hostname = general.get("hostname", "")
    tags = general.get("tags", "")

    success = 0
    fail = 0
    snapshots = []

    try:
        snapshots = prepare_env(config)

        for target in targets:
            if not target.get("enabled", True):
                continue

            url = target.get("url", "")
            name = target.get("name", url)

            if not url:
                logger.warn(f"Target has no URL, skipping: {name}")
                continue

            logger.info(f"Target: {name}")
            t_repo = time.time()

            # Build restic backup command
            cmd = ["restic", "-r", url, "backup", "."]

            if tags:
                cmd.extend(["--tag", tags])
            if hostname:
                cmd.extend(["--host", hostname])

            # Global excludes (always applied)
            for ex in excludes.get("global", []):
                if ex.strip():
                    cmd.append(f"--exclude={ex.strip()}")

            # Optional excludes (only if target opts in)
            if target.get("use_optional_excludes", False):
                logger.info("-> Applying optional excludes")
                for ex in excludes.get("optional", []):
                    if ex.strip():
                        cmd.append(f"--exclude={ex.strip()}")

            # Upload with retries
            logger.info("-> Uploading...")
            t_upload = time.time()
            upload_ok = False

            for attempt in range(1, max_retries + 1):
                result = run_cmd(cmd, cwd=MOUNT_ROOT, check=False)
                if result is True:
                    upload_ok = True
                    break
                elif attempt < max_retries:
                    logger.warn(f"Attempt {attempt}/{max_retries} failed. Retrying in {retry_wait}s...")
                    time.sleep(retry_wait)
                else:
                    logger.error(f"All {max_retries} attempts failed. Error: {result}")

            upload_duration = format_duration(time.time() - t_upload)

            if upload_ok:
                logger.info(f"Upload finished ({upload_duration})")

                # Prune
                logger.info("-> Pruning...")
                run_cmd(["restic", "-r", url, "forget",
                         "--keep-daily", "7", "--keep-weekly", "4", "--prune"], check=False)

                # Stats
                logger.info("-> Statistics...")
                stats = run_cmd(["restic", "-r", url, "stats", "latest",
                                 "--mode", "restore-size"], check=False, capture_output=True)
                if stats and isinstance(stats, str):
                    for line in stats.splitlines():
                        if "Total Size" in line or "Total File Count" in line:
                            logger.info(f"   {line.strip()}")

                # Integrity check
                should_check = False
                if check_enabled:
                    if check_schedule == "always":
                        should_check = True
                    elif check_schedule == "sunday" and datetime.datetime.now().weekday() == 6:
                        should_check = True
                    elif check_schedule == "monthly" and datetime.datetime.now().day == 1:
                        should_check = True

                if should_check:
                    logger.info(f"-> Integrity Check ({check_pct})...")
                    check_res = run_cmd(["restic", "-r", url, "check",
                                         f"--read-data-subset={check_pct}"], check=False)
                    if check_res is True:
                        logger.info("Integrity Check Passed")
                    else:
                        logger.warn("Integrity Check found issues!")
                        logger.notify("Restic Backup", f"Integrity Check on {name} found issues!", "warning")

                repo_duration = format_duration(time.time() - t_repo)
                logger.info(f"== Target {name} completed ({repo_duration}) ==")
                logger.notify("Restic Backup", f"{name} finished ({upload_duration})", "normal")
                success += 1
            else:
                logger.error(f"FAILED: {name}")
                logger.notify("Restic Backup", f"{name} failed! Check logs.", "alert")
                fail += 1

    except Exception as e:
        logger.error(f"CRITICAL: {e}")
        logger.notify("Restic Backup", f"Script aborted: {e}", "alert")
        fail += 1
    finally:
        cleanup(snapshots)

    total = format_duration(time.time() - t_start)

    if fail == 0 and success > 0:
        msg = f"All targets OK ({total})"
        logger.info(msg)
        logger.notify("Restic Backup", msg, "normal")
    elif success > 0:
        logger.notify("Restic Backup", f"Partial: {success} OK, {fail} failed", "warning")
    else:
        logger.notify("Restic Backup", "All targets FAILED!", "alert")

    logger.info("--- Done ---")
    return 0 if fail == 0 else 1

# ==============================================================================
# REPO MANAGEMENT
# ==============================================================================

def do_init(config, target_id):
    """Initialize a restic repository for a specific target."""
    setup_env(config)
    target = None
    for t in config.get("targets", []):
        if t.get("id") == target_id:
            target = t
            break

    if not target:
        print(json.dumps({"error": f"Target not found: {target_id}"}))
        return 1

    url = target.get("url", "")
    name = target.get("name", url)
    print(json.dumps({"status": "running", "message": f"Initializing repo: {name}"}))

    result = run_cmd(["restic", "-r", url, "init"], check=False, capture_output=True)
    if result and "created restic repository" in result.lower():
        print(json.dumps({"status": "success", "message": f"Repository initialized: {name}"}))
        return 0

    # Check if already initialized
    if isinstance(result, str) and "already" in result.lower():
        print(json.dumps({"status": "success", "message": f"Repository already exists: {name}"}))
        return 0

    print(json.dumps({"status": "error", "message": str(result)}))
    return 1

def do_test(config, target_id):
    """Test connection to a target repository."""
    setup_env(config)
    target = None
    for t in config.get("targets", []):
        if t.get("id") == target_id:
            target = t
            break

    if not target:
        print(json.dumps({"error": f"Target not found: {target_id}"}))
        return 1

    url = target.get("url", "")
    name = target.get("name", url)

    result = run_cmd(["restic", "-r", url, "snapshots", "--latest", "1"], check=False, capture_output=True)
    if result is True or (isinstance(result, str) and "ID" in result):
        print(json.dumps({"status": "success", "message": f"Connection OK: {name}"}))
        return 0

    print(json.dumps({"status": "error", "message": str(result)}))
    return 1

def do_snapshots(config, target_id):
    """List snapshots for a target."""
    setup_env(config)
    target = None
    for t in config.get("targets", []):
        if t.get("id") == target_id:
            target = t
            break

    if not target:
        print(json.dumps({"error": f"Target not found: {target_id}"}))
        return 1

    url = target.get("url", "")
    result = run_cmd(["restic", "-r", url, "snapshots", "--json"], check=False, capture_output=True)
    if isinstance(result, str):
        print(result)
        return 0

    print(json.dumps({"status": "error", "message": str(result)}))
    return 1

def do_status():
    """Output current status as JSON."""
    running = os.path.exists(LOCK_FILE)
    pid = 0
    if running:
        try:
            with open(LOCK_FILE, "r") as f:
                pid = int(f.read().strip())
            os.kill(pid, 0)
        except (ProcessLookupError, ValueError, FileNotFoundError):
            running = False
            pid = 0

    print(json.dumps({
        "running": running,
        "pid": pid,
    }))
    return 0

# ==============================================================================
# MAIN
# ==============================================================================

def main():
    parser = argparse.ArgumentParser(description="Restic Backup Plugin Backend")
    parser.add_argument("--backup", action="store_true", help="Run full backup")
    parser.add_argument("--init", metavar="TARGET_ID", help="Initialize a repository")
    parser.add_argument("--test", metavar="TARGET_ID", help="Test connection to a repository")
    parser.add_argument("--snapshots", metavar="TARGET_ID", help="List snapshots for a repository")
    parser.add_argument("--status", action="store_true", help="Show current status (JSON)")
    args = parser.parse_args()

    if args.status:
        return do_status()

    config = load_config()

    if args.backup:
        return do_backup(config)
    elif args.init:
        return do_init(config, args.init)
    elif args.test:
        return do_test(config, args.test)
    elif args.snapshots:
        return do_snapshots(config, args.snapshots)
    else:
        parser.print_help()
        return 0

if __name__ == "__main__":
    def signal_handler(sig, frame):
        print("\nAborting...")
        cleanup()
        sys.exit(0)
    signal.signal(signal.SIGINT, signal_handler)
    sys.exit(main())
