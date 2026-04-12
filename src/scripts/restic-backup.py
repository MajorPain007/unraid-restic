#!/usr/bin/python3
"""
Restic Backup Plugin for Unraid - Backend Script
Reads config from /boot/config/plugins/restic-backup/restic-backup.json
Supports multiple independent backup jobs.
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
        try:
            with open(self.logfile, "a") as f:
                f.write(formatted + "\n")
        except Exception:
            pass

    def info(self, msg):  self.log("INFO", msg)
    def warn(self, msg):  self.log("WARN", msg)
    def error(self, msg): self.log("ERROR", msg)

    def notify(self, title, msg, severity="normal"):
        if not self.notifications_enabled:
            return
        script = "/usr/local/emhttp/webGui/scripts/notify"
        if os.path.exists(script):
            subprocess.call([script, "-s", title, "-d", msg, "-i", severity])

logger = Logger()

# ==============================================================================
# CONFIG
# ==============================================================================

def load_config():
    if not os.path.exists(CONFIG_FILE):
        logger.error(f"Config not found: {CONFIG_FILE}")
        logger.error("Please configure the plugin via the Unraid web interface.")
        sys.exit(1)
    with open(CONFIG_FILE, "r") as f:
        return json.load(f)

def setup_password(config):
    general = config.get("general", {})
    mode = general.get("password_mode", "file")
    if mode == "file":
        pw_file = general.get("password_file", "")
        if pw_file:
            os.environ["RESTIC_PASSWORD_FILE"] = pw_file
    elif mode == "inline":
        pw = general.get("password_inline", "")
        if pw:
            os.environ["RESTIC_PASSWORD"] = pw
    logger.notifications_enabled = general.get("notifications", True)

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
        return e.stderr or f"Exit code {e.returncode}"

def fmt(seconds):
    m, s = divmod(int(seconds), 60)
    return f"{m}m {s}s"

# ==============================================================================
# LOCK / PID
# ==============================================================================

def acquire_lock():
    if os.path.exists(LOCK_FILE):
        try:
            with open(LOCK_FILE, "r") as f:
                pid = int(f.read().strip())
            os.kill(pid, 0)
            logger.error(f"Backup already running (PID {pid}).")
            return False
        except (ProcessLookupError, ValueError):
            logger.warn("Stale lock file found. Removing.")
            try: os.remove(LOCK_FILE)
            except OSError: return False
        except Exception as e:
            logger.error(f"Lock check error: {e}")
            return False

    try:
        with open(LOCK_FILE, "w") as f:
            f.write(str(os.getpid()))
        with open(PID_FILE, "w") as f:
            f.write(str(os.getpid()))
        return True
    except Exception as e:
        logger.error(f"Could not create lock: {e}")
        return False

def release_lock():
    for f in [LOCK_FILE, PID_FILE]:
        if os.path.exists(f):
            try: os.remove(f)
            except: pass

# ==============================================================================
# TARGET CREDENTIALS
# ==============================================================================

def build_target_env(target):
    """Return os.environ copy with credentials injected for the target type."""
    env = os.environ.copy()
    t = target.get("type", "local")
    creds = target.get("credentials", {})
    if t == "s3":
        if creds.get("aws_access_key_id"):
            env["AWS_ACCESS_KEY_ID"] = creds["aws_access_key_id"]
        if creds.get("aws_secret_access_key"):
            env["AWS_SECRET_ACCESS_KEY"] = creds["aws_secret_access_key"]
        if creds.get("aws_region"):
            env["AWS_DEFAULT_REGION"] = creds["aws_region"]
    elif t == "b2":
        if creds.get("b2_account_id"):
            env["B2_ACCOUNT_ID"] = creds["b2_account_id"]
        if creds.get("b2_account_key"):
            env["B2_ACCOUNT_KEY"] = creds["b2_account_key"]
    return env

def get_sftp_opts(target):
    """Return extra restic CLI args for SFTP host key handling."""
    if target.get("type") != "sftp":
        return []
    if target.get("credentials", {}).get("sftp_accept_hostkey", True):
        return ["-o", "sftp.args=-o StrictHostKeyChecking=accept-new"]
    return []

def get_target_url(target):
    """Return the restic repository URL, injecting REST credentials if set."""
    url = target.get("url", "")
    if target.get("type") == "rest":
        creds = target.get("credentials", {})
        user = creds.get("rest_user", "")
        passwd = creds.get("rest_pass", "")
        if user and passwd and url.startswith("rest:"):
            from urllib.parse import urlparse, urlunparse, quote
            inner = url[5:]
            p = urlparse(inner)
            netloc = "{}:{}@{}".format(quote(user, safe=""), quote(passwd, safe=""), p.hostname)
            if p.port:
                netloc += ":{}".format(p.port)
            url = "rest:" + urlunparse((p.scheme, netloc, p.path, p.params, p.query, p.fragment))
    return url

# ==============================================================================
# ZFS
# ==============================================================================

def create_zfs_snapshots(zfs_conf):
    if not zfs_conf.get("enabled", False):
        return []

    datasets = zfs_conf.get("datasets", [])
    recursive = zfs_conf.get("recursive", True)
    prefix = zfs_conf.get("snapshot_prefix", "restic-backup")
    snap_name = f"{prefix}-{datetime.datetime.now():%Y%m%d-%H%M%S}"
    created = []

    for parent_ds in datasets:
        if recursive:
            try:
                output = subprocess.check_output(
                    ["zfs", "list", "-H", "-r", "-o", "name", parent_ds], text=True
                ).splitlines()
            except subprocess.CalledProcessError as e:
                logger.warn(f"Cannot list datasets under {parent_ds}: {e}")
                continue
            for ds in output:
                ds = ds.strip()
                if not ds: continue
                result = run_cmd(["zfs", "snapshot", f"{ds}@{snap_name}"], check=False)
                if result is True:
                    created.append((ds, snap_name))
                    logger.info(f"Snapshot: {ds}@{snap_name}")
                else:
                    logger.warn(f"Snapshot failed {ds}: {result}")
        else:
            result = run_cmd(["zfs", "snapshot", f"{parent_ds}@{snap_name}"], check=False)
            if result is True:
                created.append((parent_ds, snap_name))
                logger.info(f"Snapshot: {parent_ds}@{snap_name}")
            else:
                logger.warn(f"Snapshot failed {parent_ds}: {result}")

    return created

def mount_zfs_snapshots(snapshots, mount_root):
    for ds, snap_name in snapshots:
        real_path = f"/mnt/{ds}/.zfs/snapshot/{snap_name}"
        # Strip pool name (first segment) so the backup path mirrors the dataset
        # hierarchy without the pool prefix.
        # e.g. cache/appdata/crowdsec-agent → appdata/crowdsec-agent
        parts = ds.split("/")
        rel_path = "/".join(parts[1:]) if len(parts) > 1 else parts[0]
        target = os.path.join(mount_root, rel_path)
        if not os.path.exists(real_path):
            logger.warn(f"Snapshot path missing: {real_path}")
            continue
        os.makedirs(target, exist_ok=True)
        result = run_cmd(["mount", "--bind", "-o", "ro", real_path, target], check=False)
        if result is not True:
            logger.warn(f"Mount failed {real_path}: {result}")

def destroy_zfs_snapshots(snapshots):
    for ds, snap_name in snapshots:
        run_cmd(["zfs", "destroy", f"{ds}@{snap_name}"], check=False)

# ==============================================================================
# BACKUP ENVIRONMENT
# ==============================================================================

def cleanup(snapshots=None):
    logger.info("Cleaning up...")
    if os.path.exists(MOUNT_ROOT):
        try:
            mounts = subprocess.getoutput("cat /proc/mounts").splitlines()
            active = [l.split()[1] for l in mounts if MOUNT_ROOT in l.split()[1]]
            for m in sorted(active, key=len, reverse=True):
                run_cmd(["umount", "-l", m], check=False)
        except: pass
        try: shutil.rmtree(MOUNT_ROOT)
        except: pass
    if snapshots:
        destroy_zfs_snapshots(snapshots)
    release_lock()

def prepare_job_env(job):
    job_root = os.path.join(MOUNT_ROOT, job.get("id", "default"))
    os.makedirs(job_root, exist_ok=True)

    # Mount sources
    for src in job.get("sources", []):
        if not src.get("enabled", True):
            continue
        path = src.get("path", "")
        label = src.get("label", "") or os.path.basename(path)
        if not path or not os.path.exists(path):
            logger.warn(f"Source missing: {path}")
            continue
        target = os.path.join(job_root, label)
        os.makedirs(target, exist_ok=True)
        result = run_cmd(["mount", "--bind", "-o", "ro", path, target], check=False)
        if result is True:
            logger.info(f"Source: {path} -> {label}")
        else:
            logger.warn(f"Mount failed {path}: {result}")

    # ZFS Snapshots
    snapshots = create_zfs_snapshots(job.get("zfs", {}))
    if snapshots:
        mount_zfs_snapshots(snapshots, job_root)

    return job_root, snapshots

# ==============================================================================
# RUN JOB
# ==============================================================================

def run_job(config, job):
    job_name = job.get("name", "Unnamed")
    logger.info(f"=== Job: {job_name} ===")
    t_job = time.time()

    targets = job.get("targets", [])
    excludes = job.get("excludes", {})
    retention = job.get("retention", {})
    check_conf = job.get("check", {})
    max_retries = job.get("max_retries", 3)
    retry_wait = job.get("retry_wait", 30)
    hostname = config.get("general", {}).get("hostname", "")
    tags = job.get("tags", "")

    job_root, snapshots = prepare_job_env(job)
    success = 0
    fail = 0

    try:
        for target in targets:
            if not target.get("enabled", True):
                continue
            url = get_target_url(target)
            target_env = build_target_env(target)
            name = target.get("name", url)
            if not url:
                logger.warn(f"Target has no URL: {name}")
                continue

            logger.info(f"Target: {name}")
            t_repo = time.time()

            sftp_opts = get_sftp_opts(target)
            cmd = ["restic", "-r", url] + sftp_opts + ["backup", "."]
            if tags:
                cmd.extend(["--tag", tags])
            if hostname:
                cmd.extend(["--host", hostname])

            for ex in excludes.get("global", []):
                ex = ex.strip()
                if ex: cmd.append(f"--exclude={ex}")

            if target.get("use_optional_excludes", False):
                logger.info("  Applying optional excludes")
                for ex in excludes.get("optional", []):
                    ex = ex.strip()
                    if ex: cmd.append(f"--exclude={ex}")

            # Upload
            logger.info("  Uploading...")
            t_up = time.time()
            ok = False
            for attempt in range(1, max_retries + 1):
                result = run_cmd(cmd, cwd=job_root, check=False, env=target_env)
                if result is True:
                    ok = True
                    break
                elif attempt < max_retries:
                    logger.warn(f"  Attempt {attempt}/{max_retries} failed, retry in {retry_wait}s...")
                    time.sleep(retry_wait)
                else:
                    logger.error(f"  All attempts failed: {result}")

            up_dur = fmt(time.time() - t_up)

            if ok:
                logger.info(f"  Upload done ({up_dur})")

                # Retention / Prune
                prune_cmd = ["restic", "-r", url] + sftp_opts + ["forget", "--prune"]
                kd = retention.get("keep_daily", 7)
                kw = retention.get("keep_weekly", 4)
                km = retention.get("keep_monthly", 0)
                ky = retention.get("keep_yearly", 0)
                if kd: prune_cmd.extend(["--keep-daily", str(kd)])
                if kw: prune_cmd.extend(["--keep-weekly", str(kw)])
                if km: prune_cmd.extend(["--keep-monthly", str(km)])
                if ky: prune_cmd.extend(["--keep-yearly", str(ky)])

                logger.info("  Pruning...")
                run_cmd(prune_cmd, check=False, env=target_env)

                # Stats
                stats = run_cmd(["restic", "-r", url] + sftp_opts + ["stats", "latest",
                                 "--mode", "restore-size"], check=False, capture_output=True, env=target_env)
                if stats and isinstance(stats, str):
                    for line in stats.splitlines():
                        if "Total Size" in line or "Total File Count" in line:
                            logger.info(f"  {line.strip()}")

                # Integrity check
                should_check = False
                if check_conf.get("enabled", False):
                    sched = check_conf.get("schedule", "sunday")
                    if sched == "always":
                        should_check = True
                    elif sched == "sunday" and datetime.datetime.now().weekday() == 6:
                        should_check = True
                    elif sched == "monthly" and datetime.datetime.now().day == 1:
                        should_check = True

                if should_check:
                    pct = check_conf.get("percentage", "2%")
                    logger.info(f"  Integrity check ({pct})...")
                    cr = run_cmd(["restic", "-r", url] + sftp_opts + ["check", f"--read-data-subset={pct}"], check=False, env=target_env)
                    if cr is True:
                        logger.info("  Check passed")
                    else:
                        logger.warn(f"  Check issues: {cr}")
                        logger.notify("Restic Backup", f"Check issues on {name}", "warning")

                logger.info(f"  Target done ({fmt(time.time() - t_repo)})")
                logger.notify("Restic Backup", f"{job_name}/{name} OK ({up_dur})", "normal")
                success += 1
            else:
                logger.error(f"  FAILED: {name}")
                logger.notify("Restic Backup", f"{job_name}/{name} failed!", "alert")
                fail += 1

    finally:
        # Cleanup job-specific mounts
        if os.path.exists(job_root):
            try:
                mounts = subprocess.getoutput("cat /proc/mounts").splitlines()
                active = [l.split()[1] for l in mounts if job_root in l.split()[1]]
                for m in sorted(active, key=len, reverse=True):
                    run_cmd(["umount", "-l", m], check=False)
            except: pass
            try: shutil.rmtree(job_root)
            except: pass
        if snapshots:
            destroy_zfs_snapshots(snapshots)

    logger.info(f"=== Job {job_name} done ({fmt(time.time() - t_job)}) ===")
    return success, fail

# ==============================================================================
# BACKUP COMMAND
# ==============================================================================

def do_backup(config, job_id=None):
    sys.stdout.reconfigure(line_buffering=True)
    t_start = time.time()
    logger.info("--- Starting Backup ---")

    if not acquire_lock():
        sys.exit(1)

    setup_password(config)
    os.makedirs(MOUNT_ROOT, exist_ok=True)

    total_ok = 0
    total_fail = 0

    try:
        jobs = config.get("jobs", [])
        for job in jobs:
            if job_id and job.get("id") != job_id:
                continue
            if not job.get("enabled", True):
                continue
            ok, fail = run_job(config, job)
            total_ok += ok
            total_fail += fail
    except Exception as e:
        logger.error(f"CRITICAL: {e}")
        logger.notify("Restic Backup", f"Aborted: {e}", "alert")
        total_fail += 1
    finally:
        cleanup()

    dur = fmt(time.time() - t_start)
    if total_fail == 0 and total_ok > 0:
        logger.info(f"All OK ({dur})")
        logger.notify("Restic Backup", f"All OK ({dur})", "normal")
    elif total_ok > 0:
        logger.notify("Restic Backup", f"Partial: {total_ok} OK, {total_fail} failed", "warning")
    elif total_ok == 0 and total_fail == 0:
        logger.info("No enabled jobs/targets found.")
    else:
        logger.notify("Restic Backup", "All FAILED!", "alert")

    logger.info("--- Done ---")
    return 0 if total_fail == 0 else 1

# ==============================================================================
# STATUS
# ==============================================================================

def do_status():
    running = False
    pid = 0
    if os.path.exists(LOCK_FILE):
        try:
            pid = int(open(LOCK_FILE).read().strip())
            os.kill(pid, 0)
            running = True
        except: pass
    print(json.dumps({"running": running, "pid": pid}))
    return 0

# ==============================================================================
# MAIN
# ==============================================================================

def main():
    parser = argparse.ArgumentParser(description="Restic Backup Plugin")
    parser.add_argument("--backup", action="store_true", help="Run backup")
    parser.add_argument("--job", metavar="JOB_ID", help="Run specific job only")
    parser.add_argument("--status", action="store_true", help="Show status (JSON)")
    args = parser.parse_args()

    if args.status:
        return do_status()

    config = load_config()

    if args.backup:
        return do_backup(config, args.job)
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
