#!/usr/bin/python3
"""
Restic Backup Plugin for Unraid - Backend Script
Reads config from /boot/config/plugins/restic-backup/restic-backup.json
Supports multiple independent backup jobs.

Original-path backups (v2026.04.18.x+):
  * Source directories are passed to restic as their absolute paths — no
    bind-mount staging, no "." placeholder.
  * ZFS snapshots are created, bind-mounted under STAGE_ROOT, and then
    re-bound over their original mountpoints inside an `unshare -m` mount
    namespace before restic runs. This way restic records the real
    mountpoint (e.g. `/mnt/cache/appdata`) instead of a staging path.
"""
import os
import sys
import json
import shlex
import subprocess
import datetime
import signal
import time
import argparse

# ==============================================================================
# CONSTANTS
# ==============================================================================

CONFIG_FILE = "/boot/config/plugins/restic-backup/restic-backup.json"
HOOKS_DIR   = "/boot/config/plugins/restic-backup/hooks"
STAGE_ROOT  = "/tmp/restic-stage"           # where ZFS snapshots are bound to
LOCK_FILE   = "/tmp/restic-backup.lock"
PID_FILE    = "/tmp/restic-backup.pid"
LOG_DIR     = "/tmp"

# ==============================================================================
# LOGGER
# ==============================================================================

class Logger:
    """
    Dual-writer logger.
    * Every line goes into the combined main log (restic-backup-YYYYMMDD.log).
    * When a job is active (set_job()), the same line is also appended to a
      per-job log file (restic-backup-<job_id>-YYYYMMDD.log) so the UI can
      show only one job's output.
    """
    def __init__(self):
        self.main_log = os.path.join(LOG_DIR, f"restic-backup-{datetime.datetime.now():%Y%m%d}.log")
        self.job_log = None
        self.notifications_enabled = True

    # kept for backwards compatibility with any external callers
    @property
    def logfile(self):
        return self.main_log

    def set_job(self, job_id):
        if job_id:
            safe = "".join(c if c.isalnum() or c in "-_" else "_" for c in str(job_id))
            self.job_log = os.path.join(
                LOG_DIR, f"restic-backup-{safe}-{datetime.datetime.now():%Y%m%d}.log"
            )
        else:
            self.job_log = None

    def log(self, level, msg):
        timestamp = datetime.datetime.now().strftime("%H:%M:%S")
        formatted = f"[{timestamp}] [{level}] {msg}"
        for path in (self.main_log, self.job_log):
            if not path:
                continue
            try:
                with open(path, "a") as f:
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

def setup_notifications(config):
    general = config.get("general", {})
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

def _unmount_tree(prefix):
    """Lazy-unmount every active mount whose target starts with prefix,
    longest first so that nested binds are released before their parents."""
    try:
        mounts = subprocess.getoutput("cat /proc/mounts").splitlines()
        active = []
        for l in mounts:
            parts = l.split()
            if len(parts) >= 2 and parts[1].startswith(prefix):
                active.append(parts[1])
        for m in sorted(active, key=len, reverse=True):
            run_cmd(["umount", "-l", m], check=False)
    except Exception:
        pass

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

def build_target_env(target, general=None):
    """Return os.environ copy with credentials + password injected for the target."""
    env = os.environ.copy()
    if general is None:
        general = {}

    pw_mode = target.get("password_mode") or general.get("password_mode", "file")
    if pw_mode == "file":
        pw_file = target.get("password_file") or general.get("password_file", "")
        if pw_file:
            env["RESTIC_PASSWORD_FILE"] = pw_file
    elif pw_mode == "inline":
        pw = target.get("password_inline") or general.get("password_inline", "")
        if pw:
            env["RESTIC_PASSWORD"] = pw

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

def _zfs_mountpoint(ds):
    """Return the mountpoint for a ZFS dataset, falling back to /mnt/<ds>."""
    try:
        mp = subprocess.check_output(
            ["zfs", "get", "-H", "-o", "value", "mountpoint", ds],
            text=True, stderr=subprocess.DEVNULL
        ).strip()
        if mp and mp not in ("-", "none", "legacy"):
            return mp
    except Exception:
        pass
    return f"/mnt/{ds}"

def create_zfs_snapshots(zfs_conf):
    """Create ZFS snapshots as configured. Returns list of (ds, snap_name)."""
    if not zfs_conf.get("enabled", False):
        return []

    datasets  = zfs_conf.get("datasets", [])
    recursive = zfs_conf.get("recursive", True)
    prefix    = zfs_conf.get("snapshot_prefix", "restic-backup")
    snap_name = f"{prefix}-{datetime.datetime.now():%Y%m%d-%H%M%S}"
    created   = []

    for parent_ds in datasets:
        parent_ds = parent_ds.strip()
        if not parent_ds:
            continue

        if recursive:
            result = run_cmd(["zfs", "snapshot", "-r", f"{parent_ds}@{snap_name}"], check=False)
            if result is not True:
                logger.warn(f"Snapshot failed {parent_ds}: {result}")
                continue
            logger.info(f"Snapshot (recursive): {parent_ds}@{snap_name}")
            try:
                all_ds = subprocess.check_output(
                    ["zfs", "list", "-H", "-r", "-o", "name", parent_ds], text=True
                ).splitlines()
            except subprocess.CalledProcessError as e:
                logger.warn(f"Cannot enumerate datasets under {parent_ds}: {e}")
                all_ds = [parent_ds]
            for ds in all_ds:
                ds = ds.strip()
                if ds:
                    created.append((ds, snap_name))
        else:
            result = run_cmd(["zfs", "snapshot", f"{parent_ds}@{snap_name}"], check=False)
            if result is True:
                created.append((parent_ds, snap_name))
                logger.info(f"Snapshot: {parent_ds}@{snap_name}")
            else:
                logger.warn(f"Snapshot failed {parent_ds}: {result}")

    return created

def destroy_zfs_snapshots(snapshots):
    for ds, snap_name in snapshots:
        run_cmd(["zfs", "destroy", f"{ds}@{snap_name}"], check=False)

# ==============================================================================
# BACKUP ENVIRONMENT — original-path architecture
# ==============================================================================

def prepare_zfs_stages(zfs_conf):
    """
    Create the ZFS snapshots configured for the job and bind-mount each one
    (read-only) under STAGE_ROOT/<idx>.

    Returns (snapshots, stages) where:
      * snapshots = [(ds, snap_name), ...] — for cleanup/destroy
      * stages    = [(original_mountpoint, stage_path), ...] — for the
        mount-namespace remap in run_backup_attempt()
    """
    if not zfs_conf.get("enabled", False):
        return [], []

    os.makedirs(STAGE_ROOT, exist_ok=True)
    snapshots = create_zfs_snapshots(zfs_conf)
    stages = []

    for idx, (ds, snap_name) in enumerate(snapshots):
        real_path  = f"/mnt/{ds}/.zfs/snapshot/{snap_name}"
        stage_path = os.path.join(STAGE_ROOT, str(idx))
        if not os.path.exists(real_path):
            logger.warn(f"Snapshot path missing: {real_path}")
            continue
        os.makedirs(stage_path, exist_ok=True)
        result = run_cmd(["mount", "--bind", "-o", "ro", real_path, stage_path], check=False)
        if result is not True:
            logger.warn(f"Stage mount failed {real_path}: {result}")
            continue
        original_mp = _zfs_mountpoint(ds)
        stages.append((original_mp, stage_path))
        logger.info(f"Stage: {real_path} -> {stage_path}  (will appear as {original_mp})")

    return snapshots, stages

def collect_direct_paths(job):
    """Return the list of paths to back up directly — no binds, no staging."""
    paths = []
    seen  = set()

    for src in job.get("sources", []):
        if not src.get("enabled", True):
            continue
        path = (src.get("path") or "").rstrip("/")
        if not path:
            continue
        if not os.path.exists(path):
            logger.warn(f"Source missing: {path}")
            continue
        if path not in seen:
            paths.append(path)
            seen.add(path)

    if job.get("backup_boot", False):
        if os.path.exists("/boot") and "/boot" not in seen:
            paths.append("/boot")
            seen.add("/boot")

    return paths

def build_backup_argv(cmd_head, paths):
    """
    Assemble the final argv for `restic backup ...`. Expects cmd_head to end
    at the point where paths should be appended (i.e. after excludes/tags).
    """
    return list(cmd_head) + list(paths)

def run_backup_attempt(cmd_args, env, stages):
    """
    Execute the restic backup command.

    * No ZFS stages → just run restic directly.
    * With stages → spawn `unshare -m` and bind-mount each staging path over
      its original mountpoint inside the new namespace, then exec restic.
      restic then records the ORIGINAL mountpoint (e.g. /mnt/cache/appdata)
      in the snapshot instead of /tmp/restic-stage/<idx>.
    """
    if not stages:
        return run_cmd(cmd_args, check=False, env=env)

    script_lines = ["set -e"]
    for (original, stage) in stages:
        script_lines.append(f"mkdir -p {shlex.quote(original)}")
        script_lines.append(
            f"mount --bind -o ro {shlex.quote(stage)} {shlex.quote(original)}"
        )
    quoted_restic = " ".join(shlex.quote(a) for a in cmd_args)
    script_lines.append(f"exec {quoted_restic}")
    script = "\n".join(script_lines)

    wrapped = [
        "unshare", "-m", "--propagation", "private",
        "bash", "-c", script,
    ]
    return run_cmd(wrapped, check=False, env=env)

def cleanup_global():
    """Clear anything left over from a previous run — stage mounts + snapshots."""
    logger.info("Cleaning up...")
    if os.path.exists(STAGE_ROOT):
        _unmount_tree(STAGE_ROOT)
        try:
            import shutil
            shutil.rmtree(STAGE_ROOT, ignore_errors=True)
        except Exception:
            pass
    # Also clean any leftover temp-snap ZFS snapshots from crashed runs
    try:
        out = subprocess.check_output(
            ["zfs", "list", "-H", "-t", "snapshot", "-o", "name"],
            text=True, stderr=subprocess.DEVNULL
        ).splitlines()
        for line in out:
            line = line.strip()
            if "@restic-backup-" in line:
                run_cmd(["zfs", "destroy", line], check=False)
    except Exception:
        pass
    release_lock()

# ==============================================================================
# PRE / POST BACKUP HOOKS
#
# Hook commands are persisted as .sh files under
#   /boot/config/plugins/restic-backup/hooks/<jobid>/{pre,post}-<idx>-<slug>.sh
# by the PHP save layer. We pick them up by (job_id, phase, hook_id) — the
# JSON config is the source of truth for order and metadata.
# ==============================================================================

def _slug(name):
    s = (name or "").lower()
    out = []
    for ch in s:
        if ch.isalnum():
            out.append(ch)
        elif out and out[-1] != "-":
            out.append("-")
    return "".join(out).strip("-")

def _find_hook_script(job_id, phase, idx, name):
    """
    Return the path to the materialized hook .sh file, or None if missing.
    Index is 1-based in the filename. Falls back to a fuzzy match if the
    slug drifted (e.g. user renamed the hook but save hasn't run yet).
    """
    if not job_id:
        return None
    job_dir = os.path.join(HOOKS_DIR, job_id)
    if not os.path.isdir(job_dir):
        return None
    exact = os.path.join(job_dir, f"{phase}-{idx:02d}-{_slug(name) or 'hook'}.sh")
    if os.path.isfile(exact):
        return exact
    # Fallback: any file starting with "<phase>-<idx>-"
    prefix = f"{phase}-{idx:02d}-"
    for f in sorted(os.listdir(job_dir)):
        if f.startswith(prefix) and f.endswith(".sh"):
            return os.path.join(job_dir, f)
    return None

def run_hooks(job, phase, status_ctx=None):
    """
    Execute the pre_backup or post_backup hook list for a job.

    phase: "pre_backup" or "post_backup"
    status_ctx: dict with success/fail counts for post-hooks (optional)

    Returns:
      (ran, failed_abort)  — ran = hooks actually executed (not skipped),
                             failed_abort = True if any hook with on_error=abort
                             ended non-zero and we should abort the job.
    """
    hooks = (job.get("hooks") or {}).get(phase) or []
    if not hooks:
        return 0, False

    phase_short = "pre" if phase == "pre_backup" else "post"
    job_id   = job.get("id", "")
    job_name = job.get("name", "Unnamed")
    ran = 0
    abort = False

    logger.info(f"--- Running {len(hooks)} {phase_short}-hook(s) ---")

    for idx, hook in enumerate(hooks, start=1):
        if not hook.get("enabled", True):
            logger.info(f"  [{idx}] '{hook.get('name','')}' disabled — skip")
            continue

        name     = hook.get("name", "") or f"hook-{idx}"
        timeout  = int(hook.get("timeout", 3600) or 3600)
        on_error = hook.get("on_error", "continue")
        script   = _find_hook_script(job_id, phase_short, idx, name)

        # Fallback: if the .sh wasn't materialized (e.g. first run after a
        # raw JSON edit), write a throwaway to /tmp so the hook still runs.
        cleanup_tmp = None
        if not script:
            command = hook.get("command", "") or ""
            tmp_path = f"/tmp/restic-hook-{job_id}-{phase_short}-{idx}.sh"
            try:
                with open(tmp_path, "w") as f:
                    f.write("#!/bin/bash\n" + command + "\n")
                os.chmod(tmp_path, 0o755)
                script = tmp_path
                cleanup_tmp = tmp_path
            except Exception as e:
                logger.error(f"  [{idx}] '{name}' cannot create temp script: {e}")
                if on_error == "abort":
                    abort = True
                    break
                continue

        # Environment passed to the hook.
        env = os.environ.copy()
        env["RESTIC_JOB_ID"]    = job_id
        env["RESTIC_JOB_NAME"]  = job_name
        env["RESTIC_PHASE"]     = phase_short
        env["RESTIC_HOSTNAME"]  = (status_ctx or {}).get("hostname", "") or env.get("RESTIC_HOSTNAME", "")
        if phase == "post_backup" and status_ctx is not None:
            env["RESTIC_STATUS"]      = status_ctx.get("status", "")
            env["RESTIC_OK_COUNT"]    = str(status_ctx.get("ok", 0))
            env["RESTIC_FAIL_COUNT"]  = str(status_ctx.get("fail", 0))

        logger.info(f"  [{idx}] '{name}' starting (timeout {timeout}s)")
        t_hook = time.time()
        try:
            proc = subprocess.run(
                ["/bin/bash", script],
                env=env,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                timeout=timeout,
            )
            dt = time.time() - t_hook
            # Pipe hook output into the log, one line at a time.
            for line in (proc.stdout or "").splitlines():
                logger.info(f"      {line}")
            if proc.returncode == 0:
                logger.info(f"  [{idx}] '{name}' OK ({fmt(dt)})")
                ran += 1
            else:
                logger.error(f"  [{idx}] '{name}' FAILED exit={proc.returncode} ({fmt(dt)})")
                if on_error == "abort":
                    abort = True
                    if cleanup_tmp:
                        try: os.unlink(cleanup_tmp)
                        except Exception: pass
                    break
        except subprocess.TimeoutExpired:
            logger.error(f"  [{idx}] '{name}' TIMEOUT after {timeout}s")
            if on_error == "abort":
                abort = True
                if cleanup_tmp:
                    try: os.unlink(cleanup_tmp)
                    except Exception: pass
                break
        except Exception as e:
            logger.error(f"  [{idx}] '{name}' EXCEPTION: {e}")
            if on_error == "abort":
                abort = True
                if cleanup_tmp:
                    try: os.unlink(cleanup_tmp)
                    except Exception: pass
                break

        if cleanup_tmp:
            try: os.unlink(cleanup_tmp)
            except Exception: pass

    return ran, abort

# ==============================================================================
# RUN JOB
# ==============================================================================

def run_job(config, job):
    job_name = job.get("name", "Unnamed")
    logger.set_job(job.get("id"))
    logger.info(f"=== Job: {job_name} ===")
    t_job = time.time()

    general     = config.get("general", {})
    targets     = job.get("targets", [])
    excludes    = job.get("excludes", {})
    retention   = job.get("retention", {})
    check_conf  = job.get("check", {})
    max_retries = job.get("max_retries", 3)
    retry_wait  = job.get("retry_wait", 30)
    hostname    = general.get("hostname", "")
    tags        = job.get("tags", "")

    # -------------------------------------------------------------------------
    # PRE-BACKUP HOOKS — run as the very first thing in the job, before any
    # ZFS snapshot, before path collection, before restic. If a hook with
    # on_error=abort fails, we skip the backup entirely (but still run
    # post-hooks with status=failed).
    # -------------------------------------------------------------------------
    pre_status_ctx = {"hostname": hostname}
    _pre_ran, pre_abort = run_hooks(job, "pre_backup", pre_status_ctx)
    if pre_abort:
        logger.error(f"Pre-hook aborted job '{job_name}'. Skipping backup.")
        # Run post-hooks with failure status so notifiers still fire.
        run_hooks(job, "post_backup",
                  {"hostname": hostname, "status": "failed", "ok": 0, "fail": 0})
        logger.info(f"=== Job {job_name} aborted by pre-hook ({fmt(time.time() - t_job)}) ===")
        logger.set_job(None)
        return 0, 0

    direct_paths = collect_direct_paths(job)
    snapshots, stages = prepare_zfs_stages(job.get("zfs", {}))

    # Final list of paths passed to restic — always absolute.
    # Stage paths surface to restic as their original mountpoints.
    all_paths = list(direct_paths) + [original for (original, _stage) in stages]
    if not all_paths:
        logger.warn(f"Job '{job_name}' has no enabled sources. Skipping.")
        # Still need to clean up any ZFS snapshots that might have been taken
        if snapshots:
            destroy_zfs_snapshots(snapshots)
        run_hooks(job, "post_backup",
                  {"hostname": hostname, "status": "failed", "ok": 0, "fail": 0})
        logger.set_job(None)
        return 0, 0

    success = 0
    fail = 0

    try:
        for target in targets:
            if not target.get("enabled", True):
                continue
            url = get_target_url(target)
            target_env = build_target_env(target, general)
            name = target.get("name", url)
            if not url:
                logger.warn(f"Target has no URL: {name}")
                continue

            logger.info(f"Target: {name}")
            t_repo = time.time()

            sftp_opts = get_sftp_opts(target)
            cmd_head = ["restic", "-r", url] + sftp_opts + ["backup"]
            if tags:
                cmd_head.extend(["--tag", tags])
            if hostname:
                cmd_head.extend(["--host", hostname])

            for ex in excludes.get("global", []):
                ex = ex.strip()
                if ex: cmd_head.append(f"--exclude={ex}")

            if target.get("use_optional_excludes", False):
                logger.info("  Applying optional excludes")
                for ex in excludes.get("optional", []):
                    ex = ex.strip()
                    if ex: cmd_head.append(f"--exclude={ex}")

            # Append paths last, exactly as restic expects
            cmd = build_backup_argv(cmd_head, all_paths)

            logger.info("  Uploading...")
            logger.info("  Paths: " + ", ".join(all_paths))
            t_up = time.time()
            ok = False
            for attempt in range(1, max_retries + 1):
                result = run_backup_attempt(cmd, target_env, stages)
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

                # Retention / Prune — restricted to this job's tag when set
                prune_cmd = ["restic", "-r", url] + sftp_opts + ["forget", "--prune"]
                if tags:
                    # Use the first tag as the isolation key for forget so that
                    # multiple jobs sharing one repository don't prune each other.
                    first_tag = tags.split(",")[0].strip()
                    if first_tag:
                        prune_cmd.extend(["--tag", first_tag])
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
        # Unmount + destroy ZFS snapshots belonging to this job
        if stages:
            _unmount_tree(STAGE_ROOT)
        if snapshots:
            destroy_zfs_snapshots(snapshots)

        # ---------------------------------------------------------------------
        # POST-BACKUP HOOKS — run after all targets are processed, regardless
        # of success/failure. RESTIC_STATUS reflects the outcome:
        #   success  — every enabled target succeeded
        #   partial  — some succeeded, some failed
        #   failed   — every target failed (or no target ran)
        # ---------------------------------------------------------------------
        if success > 0 and fail == 0:
            _status = "success"
        elif success > 0 and fail > 0:
            _status = "partial"
        else:
            _status = "failed"
        run_hooks(job, "post_backup", {
            "hostname": hostname,
            "status":   _status,
            "ok":       success,
            "fail":     fail,
        })

    logger.info(f"=== Job {job_name} done ({fmt(time.time() - t_job)}) ===")
    logger.set_job(None)
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

    setup_notifications(config)
    os.makedirs(STAGE_ROOT, exist_ok=True)

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
        cleanup_global()

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
        cleanup_global()
        sys.exit(0)
    signal.signal(signal.SIGINT, signal_handler)
    sys.exit(main())
