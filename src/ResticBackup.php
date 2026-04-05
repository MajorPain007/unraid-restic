<?php
/**
 * Restic Backup Plugin - Main GUI Page
 * Single page with collapsible sections.
 */
require_once '/usr/local/emhttp/plugins/restic-backup/include/helpers.php';

$config = restic_load_config();
$running = restic_is_running();
?>

<link type="text/css" rel="stylesheet" href="<?autov('/webGui/styles/jquery.ui.css')?>">
<style>
.restic-section {
    margin-bottom: 16px;
    border: 1px solid #2a2a2a;
    border-radius: 4px;
}
.restic-section-header {
    background: #1c1c1c;
    padding: 10px 16px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: bold;
    font-size: 1.1em;
}
.restic-section-header:hover {
    background: #252525;
}
.restic-section-header .toggle-icon {
    transition: transform 0.2s;
}
.restic-section-header.collapsed .toggle-icon {
    transform: rotate(-90deg);
}
.restic-section-body {
    padding: 16px;
}
.restic-section-body.hidden {
    display: none;
}
.restic-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.restic-row label {
    min-width: 180px;
    font-weight: bold;
}
.restic-row input[type="text"],
.restic-row input[type="number"],
.restic-row select {
    flex: 1;
    min-width: 200px;
    max-width: 500px;
}
.restic-row .hint {
    color: #888;
    font-size: 0.85em;
    width: 100%;
    margin-left: 190px;
}
.restic-list-item {
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 4px;
    padding: 12px;
    margin-bottom: 8px;
}
.restic-list-item .item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.restic-list-item .item-header .item-title {
    font-weight: bold;
}
.restic-btn {
    padding: 6px 16px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 0.9em;
}
.restic-btn-primary {
    background: #ff8c2f;
    color: #fff;
}
.restic-btn-primary:hover {
    background: #e67a20;
}
.restic-btn-danger {
    background: #c0392b;
    color: #fff;
}
.restic-btn-danger:hover {
    background: #a93226;
}
.restic-btn-secondary {
    background: #444;
    color: #fff;
}
.restic-btn-secondary:hover {
    background: #555;
}
.restic-btn-success {
    background: #27ae60;
    color: #fff;
}
.restic-btn-success:hover {
    background: #219a52;
}
textarea.restic-excludes {
    width: 100%;
    max-width: 700px;
    height: 150px;
    font-family: monospace;
    font-size: 0.9em;
    background: #111;
    color: #ddd;
    border: 1px solid #444;
    padding: 8px;
    resize: vertical;
}
#restic-log {
    background: #111;
    color: #0f0;
    font-family: monospace;
    font-size: 0.85em;
    padding: 12px;
    height: 400px;
    overflow-y: auto;
    white-space: pre-wrap;
    border: 1px solid #333;
    border-radius: 4px;
}
.restic-status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 3px;
    font-weight: bold;
    font-size: 0.85em;
}
.restic-status-idle { background: #27ae60; color: #fff; }
.restic-status-running { background: #f39c12; color: #fff; }
</style>

<!-- ============================================================ -->
<!-- STATUS & CONTROL -->
<!-- ============================================================ -->
<div class="restic-section">
    <div class="restic-section-header" onclick="toggleSection(this)">
        <span>Status &amp; Control</span>
        <span class="toggle-icon">&#9660;</span>
    </div>
    <div class="restic-section-body">
        <div class="restic-row">
            <label>Status:</label>
            <span id="restic-status-badge" class="restic-status-badge <?= $running ? 'restic-status-running' : 'restic-status-idle' ?>">
                <?= $running ? 'RUNNING' : 'IDLE' ?>
            </span>
        </div>
        <div class="restic-row" style="margin-top:12px;">
            <button id="btn-start" class="restic-btn restic-btn-success" onclick="startBackup()" <?= $running ? 'disabled' : '' ?>>Start Backup Now</button>
            <button id="btn-stop" class="restic-btn restic-btn-danger" onclick="stopBackup()" <?= !$running ? 'disabled' : '' ?>>Stop Backup</button>
            <button class="restic-btn restic-btn-secondary" onclick="refreshLog()">Refresh Log</button>
        </div>
        <div style="margin-top:12px;">
            <div id="restic-log">No log data yet. Start a backup or click "Refresh Log".</div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- GENERAL SETTINGS -->
<!-- ============================================================ -->
<div class="restic-section">
    <div class="restic-section-header collapsed" onclick="toggleSection(this)">
        <span>General Settings</span>
        <span class="toggle-icon">&#9660;</span>
    </div>
    <div class="restic-section-body hidden">
        <div class="restic-row">
            <label>Password Mode:</label>
            <select id="cfg-password-mode" onchange="togglePasswordFields()">
                <option value="file" <?= $config['general']['password_mode'] === 'file' ? 'selected' : '' ?>>Password File</option>
                <option value="inline" <?= $config['general']['password_mode'] === 'inline' ? 'selected' : '' ?>>Inline Password</option>
            </select>
        </div>
        <div class="restic-row" id="row-password-file">
            <label>Password File Path:</label>
            <input type="text" id="cfg-password-file" value="<?= htmlspecialchars($config['general']['password_file']) ?>" placeholder="/mnt/user/appdata/restic/password.txt">
        </div>
        <div class="restic-row" id="row-password-inline" style="display:none;">
            <label>Password:</label>
            <input type="text" id="cfg-password-inline" value="<?= htmlspecialchars($config['general']['password_inline']) ?>" placeholder="Enter restic repository password">
        </div>
        <div class="restic-row">
            <label>Hostname:</label>
            <input type="text" id="cfg-hostname" value="<?= htmlspecialchars($config['general']['hostname']) ?>" placeholder="e.g. my-unraid-server">
            <span class="hint">Used as --host in restic. Leave empty to use system hostname.</span>
        </div>
        <div class="restic-row">
            <label>Tags:</label>
            <input type="text" id="cfg-tags" value="<?= htmlspecialchars($config['general']['tags']) ?>" placeholder="e.g. unraid,full">
            <span class="hint">Comma-separated tags applied to all snapshots.</span>
        </div>
        <div class="restic-row">
            <label>Max Retries:</label>
            <input type="number" id="cfg-max-retries" value="<?= (int)$config['general']['max_retries'] ?>" min="1" max="10" style="max-width:80px;">
        </div>
        <div class="restic-row">
            <label>Retry Wait (seconds):</label>
            <input type="number" id="cfg-retry-wait" value="<?= (int)$config['general']['retry_wait'] ?>" min="5" max="300" style="max-width:80px;">
        </div>
        <hr>
        <div class="restic-row">
            <label>Integrity Check:</label>
            <select id="cfg-check-enabled">
                <option value="0" <?= !$config['general']['check_enabled'] ? 'selected' : '' ?>>Disabled</option>
                <option value="1" <?= $config['general']['check_enabled'] ? 'selected' : '' ?>>Enabled</option>
            </select>
        </div>
        <div class="restic-row">
            <label>Check Data Percentage:</label>
            <input type="text" id="cfg-check-percentage" value="<?= htmlspecialchars($config['general']['check_percentage']) ?>" placeholder="2%" style="max-width:80px;">
        </div>
        <div class="restic-row">
            <label>Check Schedule:</label>
            <select id="cfg-check-schedule">
                <option value="sunday" <?= $config['general']['check_schedule'] === 'sunday' ? 'selected' : '' ?>>Every Sunday</option>
                <option value="monthly" <?= $config['general']['check_schedule'] === 'monthly' ? 'selected' : '' ?>>First of Month</option>
                <option value="always" <?= $config['general']['check_schedule'] === 'always' ? 'selected' : '' ?>>Every Backup</option>
            </select>
        </div>
        <hr>
        <div class="restic-row">
            <label>Unraid Notifications:</label>
            <select id="cfg-notifications">
                <option value="1" <?= $config['notifications']['enabled'] ? 'selected' : '' ?>>Enabled</option>
                <option value="0" <?= !$config['notifications']['enabled'] ? 'selected' : '' ?>>Disabled</option>
            </select>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- BACKUP TARGETS -->
<!-- ============================================================ -->
<div class="restic-section">
    <div class="restic-section-header collapsed" onclick="toggleSection(this)">
        <span>Backup Targets</span>
        <span class="toggle-icon">&#9660;</span>
    </div>
    <div class="restic-section-body hidden">
        <p style="color:#888;margin-bottom:12px;">Define where your backups are stored. Each target is a restic repository.</p>
        <div id="targets-list">
            <?php foreach ($config['targets'] as $i => $t): ?>
            <div class="restic-list-item" data-id="<?= htmlspecialchars($t['id']) ?>">
                <div class="item-header">
                    <span class="item-title">Target #<?= $i + 1 ?></span>
                    <div>
                        <button class="restic-btn restic-btn-secondary" onclick="initRepo(this)" title="Initialize Repository">Init Repo</button>
                        <button class="restic-btn restic-btn-secondary" onclick="testTarget(this)" title="Test Connection">Test</button>
                        <button class="restic-btn restic-btn-danger" onclick="removeListItem(this)">Remove</button>
                    </div>
                </div>
                <div class="restic-row">
                    <label>Repository URL:</label>
                    <input type="text" class="target-url" value="<?= htmlspecialchars($t['url']) ?>" placeholder="sftp://host:/path or /mnt/disks/...">
                </div>
                <div class="restic-row">
                    <label>Name:</label>
                    <input type="text" class="target-name" value="<?= htmlspecialchars($t['name']) ?>" placeholder="e.g. Hetzner Cloud">
                </div>
                <div class="restic-row">
                    <label>Use Optional Excludes:</label>
                    <select class="target-optional-excludes">
                        <option value="0" <?= !$t['use_optional_excludes'] ? 'selected' : '' ?>>No</option>
                        <option value="1" <?= $t['use_optional_excludes'] ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
                <div class="restic-row">
                    <label>Enabled:</label>
                    <select class="target-enabled">
                        <option value="1" <?= $t['enabled'] !== false ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= $t['enabled'] === false ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="restic-btn restic-btn-primary" onclick="addTarget()" style="margin-top:8px;">+ Add Target</button>
    </div>
</div>

<!-- ============================================================ -->
<!-- SOURCE DIRECTORIES -->
<!-- ============================================================ -->
<div class="restic-section">
    <div class="restic-section-header collapsed" onclick="toggleSection(this)">
        <span>Source Directories</span>
        <span class="toggle-icon">&#9660;</span>
    </div>
    <div class="restic-section-body hidden">
        <p style="color:#888;margin-bottom:12px;">Directories to include in the backup. Each will be mounted into a temporary backup root.</p>
        <div id="sources-list">
            <?php foreach ($config['sources'] as $i => $s): ?>
            <div class="restic-list-item" data-id="<?= htmlspecialchars($s['id']) ?>">
                <div class="item-header">
                    <span class="item-title">Source #<?= $i + 1 ?></span>
                    <button class="restic-btn restic-btn-danger" onclick="removeListItem(this)">Remove</button>
                </div>
                <div class="restic-row">
                    <label>Path:</label>
                    <input type="text" class="source-path" value="<?= htmlspecialchars($s['path']) ?>" placeholder="/mnt/user/appdata">
                </div>
                <div class="restic-row">
                    <label>Label:</label>
                    <input type="text" class="source-label" value="<?= htmlspecialchars($s['label']) ?>" placeholder="appdata (used as folder name in backup)">
                </div>
                <div class="restic-row">
                    <label>Read-Only Mount:</label>
                    <select class="source-readonly">
                        <option value="1" <?= $s['readonly_mount'] !== false ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= $s['readonly_mount'] === false ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="restic-row">
                    <label>Enabled:</label>
                    <select class="source-enabled">
                        <option value="1" <?= $s['enabled'] !== false ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= $s['enabled'] === false ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="restic-btn restic-btn-primary" onclick="addSource()" style="margin-top:8px;">+ Add Source</button>
    </div>
</div>

<!-- ============================================================ -->
<!-- ZFS SNAPSHOTS -->
<!-- ============================================================ -->
<div class="restic-section">
    <div class="restic-section-header collapsed" onclick="toggleSection(this)">
        <span>ZFS Snapshots (Optional)</span>
        <span class="toggle-icon">&#9660;</span>
    </div>
    <div class="restic-section-body hidden">
        <p style="color:#888;margin-bottom:12px;">
            Create ZFS snapshots before backup for consistent data. The snapshots are mounted read-only and cleaned up after backup.
        </p>
        <div class="restic-row">
            <label>Enable ZFS Snapshots:</label>
            <select id="cfg-zfs-enabled">
                <option value="0" <?= !$config['zfs']['enabled'] ? 'selected' : '' ?>>Disabled</option>
                <option value="1" <?= $config['zfs']['enabled'] ? 'selected' : '' ?>>Enabled</option>
            </select>
        </div>
        <div class="restic-row">
            <label>Recursive:</label>
            <select id="cfg-zfs-recursive">
                <option value="1" <?= $config['zfs']['recursive'] ? 'selected' : '' ?>>Yes (all child datasets)</option>
                <option value="0" <?= !$config['zfs']['recursive'] ? 'selected' : '' ?>>No (only listed datasets)</option>
            </select>
            <span class="hint">Recursive: automatically includes all child datasets under each parent dataset.</span>
        </div>
        <div class="restic-row">
            <label>Snapshot Prefix:</label>
            <input type="text" id="cfg-zfs-prefix" value="<?= htmlspecialchars($config['zfs']['snapshot_prefix']) ?>" placeholder="restic-backup">
        </div>
        <div style="margin-top:12px;">
            <label style="font-weight:bold;display:block;margin-bottom:8px;">ZFS Datasets:</label>
            <div id="zfs-datasets-list">
                <?php foreach ($config['zfs']['datasets'] as $ds): ?>
                <div class="restic-row">
                    <input type="text" class="zfs-dataset" value="<?= htmlspecialchars($ds) ?>" placeholder="e.g. cache/appdata" style="flex:1;">
                    <button class="restic-btn restic-btn-danger" onclick="this.parentElement.remove()">Remove</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button class="restic-btn restic-btn-secondary" onclick="addZfsDataset()" style="margin-top:4px;">+ Add Dataset</button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- EXCLUDE PATTERNS -->
<!-- ============================================================ -->
<div class="restic-section">
    <div class="restic-section-header collapsed" onclick="toggleSection(this)">
        <span>Exclude Patterns</span>
        <span class="toggle-icon">&#9660;</span>
    </div>
    <div class="restic-section-body hidden">
        <p style="color:#888;margin-bottom:12px;">
            One pattern per line. Uses
            <a href="https://restic.readthedocs.io/en/stable/040_backup.html#excluding-files" target="_blank" style="color:#ff8c2f;">restic exclude syntax</a>
            (glob patterns, ** for recursive matching).
        </p>
        <label style="font-weight:bold;">Global Excludes (applied to ALL targets):</label>
        <textarea class="restic-excludes" id="cfg-excludes-global" placeholder="e.g.&#10;.Trash&#10;**/.Recycle.Bin/**&#10;*.DS_Store"><?= htmlspecialchars(implode("\n", $config['excludes']['global'])) ?></textarea>

        <label style="font-weight:bold;margin-top:12px;display:block;">Optional Excludes (only for targets with "Use Optional Excludes" enabled):</label>
        <textarea class="restic-excludes" id="cfg-excludes-optional" placeholder="e.g.&#10;**/jellyfin/cache/**&#10;**/Plex Media Server/Cache/**"><?= htmlspecialchars(implode("\n", $config['excludes']['optional'])) ?></textarea>
    </div>
</div>

<!-- ============================================================ -->
<!-- SCHEDULE -->
<!-- ============================================================ -->
<div class="restic-section">
    <div class="restic-section-header collapsed" onclick="toggleSection(this)">
        <span>Schedule</span>
        <span class="toggle-icon">&#9660;</span>
    </div>
    <div class="restic-section-body hidden">
        <div class="restic-row">
            <label>Scheduled Backup:</label>
            <select id="cfg-schedule-enabled">
                <option value="0" <?= !$config['schedule']['enabled'] ? 'selected' : '' ?>>Disabled</option>
                <option value="1" <?= $config['schedule']['enabled'] ? 'selected' : '' ?>>Enabled</option>
            </select>
        </div>
        <div class="restic-row">
            <label>Schedule Preset:</label>
            <select id="cfg-schedule-preset" onchange="applySchedulePreset()">
                <option value="">Custom</option>
                <option value="0 3 * * *">Daily at 3:00 AM</option>
                <option value="0 4 * * *">Daily at 4:00 AM</option>
                <option value="0 2 * * 0">Weekly Sunday 2:00 AM</option>
                <option value="0 2 * * 6">Weekly Saturday 2:00 AM</option>
                <option value="0 3 1 * *">Monthly 1st at 3:00 AM</option>
            </select>
        </div>
        <div class="restic-row">
            <label>Cron Expression:</label>
            <input type="text" id="cfg-schedule-cron" value="<?= htmlspecialchars($config['schedule']['cron']) ?>" placeholder="0 3 * * *" style="max-width:200px;">
            <span class="hint">Format: minute hour day-of-month month day-of-week</span>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- SAVE BUTTON -->
<!-- ============================================================ -->
<div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
    <button class="restic-btn restic-btn-primary" onclick="saveConfig()" style="padding:10px 30px; font-size:1.1em;">Save Configuration</button>
    <span id="save-status" style="color:#27ae60; display:none;">Configuration saved!</span>
</div>

<script src="<?autov('/plugins/restic-backup/assets/script.js')?>"></script>
<script>
// Initialize password field visibility
togglePasswordFields();
</script>
