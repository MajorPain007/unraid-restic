<?php
/**
 * Restic Backup Plugin - Main GUI (v4)
 * Job-based architecture with collapsible sections.
 */
require_once '/usr/local/emhttp/plugins/restic-backup/include/helpers.php';

$config = restic_load_config();
$running = restic_is_running();
$jobs = $config['jobs'] ?? [];
?>

<link type="text/css" rel="stylesheet" href="<?autov('/webGui/styles/jquery.ui.css')?>">
<style>
:root { --accent: #ff8c2f; --accent-hover: #e67a20; --green: #27ae60; --red: #c0392b; --bg-card: #1c1c1c; --bg-input: #111; --border: #333; --text: #ddd; --text-muted: #888; }
.rb-wrap { max-width: 1100px; }
.rb-section { margin-bottom: 12px; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
.rb-section-hdr { background: var(--bg-card); padding: 10px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-weight: bold; font-size: 1.05em; user-select: none; }
.rb-section-hdr:hover { background: #252525; }
.rb-section-hdr .arr { transition: transform .2s; font-size: .8em; }
.rb-section-hdr.closed .arr { transform: rotate(-90deg); }
.rb-section-body { padding: 14px 16px; }
.rb-section-body.hidden { display: none; }
.rb-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
.rb-row label { min-width: 170px; font-weight: bold; flex-shrink: 0; }
.rb-row input[type="text"], .rb-row input[type="number"], .rb-row select { flex: 1; min-width: 180px; max-width: 480px; padding: 5px 8px; background: var(--bg-input); color: var(--text); border: 1px solid var(--border); border-radius: 3px; }
.rb-hint { color: var(--text-muted); font-size: .82em; width: 100%; padding-left: 180px; }

/* Buttons */
.rb-btn { padding: 5px 14px; border: none; border-radius: 3px; cursor: pointer; font-size: .88em; color: #fff; }
.rb-btn-accent { background: var(--accent); } .rb-btn-accent:hover { background: var(--accent-hover); }
.rb-btn-green { background: var(--green); } .rb-btn-green:hover { background: #219a52; }
.rb-btn-red { background: var(--red); } .rb-btn-red:hover { background: #a93226; }
.rb-btn-gray { background: #444; } .rb-btn-gray:hover { background: #555; }
.rb-btn-sm { padding: 3px 10px; font-size: .82em; }

/* Job Tabs */
.rb-job-tabs { display: flex; gap: 4px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.rb-job-tab { padding: 6px 16px; background: #333; border: 1px solid var(--border); border-bottom: none; border-radius: 6px 6px 0 0; cursor: pointer; color: var(--text-muted); font-weight: bold; }
.rb-job-tab.active { background: var(--bg-card); color: var(--accent); border-color: var(--accent); }
.rb-job-tab:hover { color: #fff; }

/* Cards */
.rb-card { background: #1a1a1a; border: 1px solid var(--border); border-radius: 4px; padding: 12px; margin-bottom: 8px; }
.rb-card-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.rb-card-title { font-weight: bold; color: var(--accent); }

/* Log */
#rb-log { background: var(--bg-input); color: #0f0; font-family: monospace; font-size: .82em; padding: 10px; height: 350px; overflow-y: auto; white-space: pre-wrap; border: 1px solid var(--border); border-radius: 4px; }

/* Status */
.rb-badge { display: inline-block; padding: 2px 10px; border-radius: 3px; font-weight: bold; font-size: .82em; }
.rb-badge-idle { background: var(--green); color: #fff; }
.rb-badge-run { background: #f39c12; color: #fff; }

/* Excludes */
textarea.rb-excludes { width: 100%; max-width: 680px; height: 130px; font-family: monospace; font-size: .88em; background: var(--bg-input); color: var(--text); border: 1px solid var(--border); padding: 8px; resize: vertical; border-radius: 3px; }

/* Inline directory browser */
.rb-tree { border: 1px solid var(--border); border-radius: 4px; background: var(--bg-input); max-height: 300px; overflow-y: auto; margin-top: 4px; margin-bottom: 8px; max-width: 500px; }
.rb-tree-item { padding: 5px 10px; cursor: pointer; display: flex; align-items: center; gap: 6px; color: var(--text); font-size: .9em; }
.rb-tree-item:hover { background: #252525; }
.rb-tree-item.rb-tree-up { color: var(--accent); font-weight: bold; }
.rb-tree-item .rb-tree-icon { width: 16px; text-align: center; color: var(--accent); }
.rb-tree-hdr { padding: 6px 10px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-card); font-size: .85em; }
.rb-tree-hdr .rb-tree-path { font-family: monospace; color: var(--accent); }

/* Dataset picker */
.rb-ds-list { max-height: 250px; overflow-y: auto; border: 1px solid var(--border); border-radius: 4px; padding: 6px; background: var(--bg-input); }
.rb-ds-item { padding: 3px 6px; display: flex; align-items: center; gap: 6px; }
.rb-ds-item.child { padding-left: 24px; }
.rb-ds-item label { min-width: auto; font-weight: normal; cursor: pointer; }

/* Retention row */
.rb-retention-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
.rb-retention-grid label { min-width: auto; }
.rb-retention-grid input { width: 60px; }
</style>

<div class="rb-wrap">

<!-- ================================================================ -->
<!-- STATUS & CONTROL -->
<!-- ================================================================ -->
<div class="rb-section">
    <div class="rb-section-hdr" onclick="rbToggle(this)">
        <span>Status &amp; Control</span><span class="arr">&#9660;</span>
    </div>
    <div class="rb-section-body">
        <div class="rb-row">
            <label>Status:</label>
            <span id="rb-status" class="rb-badge <?= $running ? 'rb-badge-run' : 'rb-badge-idle' ?>"><?= $running ? 'RUNNING' : 'IDLE' ?></span>
        </div>
        <div class="rb-row" style="margin-top:10px;gap:6px;">
            <button id="btn-start" class="rb-btn rb-btn-green" onclick="rbStartBackup()" <?= $running ? 'disabled' : '' ?>>Start Backup</button>
            <button id="btn-stop" class="rb-btn rb-btn-red" onclick="rbStopBackup()" <?= !$running ? 'disabled' : '' ?>>Stop</button>
            <select id="rb-job-select" style="padding:5px;">
                <option value="">All Jobs</option>
                <?php foreach ($jobs as $j): ?>
                <option value="<?= htmlspecialchars($j['id']) ?>"><?= htmlspecialchars($j['name'] ?: 'Unnamed') ?></option>
                <?php endforeach; ?>
            </select>
            <button class="rb-btn rb-btn-gray" onclick="rbRefreshLog()">Refresh Log</button>
        </div>
        <div style="margin-top:10px;">
            <div id="rb-log">Click "Refresh Log" or start a backup.</div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- GENERAL SETTINGS -->
<!-- ================================================================ -->
<div class="rb-section">
    <div class="rb-section-hdr closed" onclick="rbToggle(this)">
        <span>General Settings</span><span class="arr">&#9660;</span>
    </div>
    <div class="rb-section-body hidden">
        <div class="rb-row">
            <label>Password Mode:</label>
            <select id="cfg-pw-mode" onchange="rbTogglePw()">
                <option value="file" <?= ($config['general']['password_mode'] ?? '') === 'file' ? 'selected' : '' ?>>Password File</option>
                <option value="inline" <?= ($config['general']['password_mode'] ?? '') === 'inline' ? 'selected' : '' ?>>Inline Password</option>
            </select>
        </div>
        <div class="rb-row" id="row-pw-file">
            <label>Password File:</label>
            <input type="text" id="cfg-pw-file" value="<?= htmlspecialchars($config['general']['password_file'] ?? '') ?>" placeholder="/mnt/user/appdata/restic/password.txt" data-picktree="file">
        </div>
        <div class="rb-row" id="row-pw-inline" style="display:none;">
            <label>Password:</label>
            <input type="text" id="cfg-pw-inline" value="<?= htmlspecialchars($config['general']['password_inline'] ?? '') ?>" placeholder="Repository password">
        </div>
        <div class="rb-row">
            <label>Hostname:</label>
            <input type="text" id="cfg-hostname" value="<?= htmlspecialchars($config['general']['hostname'] ?? '') ?>" placeholder="e.g. my-unraid">
            <span class="rb-hint">Used as --host in restic. Leave empty for system hostname.</span>
        </div>
        <div class="rb-row">
            <label>Notifications:</label>
            <select id="cfg-notify">
                <option value="1" <?= ($config['general']['notifications'] ?? true) ? 'selected' : '' ?>>Enabled</option>
                <option value="0" <?= !($config['general']['notifications'] ?? true) ? 'selected' : '' ?>>Disabled</option>
            </select>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- BACKUP JOBS -->
<!-- ================================================================ -->
<div class="rb-section">
    <div class="rb-section-hdr" onclick="rbToggle(this)">
        <span>Backup Jobs</span><span class="arr">&#9660;</span>
    </div>
    <div class="rb-section-body">
        <div class="rb-job-tabs" id="rb-job-tabs">
            <?php foreach ($jobs as $i => $j): ?>
            <div class="rb-job-tab <?= $i === 0 ? 'active' : '' ?>" onclick="rbSwitchJob(<?= $i ?>)"><?= htmlspecialchars($j['name'] ?: 'Job ' . ($i+1)) ?></div>
            <?php endforeach; ?>
            <button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbAddJob()">+ Add Job</button>
        </div>

        <div id="rb-jobs-container">
            <?php foreach ($jobs as $i => $j): ?>
            <div class="rb-job-panel" data-job-idx="<?= $i ?>" data-job-id="<?= htmlspecialchars($j['id'] ?? '') ?>" style="<?= $i > 0 ? 'display:none;' : '' ?>">

                <!-- Job Header -->
                <div class="rb-row">
                    <label>Job Name:</label>
                    <input type="text" class="job-name" value="<?= htmlspecialchars($j['name'] ?? '') ?>" placeholder="e.g. Daily Backup">
                    <select class="job-enabled"><option value="1" <?= ($j['enabled'] ?? true) ? 'selected' : '' ?>>Enabled</option><option value="0" <?= !($j['enabled'] ?? true) ? 'selected' : '' ?>>Disabled</option></select>
                    <button class="rb-btn rb-btn-red rb-btn-sm" onclick="rbRemoveJob(this)">Delete Job</button>
                </div>

                <!-- TARGETS -->
                <div class="rb-section" style="margin-top:10px;">
                    <div class="rb-section-hdr" onclick="rbToggle(this)"><span>Targets</span><span class="arr">&#9660;</span></div>
                    <div class="rb-section-body">
                        <div class="job-targets">
                            <?php foreach (($j['targets'] ?? []) as $ti => $t): ?>
                            <div class="rb-card" data-id="<?= htmlspecialchars($t['id'] ?? '') ?>">
                                <div class="rb-card-hdr">
                                    <span class="rb-card-title">Target #<?= $ti+1 ?></span>
                                    <div style="display:flex;gap:4px;">
                                        <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbInitRepo(this)">Init Repo</button>
                                        <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbTestTarget(this)">Test</button>
                                        <button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest('.rb-card').remove()">Remove</button>
                                    </div>
                                </div>
                                <div class="rb-row">
                                    <label>Type:</label>
                                    <select class="target-type" onchange="rbTargetTypeChange(this)">
                                        <option value="local" <?= ($t['type'] ?? '') === 'local' ? 'selected' : '' ?>>Local Path</option>
                                        <option value="sftp" <?= ($t['type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                                        <option value="s3" <?= ($t['type'] ?? '') === 's3' ? 'selected' : '' ?>>S3 / Minio</option>
                                        <option value="b2" <?= ($t['type'] ?? '') === 'b2' ? 'selected' : '' ?>>Backblaze B2</option>
                                        <option value="rest" <?= ($t['type'] ?? '') === 'rest' ? 'selected' : '' ?>>REST Server</option>
                                        <option value="rclone" <?= ($t['type'] ?? '') === 'rclone' ? 'selected' : '' ?>>Rclone</option>
                                    </select>
                                </div>
                                <div class="rb-row">
                                    <label>Repository URL:</label>
                                    <input type="text" class="target-url" value="<?= htmlspecialchars($t['url'] ?? '') ?>" placeholder="<?= ($t['type'] ?? 'local') === 'local' ? '/mnt/disks/backup/restic' : 'sftp://user@host:/path' ?>"<?= ($t['type'] ?? 'local') === 'local' ? ' data-picktree="dir"' : '' ?>>
                                </div>
                                <div class="rb-row">
                                    <label>Name:</label>
                                    <input type="text" class="target-name" value="<?= htmlspecialchars($t['name'] ?? '') ?>" placeholder="e.g. Hetzner Cloud">
                                </div>
                                <div class="rb-row">
                                    <label>Optional Excludes:</label>
                                    <select class="target-opt-exc"><option value="0" <?= !($t['use_optional_excludes'] ?? false) ? 'selected' : '' ?>>No</option><option value="1" <?= ($t['use_optional_excludes'] ?? false) ? 'selected' : '' ?>>Yes</option></select>
                                </div>
                                <div class="rb-row">
                                    <label>Enabled:</label>
                                    <select class="target-enabled"><option value="1" <?= ($t['enabled'] ?? true) ? 'selected' : '' ?>>Yes</option><option value="0" <?= !($t['enabled'] ?? true) ? 'selected' : '' ?>>No</option></select>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbAddTarget(this)">+ Add Target</button>
                    </div>
                </div>

                <!-- ZFS SNAPSHOTS -->
                <div class="rb-section">
                    <div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>ZFS Snapshots</span><span class="arr">&#9660;</span></div>
                    <div class="rb-section-body hidden">
                        <p style="color:var(--text-muted);margin:0 0 10px;">Create ZFS snapshots before backup for data consistency. Cleaned up automatically after backup.</p>
                        <div class="rb-row">
                            <label>Enable Snapshots:</label>
                            <select class="zfs-enabled"><option value="0" <?= !($j['zfs']['enabled'] ?? false) ? 'selected' : '' ?>>Disabled</option><option value="1" <?= ($j['zfs']['enabled'] ?? false) ? 'selected' : '' ?>>Enabled</option></select>
                        </div>
                        <div class="rb-row">
                            <label>Recursive:</label>
                            <select class="zfs-recursive"><option value="1" <?= ($j['zfs']['recursive'] ?? true) ? 'selected' : '' ?>>Yes (all child datasets)</option><option value="0" <?= !($j['zfs']['recursive'] ?? true) ? 'selected' : '' ?>>No</option></select>
                        </div>
                        <div class="rb-row">
                            <label>Snapshot Prefix:</label>
                            <input type="text" class="zfs-prefix" value="<?= htmlspecialchars($j['zfs']['snapshot_prefix'] ?? 'restic-backup') ?>" placeholder="restic-backup" style="max-width:200px;">
                        </div>
                        <div style="margin-top:8px;">
                            <label style="font-weight:bold;display:block;margin-bottom:6px;">Datasets:</label>
                            <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbLoadDatasets(this)" style="margin-bottom:8px;">Load Available Datasets</button>
                            <div class="zfs-ds-picker rb-ds-list" style="display:none;"></div>
                            <div class="zfs-ds-manual">
                                <?php foreach (($j['zfs']['datasets'] ?? []) as $ds): ?>
                                <div class="rb-row"><input type="text" class="zfs-dataset" value="<?= htmlspecialchars($ds) ?>" placeholder="cache/appdata" style="flex:1;"><button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.parentElement.remove()">X</button></div>
                                <?php endforeach; ?>
                            </div>
                            <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbAddDatasetInput(this)" style="margin-top:4px;">+ Add Manually</button>
                        </div>
                    </div>
                </div>

                <!-- SOURCES -->
                <div class="rb-section">
                    <div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Source Directories</span><span class="arr">&#9660;</span></div>
                    <div class="rb-section-body hidden">
                        <p style="color:var(--text-muted);margin:0 0 10px;">Directories to include in the backup. Click into a path field to browse.</p>
                        <div class="job-sources">
                            <?php foreach (($j['sources'] ?? []) as $si => $s): ?>
                            <div class="rb-card" data-id="<?= htmlspecialchars($s['id'] ?? '') ?>">
                                <div class="rb-card-hdr">
                                    <span class="rb-card-title">Source #<?= $si+1 ?></span>
                                    <button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest('.rb-card').remove()">Remove</button>
                                </div>
                                <div class="rb-row">
                                    <label>Path:</label>
                                    <input type="text" class="source-path" value="<?= htmlspecialchars($s['path'] ?? '') ?>" placeholder="/mnt/user/appdata" data-picktree="dir">
                                </div>
                                <div class="rb-row">
                                    <label>Label:</label>
                                    <input type="text" class="source-label" value="<?= htmlspecialchars($s['label'] ?? '') ?>" placeholder="appdata (folder name in backup)">
                                </div>
                                <div class="rb-row">
                                    <label>Enabled:</label>
                                    <select class="source-enabled"><option value="1" <?= ($s['enabled'] ?? true) ? 'selected' : '' ?>>Yes</option><option value="0" <?= !($s['enabled'] ?? true) ? 'selected' : '' ?>>No</option></select>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbAddSource(this)">+ Add Source</button>
                    </div>
                </div>

                <!-- EXCLUDES -->
                <div class="rb-section">
                    <div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Exclude Patterns</span><span class="arr">&#9660;</span></div>
                    <div class="rb-section-body hidden">
                        <p style="color:var(--text-muted);margin:0 0 10px;">One pattern per line. <a href="https://restic.readthedocs.io/en/stable/040_backup.html#excluding-files" target="_blank" style="color:var(--accent);">Restic exclude syntax</a></p>
                        <div style="margin-bottom:10px;">
                            <label style="font-weight:bold;display:block;margin-bottom:4px;">Global Excludes (all targets):</label>
                            <textarea class="rb-excludes job-exc-global" placeholder="e.g.&#10;.Trash&#10;**/.Recycle.Bin/**&#10;*.DS_Store"><?= htmlspecialchars(implode("\n", $j['excludes']['global'] ?? [])) ?></textarea>
                        </div>
                        <div>
                            <label style="font-weight:bold;display:block;margin-bottom:4px;">Optional Excludes (only targets with "Optional Excludes: Yes"):</label>
                            <textarea class="rb-excludes job-exc-optional" placeholder="e.g.&#10;**/jellyfin/cache/**&#10;**/Plex Media Server/Cache/**"><?= htmlspecialchars(implode("\n", $j['excludes']['optional'] ?? [])) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- RETENTION -->
                <div class="rb-section">
                    <div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Retention Policy</span><span class="arr">&#9660;</span></div>
                    <div class="rb-section-body hidden">
                        <p style="color:var(--text-muted);margin:0 0 10px;">How many snapshots to keep. Set to 0 to disable a rule.</p>
                        <div class="rb-retention-grid">
                            <div class="rb-row"><label>Keep Daily:</label><input type="number" class="ret-daily" value="<?= (int)($j['retention']['keep_daily'] ?? 7) ?>" min="0"></div>
                            <div class="rb-row"><label>Keep Weekly:</label><input type="number" class="ret-weekly" value="<?= (int)($j['retention']['keep_weekly'] ?? 4) ?>" min="0"></div>
                            <div class="rb-row"><label>Keep Monthly:</label><input type="number" class="ret-monthly" value="<?= (int)($j['retention']['keep_monthly'] ?? 0) ?>" min="0"></div>
                            <div class="rb-row"><label>Keep Yearly:</label><input type="number" class="ret-yearly" value="<?= (int)($j['retention']['keep_yearly'] ?? 0) ?>" min="0"></div>
                        </div>
                    </div>
                </div>

                <!-- SCHEDULE & CHECK -->
                <div class="rb-section">
                    <div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Schedule &amp; Integrity Check</span><span class="arr">&#9660;</span></div>
                    <div class="rb-section-body hidden">
                        <div class="rb-row"><label>Schedule:</label><select class="sched-enabled"><option value="0" <?= !($j['schedule']['enabled'] ?? false) ? 'selected' : '' ?>>Disabled</option><option value="1" <?= ($j['schedule']['enabled'] ?? false) ? 'selected' : '' ?>>Enabled</option></select></div>
                        <div class="rb-row">
                            <label>Preset:</label>
                            <select class="sched-preset" onchange="rbApplyPreset(this)">
                                <option value="">Custom</option>
                                <option value="0 3 * * *">Daily 3:00 AM</option>
                                <option value="0 4 * * *">Daily 4:00 AM</option>
                                <option value="0 2 * * 0">Weekly Sunday 2 AM</option>
                                <option value="0 2 * * 6">Weekly Saturday 2 AM</option>
                            </select>
                        </div>
                        <div class="rb-row"><label>Cron:</label><input type="text" class="sched-cron" value="<?= htmlspecialchars($j['schedule']['cron'] ?? '') ?>" placeholder="0 3 * * *" style="max-width:180px;"><span class="rb-hint">minute hour day month weekday</span></div>
                        <hr style="border-color:var(--border);margin:10px 0;">
                        <div class="rb-row"><label>Integrity Check:</label><select class="chk-enabled"><option value="0" <?= !($j['check']['enabled'] ?? false) ? 'selected' : '' ?>>Disabled</option><option value="1" <?= ($j['check']['enabled'] ?? false) ? 'selected' : '' ?>>Enabled</option></select></div>
                        <div class="rb-row"><label>Data Percentage:</label><input type="text" class="chk-pct" value="<?= htmlspecialchars($j['check']['percentage'] ?? '2%') ?>" placeholder="2%" style="max-width:80px;"></div>
                        <div class="rb-row"><label>Check When:</label><select class="chk-sched"><option value="sunday" <?= ($j['check']['schedule'] ?? '') === 'sunday' ? 'selected' : '' ?>>Every Sunday</option><option value="monthly" <?= ($j['check']['schedule'] ?? '') === 'monthly' ? 'selected' : '' ?>>First of Month</option><option value="always" <?= ($j['check']['schedule'] ?? '') === 'always' ? 'selected' : '' ?>>Every Backup</option></select></div>
                        <hr style="border-color:var(--border);margin:10px 0;">
                        <div class="rb-row"><label>Tags:</label><input type="text" class="job-tags" value="<?= htmlspecialchars($j['tags'] ?? '') ?>" placeholder="e.g. unraid,daily"></div>
                        <div class="rb-row"><label>Max Retries:</label><input type="number" class="job-retries" value="<?= (int)($j['max_retries'] ?? 3) ?>" min="1" max="10" style="max-width:70px;"></div>
                        <div class="rb-row"><label>Retry Wait (s):</label><input type="number" class="job-retry-wait" value="<?= (int)($j['retry_wait'] ?? 30) ?>" min="5" max="600" style="max-width:70px;"></div>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($jobs)): ?>
        <p style="color:var(--text-muted);text-align:center;padding:20px;">No backup jobs configured. Click "+ Add Job" to create one.</p>
        <?php endif; ?>
    </div>
</div>

<!-- SAVE -->
<div style="margin:14px 0;display:flex;gap:10px;align-items:center;">
    <button class="rb-btn rb-btn-accent" onclick="rbSave()" style="padding:8px 28px;font-size:1.05em;">Save Configuration</button>
    <span id="rb-save-msg" style="display:none;"></span>
</div>

</div><!-- /rb-wrap -->

<script src="<?autov('/plugins/restic-backup/assets/script.js')?>"></script>
<script>rbTogglePw(); rbInitPickTree();</script>
