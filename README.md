# Restic Backup Plugin for Unraid

An Unraid plugin that provides a web GUI for managing [restic](https://restic.net/) backups directly from the Unraid dashboard.

## Features

- **Multiple Backup Jobs** - Create independent backup jobs with their own targets, sources, schedules, and retention policies
- **Multiple Backup Targets** - Back up to local drives, SFTP, S3/Minio, Backblaze B2, REST Server, or Rclone (with type selector dropdown)
- **Directory Browser** - Browse and select source directories directly from the web GUI
- **ZFS Snapshot Support** - Optional ZFS snapshots with auto-detection of available datasets (supports recursive snapshots across child datasets)
- **Exclude Patterns** - Global excludes for all targets + optional excludes for space-saving on cloud targets
- **Configurable Retention** - Per-job retention policies (daily, weekly, monthly, yearly)
- **Scheduled Backups** - Built-in cron scheduling with presets or custom expressions
- **Integrity Checks** - Configurable periodic data integrity verification
- **Live Log Viewer** - Monitor backup progress in real-time from the web GUI
- **Unraid Notifications** - Get notified about backup success/failure through Unraid's notification system
- **Retry Logic** - Automatic retries with configurable attempts and wait time

## Installation

### Manual Install

1. In Unraid, go to **Plugins** > **Install Plugin**
2. Paste the plugin URL:
   ```
   https://raw.githubusercontent.com/MajorPain007/unraid-restic/main/src/restic-backup.plg
   ```
3. Click **Install**

The plugin automatically downloads and installs the restic binary.

## Configuration

After installation, navigate to **Settings** > **Utilities** > **Restic Backup**.

### General Settings

- **Password** - Repository password (file path or inline)
- **Hostname & Tags** - Applied to all restic snapshots
- **Retries** - Number of retry attempts and wait time between them
- **Integrity Check** - Enable periodic `restic check` with configurable data percentage

### Backup Targets

Add one or more restic repositories:

| Field | Description |
|---|---|
| Repository URL | `sftp://host:/path`, `/mnt/disks/backup/repo`, `s3:endpoint/bucket`, etc. |
| Name | Display name for the target |
| Optional Excludes | Whether to apply the optional exclude list to this target |
| Enabled | Toggle target on/off without deleting it |

Each target has **Init Repo** and **Test Connection** buttons.

### Source Directories

Add directories to include in the backup. Each source is bind-mounted (read-only by default) into a temporary backup root before restic runs.

### ZFS Snapshots

If your Unraid setup uses ZFS, you can enable automatic snapshots before each backup:

- **Recursive mode** - Automatically snapshots all child datasets under a parent (e.g., `cache/appdata` and all datasets beneath it)
- **Non-recursive mode** - Only snapshots the exact datasets you list
- Snapshots are cleaned up automatically after backup completes

### Exclude Patterns

Two lists using [restic exclude syntax](https://restic.readthedocs.io/en/stable/040_backup.html#excluding-files):

- **Global Excludes** - Applied to every target (e.g., `.Trash`, `*.DS_Store`, temp files)
- **Optional Excludes** - Only applied to targets with "Use Optional Excludes" enabled (e.g., large media caches for cloud targets)

### Schedule

Enable automatic backups with presets or a custom cron expression.

## File Locations

| What | Path |
|---|---|
| Plugin GUI | `/usr/local/emhttp/plugins/restic-backup/` |
| Configuration | `/boot/config/plugins/restic-backup/restic-backup.json` |
| Restic binary | `/usr/local/bin/restic` |
| Log files | `/tmp/restic-backup-YYYYMMDD.log` |
| Cron schedule | `/etc/cron.d/restic-backup` |

## Project Structure

```
archive/                       # Versioned txz packages
scripts/
  build.sh                     # Build script (produces txz + MD5)
src/
  restic-backup.plg            # Plugin installer
  restic-backup.page           # Unraid page definition
  ResticBackup.php             # Main GUI (single page, collapsible sections)
  ResticBackupAPI.php          # API endpoint
  include/
    helpers.php                # Config management, utility functions
  scripts/
    restic-backup.py           # Python backend
    setup_cron.sh              # Restores cron jobs on boot
  assets/
    script.js                  # Frontend logic
```

## Requirements

- Unraid 6.12.0 or newer
- Python 3 (included in Unraid)
- Internet connection for initial restic download

## License

MIT
