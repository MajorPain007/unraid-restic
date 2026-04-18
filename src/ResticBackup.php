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
:root {
  /* Unraid orange for primary actions */
  --accent: #f08c00; --accent-hover: #d97c00;
  --green: #56d364; --red: #f85149; --yellow: #e3b341;
  /* ZFS-plugin dark theme */
  --bg-card: #1e2329;
  --bg-secondary: #161b22;
  --bg-hover: #21262d;
  --bg-log: #0d1117;
  --border: #3a4049;
  --border-inner: #30363d;
  --border-row: #21262d;
  --text: #c9d1d9;
  --text-muted: #8b949e;
  --text-label: #9ba5b5;
}
.rb-wrap { width: 100%; }
/* Two-column grid (matches ZFS plugin layout) */
.rb-grid { display: grid; grid-template-columns: minmax(0,52fr) minmax(0,48fr); gap: 0 18px; align-items: start; }
@media (max-width: 1100px) { .rb-grid { grid-template-columns: 1fr; } }
.rb-col { min-width: 0; }
.rb-section { margin-bottom: 12px; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
.rb-section-hdr { background: var(--bg-card); padding: 10px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 13px; user-select: none; color: var(--text); }
.rb-section-hdr:hover { background: var(--bg-hover); }
.rb-section-hdr .arr { transition: transform .2s; font-size: .8em; color: var(--text-muted); }
.rb-section-hdr.closed .arr { transform: rotate(-90deg); }
.rb-section-body { padding: 14px 16px; }
.rb-section-body.hidden { display: none; }
.rb-row { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; flex-wrap: wrap; min-height: 30px; }
.rb-row label { min-width: 170px; font-size: 13px; color: var(--text-label); flex-shrink: 0; }
.rb-row input[type="text"], .rb-row input[type="number"], .rb-row input[type="password"], .rb-row select {
  flex: 1; min-width: 180px; max-width: 480px; padding: 4px 8px;
  background: var(--bg-secondary); color: var(--text); border: 1px solid var(--border-inner); border-radius: 4px; font-size: 13px;
}
.rb-row input:focus, .rb-row select:focus { outline: none; border-color: var(--accent); }
/* URL split display */
.rb-url-wrap { display:flex; flex:1; min-width:180px; max-width:480px; }
.rb-url-pfx { background:var(--bg-card); border:1px solid var(--border-inner); border-right:none; padding:4px 8px; border-radius:4px 0 0 4px; color:var(--text-muted); white-space:nowrap; font-family:monospace; font-size:.88em; display:flex; align-items:center; user-select:none; }
.rb-url-wrap .target-url { border-radius:0 4px 4px 0; flex:1; min-width:0; max-width:none; }
/* Snapshot list table */
.rb-snap-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; }
.rb-snap-table th { text-align:left; padding:5px 8px; background:var(--bg-secondary); border-bottom:1px solid var(--border-inner); color:var(--text-muted); font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
.rb-snap-table td { padding:4px 8px; border-bottom:1px solid var(--border-row); vertical-align:middle; font-size:12px; }
.rb-snap-table tr:last-child td { border-bottom:none; }
.rb-snap-table tr:hover td { background:var(--bg-hover); }
.rb-hint { color: var(--text-muted); font-size: 11px; width: 100%; padding-left: 180px; }

/* Buttons */
.rb-btn { padding: 6px 14px; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 600; color: #fff !important; transition: background .15s; }
.rb-btn-accent { background: #f08c00 !important; } .rb-btn-accent:hover { background: #d97c00 !important; }
.rb-btn-green  { background: #238636 !important; } .rb-btn-green:hover  { background: #2ea043 !important; }
.rb-btn-red    { background: #b91c1c !important; } .rb-btn-red:hover    { background: #f85149 !important; }
.rb-btn-gray   { background: #21262d !important; border:1px solid #444d56; color: #c9d1d9 !important; } .rb-btn-gray:hover { background: #30363d !important; }
.rb-btn-sm { padding: 2px 9px; font-size: 11px; }
.rb-btn:disabled { opacity: .5; cursor: default; }

/* Job Tabs */
.rb-job-tabs { display: flex; gap: 4px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.rb-job-tab { padding: 6px 16px; background: var(--bg-secondary); border: 1px solid var(--border); border-bottom: none; border-radius: 6px 6px 0 0; cursor: pointer; color: var(--text-muted); font-weight: 600; font-size: 13px; }
.rb-job-tab.active { background: var(--bg-card); color: var(--accent); border-color: var(--accent); }
.rb-job-tab:hover { color: var(--text); }

/* Cards */
.rb-card { background: var(--bg-secondary); border: 1px solid var(--border-inner); border-radius: 6px; padding: 12px; margin-bottom: 8px; }
.rb-card-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.rb-card-title { font-weight: 600; color: var(--accent); font-size: 12px; }

/* Log */
#rb-log { background: var(--bg-log); color: #56d364; font-family: 'Consolas','Monaco',monospace; font-size: 12px; padding: 10px; height: 350px; overflow-y: auto; white-space: pre-wrap; border: 1px solid var(--border-inner); border-radius: 4px; line-height: 1.6; }

/* Status */
.rb-badge { display: inline-block; padding: 3px 11px; border-radius: 12px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.rb-badge-idle { background: #1a4a2744; color: var(--green); }
.rb-badge-run  { background: #1f6feb44; color: #58a6ff; }

/* Excludes */
textarea.rb-excludes { width: 100%; max-width: 680px; height: 130px; font-family: monospace; font-size: 12px; background: var(--bg-secondary); color: var(--text); border: 1px solid var(--border-inner); padding: 8px; resize: vertical; border-radius: 4px; }
textarea.rb-excludes:focus { outline: none; border-color: var(--accent); }

/* Inline directory / file browser (source + password picker) */
.rb-tree { border: 1px solid var(--border-inner); border-radius: 4px; background: var(--bg-log); max-height: 320px; overflow-y: auto; margin-top: 4px; margin-bottom: 8px; max-width: 560px; }
.rb-tree-item { padding: 5px 10px; cursor: pointer; display: flex; align-items: center; gap: 6px; color: var(--text); font-size: 12px; }
.rb-tree-item:hover { background: var(--bg-hover); }
.rb-tree-item.rb-tree-up { color: var(--accent); font-weight: bold; }
.rb-tree-item.rb-tree-file { color: var(--text); }
.rb-tree-item.picked { background: rgba(240,140,0,.18); outline: 1px solid var(--accent); }
.rb-tree-item .rb-tree-icon { width: 16px; text-align: center; color: var(--yellow); flex-shrink: 0; }
.rb-tree-item .rb-tree-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.rb-tree-item .rb-tree-size { color: var(--text-muted); font-size: 11px; white-space: nowrap; margin-left: auto; padding-left: 6px; flex-shrink: 0; }
.rb-tree-hdr { padding: 6px 10px; border-bottom: 1px solid var(--border-inner); display: flex; flex-wrap: wrap; justify-content: flex-start; align-items: center; gap: 6px; background: var(--bg-secondary); font-size: 12px; }
.rb-tree-hdr .rb-tree-path { font-family: monospace; color: var(--accent); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rb-tree-hdr .rb-tree-roots { display: flex; gap: 4px; flex-shrink: 0; }

/* Scroll wrapper for snapshot list table */
.snapshots-scroll { max-height: 420px; overflow-y: auto; border: 1px solid var(--border-inner); border-radius: 4px; }
.snapshots-scroll table { margin-top: 0 !important; }
.snapshots-scroll thead th { position: sticky; top: 0; z-index: 2; }
.snapshots-scroll::-webkit-scrollbar { width: 8px; }
.snapshots-scroll::-webkit-scrollbar-track { background: var(--bg-secondary); }
.snapshots-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
.snapshots-scroll::-webkit-scrollbar-thumb:hover { background: var(--accent); }
.snapshots-scroll { scrollbar-width: thin; scrollbar-color: var(--border) var(--bg-secondary); }

/* Dataset picker */
.rb-ds-list { max-height: 250px; overflow-y: auto; border: 1px solid var(--border-inner); border-radius: 4px; padding: 6px; background: var(--bg-log); }
.rb-ds-item { padding: 3px 6px; display: flex; align-items: center; gap: 6px; font-size: 12px; }
.rb-ds-item.child { padding-left: 24px; }
.rb-ds-item label { min-width: auto; font-weight: normal; cursor: pointer; }

/* Retention row */
.rb-retention-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
.rb-retention-grid label { min-width: auto; }
.rb-retention-grid input { width: 60px; }

/* Snapshot file browser */
.snap-crumb { color: #58a6ff; cursor: pointer; font-size: 12px; text-decoration: underline; }
.snap-crumb:hover { color: var(--accent-hover); }
.snap-crumb-sep { color: #555; font-size: 12px; }
.snap-file-hdr { padding: 5px 10px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-inner); font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; font-weight: 600; }
.snap-file-row { display: flex; align-items: center; gap: 7px; padding: 4px 10px; border-bottom: 1px solid var(--border-row); font-size: 12px; color: var(--text); }
.snap-file-row:last-child { border-bottom: none; }
.snap-file-row:hover { background: var(--bg-hover); }
.snap-file-icon { width: 18px; text-align: center; flex-shrink: 0; }
.snap-file-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.snap-dir-name { color: #e3b341; cursor: pointer; }
.snap-dir-name:hover { text-decoration: underline; }
.snap-size { color: var(--text-muted); font-size: 11px; white-space: nowrap; margin-left: auto; padding-right: 4px; }
.snap-enter-btn { background: none; border: none; color: #58a6ff; cursor: pointer; padding: 0 4px; font-size: 13px; flex-shrink: 0; }
.snap-enter-btn:hover { color: var(--accent-hover); }
.snap-check { accent-color: #58a6ff; flex-shrink: 0; cursor: pointer; }

/* =====================================================================
 * RESPONSIVE DESIGN
 * =================================================================== */
/* Tablet: collapse 2-col grid earlier already handled at 1100px.
   Below tablet, tighten spacing, let labels stack above inputs. */
@media (max-width: 768px) {
    .rb-grid { gap: 0; }
    .rb-section-body { padding: 10px 12px; }
    .rb-section-hdr { padding: 9px 12px; font-size: 12.5px; }

    /* Stack label above input for more horizontal room */
    .rb-row { flex-direction: column; align-items: stretch; gap: 4px; min-height: 0; margin-bottom: 10px; }
    .rb-row label { min-width: 0; width: 100%; }
    .rb-row input[type="text"], .rb-row input[type="number"],
    .rb-row input[type="password"], .rb-row select {
        width: 100%; max-width: 100%; min-width: 0; flex: 1 1 auto;
    }
    .rb-url-wrap { max-width: 100%; }
    /* Hints live on their own line below the input */
    .rb-hint { padding-left: 0; margin-top: 2px; }

    /* Retention grid: 2 columns on phones */
    .rb-retention-grid { grid-template-columns: repeat(2, 1fr); }

    /* File/dir picker fills width */
    .rb-tree { max-width: 100%; }
    .rb-tree-hdr { flex-wrap: wrap; }

    /* Snapshot browser full-width */
    #snap-browser-list { max-width: 100% !important; }

    /* Snapshot table: hide the Tags column to save horizontal space */
    .rb-snap-table th:nth-child(4),
    .rb-snap-table td:nth-child(4) { display: none; }

    /* Job tabs: allow wrapping instead of horizontal overflow */
    .rb-job-tabs { gap: 3px; }
    .rb-job-tab { padding: 5px 10px; font-size: 12px; }

    /* Log area: use more of the viewport height */
    #rb-log { height: 260px; }

    /* Controls row: let the job-select + refresh wrap to its own line */
    .rb-row > #rb-job-select,
    .rb-row > #btn-start,
    .rb-row > #btn-stop { width: 100%; }
}

@media (max-width: 480px) {
    .rb-wrap { font-size: 13px; }
    .rb-section-hdr { padding: 8px 10px; font-size: 12px; }
    .rb-section-body { padding: 8px 10px; }
    .rb-card { padding: 9px; }

    /* Retention: single column */
    .rb-retention-grid { grid-template-columns: 1fr; }

    /* Snapshot table: also hide hostname to keep ID + Date + action readable */
    .rb-snap-table th:nth-child(3),
    .rb-snap-table td:nth-child(3) { display: none; }

    /* Buttons in card header: shrink */
    .rb-card-hdr { flex-wrap: wrap; gap: 4px; }
    .rb-btn-sm { padding: 2px 6px; font-size: 10.5px; }
    .rb-btn { padding: 5px 10px; font-size: 12px; }

    /* Save-button row: stretch */
    .rb-wrap > div[style*="margin:14px 0"] { flex-direction: column; align-items: stretch !important; gap: 8px !important; }
    .rb-wrap > div[style*="margin:14px 0"] .rb-btn { width: 100%; }

    #rb-log { height: 220px; font-size: 11px; }
    textarea.rb-excludes { height: 110px; }
}
</style>

<div class="rb-wrap">

<div class="rb-grid">
<!-- ════ LEFT COLUMN: Jobs ════ -->
<div class="rb-col">

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
                            <?php
                            $ttype  = $t['type'] ?? 'local';
                            $tcreds = $t['credentials'] ?? [];
                            $tpfxMap = ['sftp'=>'sftp://','s3'=>'s3:','b2'=>'b2:','rest'=>'rest:','rclone'=>'rclone:'];
                            $tpfx   = $tpfxMap[$ttype] ?? '';
                            $tfull  = $t['url'] ?? '';
                            // Strip prefix repeatedly in case a previous bug produced a doubled prefix
                            // like "sftp://sftp://host/path" — we want only the bare suffix in the input.
                            $tsuffix = $tfull;
                            if ($tpfx !== '') {
                                while (strncmp($tsuffix, $tpfx, strlen($tpfx)) === 0) {
                                    $tsuffix = substr($tsuffix, strlen($tpfx));
                                }
                            }
                            // Password: prefer target-level, fall back to general (migration path)
                            $tpwmode   = $t['password_mode']   ?? ($config['general']['password_mode']   ?? 'file');
                            $tpwfile   = $t['password_file']   ?? ($config['general']['password_file']   ?? '');
                            $tpwinline = $t['password_inline'] ?? ($config['general']['password_inline'] ?? '');
                            ?>
                            <div class="rb-card" data-id="<?= htmlspecialchars($t['id'] ?? '') ?>" data-type="<?= htmlspecialchars($ttype) ?>">
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
                                    <div class="rb-url-wrap">
                                        <span class="rb-url-pfx"<?= $ttype === 'local' ? ' style="display:none;"' : '' ?>><?= htmlspecialchars($tpfx) ?></span>
                                        <input type="text" class="target-url" value="<?= htmlspecialchars($tsuffix) ?>" placeholder="<?= $ttype === 'local' ? '/mnt/disks/backup/restic' : '' ?>"<?= $ttype === 'local' ? ' data-picktree="dir"' : ' style="border-radius:0 3px 3px 0;"' ?>>
                                    </div>
                                </div>
                                <!-- Credentials per type -->
                                <div class="target-creds target-creds-sftp"<?= $ttype !== 'sftp' ? ' style="display:none;"' : '' ?>>
                                    <div class="rb-hint" style="padding-left:0;margin-bottom:6px;">SFTP uses SSH key auth — configure in <code>/root/.ssh/config</code>.</div>
                                    <div class="rb-row">
                                        <label>Accept New Host Key:</label>
                                        <select class="cred-sftp-hostkey">
                                            <option value="1" <?= ($tcreds['sftp_accept_hostkey'] ?? true) ? 'selected' : '' ?>>Yes (StrictHostKeyChecking=accept-new)</option>
                                            <option value="0" <?= !($tcreds['sftp_accept_hostkey'] ?? true) ? 'selected' : '' ?>>No (manual known_hosts required)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="target-creds target-creds-s3"<?= $ttype !== 's3' ? ' style="display:none;"' : '' ?>>
                                    <div class="rb-row"><label>Access Key ID:</label><input type="text" class="cred-s3-key" value="<?= htmlspecialchars($tcreds['aws_access_key_id'] ?? '') ?>" placeholder="AKIAIOSFODNN7EXAMPLE"></div>
                                    <div class="rb-row"><label>Secret Access Key:</label><input type="password" class="cred-s3-secret" value="<?= htmlspecialchars($tcreds['aws_secret_access_key'] ?? '') ?>" placeholder="wJalrXUtnFEMI/K7MDENG..."></div>
                                    <div class="rb-row"><label>Region:</label><input type="text" class="cred-s3-region" value="<?= htmlspecialchars($tcreds['aws_region'] ?? '') ?>" placeholder="us-east-1 (optional)"></div>
                                </div>
                                <div class="target-creds target-creds-b2"<?= $ttype !== 'b2' ? ' style="display:none;"' : '' ?>>
                                    <div class="rb-row"><label>Account ID:</label><input type="text" class="cred-b2-id" value="<?= htmlspecialchars($tcreds['b2_account_id'] ?? '') ?>" placeholder="B2 Account ID"></div>
                                    <div class="rb-row"><label>Application Key:</label><input type="password" class="cred-b2-key" value="<?= htmlspecialchars($tcreds['b2_account_key'] ?? '') ?>" placeholder="B2 Application Key"></div>
                                </div>
                                <div class="target-creds target-creds-rest"<?= $ttype !== 'rest' ? ' style="display:none;"' : '' ?>>
                                    <div class="rb-row"><label>Username:</label><input type="text" class="cred-rest-user" value="<?= htmlspecialchars($tcreds['rest_user'] ?? '') ?>" placeholder="optional"></div>
                                    <div class="rb-row"><label>Password:</label><input type="password" class="cred-rest-pass" value="<?= htmlspecialchars($tcreds['rest_pass'] ?? '') ?>" placeholder="optional"></div>
                                </div>
                                <div class="target-creds target-creds-rclone"<?= $ttype !== 'rclone' ? ' style="display:none;"' : '' ?>>
                                    <div class="rb-hint" style="padding-left:0;margin-bottom:6px;">Rclone uses its own config (<code>rclone config</code>). No credentials needed here.</div>
                                </div>
                                <!-- Repository password (per target) -->
                                <div class="rb-row">
                                    <label>Password Mode:</label>
                                    <select class="target-pw-mode" onchange="rbTargetPwToggle(this)">
                                        <option value="file" <?= $tpwmode === 'file' ? 'selected' : '' ?>>Password File</option>
                                        <option value="inline" <?= $tpwmode === 'inline' ? 'selected' : '' ?>>Inline Password</option>
                                    </select>
                                </div>
                                <div class="target-pw-file-row rb-row"<?= $tpwmode !== 'file' ? ' style="display:none;"' : '' ?>>
                                    <label>Password File:</label>
                                    <input type="text" class="target-pw-file" value="<?= htmlspecialchars($tpwfile) ?>" placeholder="Path to password file …" data-picktree="file" autocomplete="off">
                                </div>
                                <div class="target-pw-inline-row rb-row"<?= $tpwmode !== 'inline' ? ' style="display:none;"' : '' ?>>
                                    <label>Password:</label>
                                    <input type="password" class="target-pw-inline" value="<?= htmlspecialchars($tpwinline) ?>" placeholder="Repository password" autocomplete="off">
                                </div>
                                <div class="rb-row">
                                    <label>Name:</label>
                                    <input type="text" class="target-name" value="<?= htmlspecialchars($t['name'] ?? '') ?>" placeholder="e.g. Hetzner Cloud">
                                </div>
                                <div class="rb-row">
                                    <label>Upload Limit (KiB/s):</label>
                                    <input type="number" class="target-limit-up" value="<?= (int)($t['limit_upload'] ?? 0) ?>" min="0" placeholder="0 = unlimited" style="max-width:140px;">
                                    <span class="rb-hint">0 = unlimited</span>
                                </div>
                                <div class="rb-row">
                                    <label>Download Limit (KiB/s):</label>
                                    <input type="number" class="target-limit-down" value="<?= (int)($t['limit_download'] ?? 0) ?>" min="0" placeholder="0 = unlimited" style="max-width:140px;">
                                    <span class="rb-hint">0 = unlimited (restores &amp; checks)</span>
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
                            <select class="zfs-recursive" onchange="rbSyncDsToManual(this.closest('.rb-job-panel'))">
                                <option value="1" <?= ($j['zfs']['recursive'] ?? true) ? 'selected' : '' ?>>Yes — snapshot parent + all children</option>
                                <option value="0" <?= !($j['zfs']['recursive'] ?? true) ? 'selected' : '' ?>>No — only selected datasets</option>
                            </select>
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
                                <div class="rb-row" style="flex-wrap:nowrap;">
                                    <input type="text" class="zfs-dataset" value="<?= htmlspecialchars($ds) ?>" placeholder="cache/appdata" style="flex:1;min-width:0;opacity:.7;" readonly>
                                    <?php if ($j['zfs']['recursive'] ?? true): ?>
                                    <span style="white-space:nowrap;color:var(--accent);font-size:.8em;flex-shrink:0;">recursive</span>
                                    <?php endif; ?>
                                    <button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.parentElement.remove()">X</button>
                                </div>
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
                        <div class="rb-row" style="margin-bottom:10px;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;min-width:0;">
                                <input type="checkbox" class="job-backup-boot" autocomplete="off" <?= !empty($j['backup_boot']) ? 'checked' : '' ?>>
                                <span>Include <code>/boot</code> (Unraid USB key)</span>
                            </label>
                        </div>
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

                <!-- PRE/POST HOOKS -->
                <div class="rb-section">
                    <div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Pre/Post Backup Hooks</span><span class="arr">&#9660;</span></div>
                    <div class="rb-section-body hidden">
                        <p style="color:var(--text-muted);margin:0 0 10px;">
                            Shell commands run around the backup. <strong>Pre-hooks</strong> run as the very first thing in the job (before ZFS snapshots, before restic starts). <strong>Post-hooks</strong> run after all targets are processed — even if the backup failed. Scripts are materialized on save under <code>/boot/config/plugins/restic-backup/hooks/&lt;jobid&gt;/</code> and can be inspected or edited there.
                        </p>
                        <p style="color:var(--text-muted);margin:0 0 10px;font-size:.85em;">
                            Available env vars: <code>RESTIC_JOB_ID</code>, <code>RESTIC_JOB_NAME</code>, <code>RESTIC_PHASE</code>, <code>RESTIC_HOSTNAME</code>. Post-hooks additionally get <code>RESTIC_STATUS</code> (<code>success</code>/<code>partial</code>/<code>failed</code>), <code>RESTIC_OK_COUNT</code>, <code>RESTIC_FAIL_COUNT</code>.
                        </p>

                        <!-- Pre-Backup Hooks -->
                        <div style="margin-bottom:14px;">
                            <strong style="display:block;margin:6px 0;color:var(--text-muted);font-size:.88em;">PRE-BACKUP HOOKS (run first)</strong>
                            <div class="job-hooks-pre">
                                <?php foreach (($j['hooks']['pre_backup'] ?? []) as $hi => $h): ?>
                                <div class="rb-card hook-card" data-id="<?= htmlspecialchars($h['id'] ?? '') ?>" data-phase="pre">
                                    <div class="rb-card-hdr">
                                        <span class="rb-card-title">Pre-Hook #<?= $hi+1 ?></span>
                                        <button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest('.rb-card').remove()">Remove</button>
                                    </div>
                                    <div class="rb-row"><label>Name:</label><input type="text" class="hook-name" value="<?= htmlspecialchars($h['name'] ?? '') ?>" placeholder="e.g. MariaDB dump"></div>
                                    <div class="rb-row" style="align-items:flex-start;"><label>Command:</label><textarea class="hook-command" rows="5" spellcheck="false" style="flex:1;font-family:monospace;font-size:.88em;" placeholder="docker exec mariadb sh -c 'mariadb-dump …' | gzip > /mnt/user/appdata/_db_dumps/mariadb.sql.gz"><?= htmlspecialchars($h['command'] ?? '') ?></textarea></div>
                                    <div class="rb-row"><label>Enabled:</label><select class="hook-enabled"><option value="1" <?= ($h['enabled'] ?? true) ? 'selected' : '' ?>>Yes</option><option value="0" <?= !($h['enabled'] ?? true) ? 'selected' : '' ?>>No</option></select></div>
                                    <div class="rb-row"><label>Timeout (s):</label><input type="number" class="hook-timeout" value="<?= (int)($h['timeout'] ?? 3600) ?>" min="5" max="86400" style="max-width:100px;"></div>
                                    <div class="rb-row"><label>On Error:</label><select class="hook-onerror"><option value="abort" <?= ($h['on_error'] ?? 'continue') === 'abort' ? 'selected' : '' ?>>Abort job</option><option value="continue" <?= ($h['on_error'] ?? 'continue') === 'continue' ? 'selected' : '' ?>>Continue</option></select></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbAddHook(this,'pre')">+ Add Pre-Hook</button>
                        </div>

                        <!-- Post-Backup Hooks -->
                        <div>
                            <strong style="display:block;margin:6px 0;color:var(--text-muted);font-size:.88em;">POST-BACKUP HOOKS (run last, always)</strong>
                            <div class="job-hooks-post">
                                <?php foreach (($j['hooks']['post_backup'] ?? []) as $hi => $h): ?>
                                <div class="rb-card hook-card" data-id="<?= htmlspecialchars($h['id'] ?? '') ?>" data-phase="post">
                                    <div class="rb-card-hdr">
                                        <span class="rb-card-title">Post-Hook #<?= $hi+1 ?></span>
                                        <button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest('.rb-card').remove()">Remove</button>
                                    </div>
                                    <div class="rb-row"><label>Name:</label><input type="text" class="hook-name" value="<?= htmlspecialchars($h['name'] ?? '') ?>" placeholder="e.g. ntfy notification"></div>
                                    <div class="rb-row" style="align-items:flex-start;"><label>Command:</label><textarea class="hook-command" rows="5" spellcheck="false" style="flex:1;font-family:monospace;font-size:.88em;" placeholder="curl -fsS -d &quot;$RESTIC_JOB_NAME: $RESTIC_STATUS&quot; https://ntfy.sh/mytopic"><?= htmlspecialchars($h['command'] ?? '') ?></textarea></div>
                                    <div class="rb-row"><label>Enabled:</label><select class="hook-enabled"><option value="1" <?= ($h['enabled'] ?? true) ? 'selected' : '' ?>>Yes</option><option value="0" <?= !($h['enabled'] ?? true) ? 'selected' : '' ?>>No</option></select></div>
                                    <div class="rb-row"><label>Timeout (s):</label><input type="number" class="hook-timeout" value="<?= (int)($h['timeout'] ?? 600) ?>" min="5" max="86400" style="max-width:100px;"></div>
                                    <div class="rb-row"><label>On Error:</label><select class="hook-onerror"><option value="continue" <?= ($h['on_error'] ?? 'continue') === 'continue' ? 'selected' : '' ?>>Continue (ignore)</option><option value="abort" <?= ($h['on_error'] ?? 'continue') === 'abort' ? 'selected' : '' ?>>Mark job failed</option></select></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbAddHook(this,'post')">+ Add Post-Hook</button>
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
                        <div class="rb-row"><label>Restore Verify:</label><select class="verify-enabled"><option value="0" <?= !($j['verify']['enabled'] ?? false) ? 'selected' : '' ?>>Disabled</option><option value="1" <?= ($j['verify']['enabled'] ?? false) ? 'selected' : '' ?>>Enabled</option></select></div>
                        <div class="rb-row">
                            <label>Verify File:</label>
                            <input type="text" class="verify-path" value="<?= htmlspecialchars($j['verify']['path'] ?? '') ?>" placeholder="/mnt/user/appdata/<app>/version.txt" data-picktree="file" autocomplete="off" style="flex:1;max-width:420px;">
                        </div>
                        <div class="rb-hint">After each successful target backup, restic restores this single file from the newest snapshot and byte-compares it with the live source. Mismatches trigger an alert notification. Pick a small, always-present file inside one of your source paths.</div>
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

</div><!-- /rb-col left -->

<!-- ════ RIGHT COLUMN: Status, Settings, Browse ════ -->
<div class="rb-col">

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
        <!-- Live backup progress (populated by polling /tmp/restic-progress-<jobid>.json) -->
        <div id="rb-progress" style="display:none;margin-top:8px;padding:8px 10px;background:var(--bg-secondary);border-radius:4px;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap;">
                <strong id="rb-progress-target" style="font-size:.9em;"></strong>
                <span id="rb-progress-stats" style="font-family:monospace;font-size:.85em;color:var(--text-muted);"></span>
            </div>
            <div style="height:10px;background:var(--bg-card);border-radius:5px;margin-top:6px;overflow:hidden;">
                <div id="rb-progress-bar" style="height:100%;width:0%;background:var(--accent);transition:width .3s;"></div>
            </div>
            <div id="rb-progress-file" style="font-family:monospace;font-size:.78em;color:var(--text-muted);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
        </div>
        <div class="rb-row" style="margin-top:8px;gap:6px;flex-wrap:wrap;">
            <button id="btn-start" class="rb-btn rb-btn-accent" onclick="rbStartBackup()" <?= $running ? 'disabled' : '' ?>>&#9654; Start Backup</button>
            <button id="btn-stop" class="rb-btn rb-btn-red" onclick="rbStopBackup()" <?= !$running ? 'disabled' : '' ?>>&#9632; Stop</button>
            <select id="rb-job-select" onchange="rbRefreshLog()" title="Select which job to run manually" style="padding:4px 8px;font-size:13px;background:var(--bg-secondary);color:var(--text);border:1px solid var(--border-inner);border-radius:4px;">
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
            <label>Hostname:</label>
            <input type="text" id="cfg-hostname" value="<?= htmlspecialchars($config['general']['hostname'] ?? '') ?>" placeholder="e.g. my-unraid">
            <span class="rb-hint">Leave empty for system hostname.</span>
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
<!-- BROWSE BACKUPS -->
<!-- ================================================================ -->
<div class="rb-section">
    <div class="rb-section-hdr closed" onclick="rbToggle(this)">
        <span>Browse Backups</span><span class="arr">&#9660;</span>
    </div>
    <div class="rb-section-body hidden">
        <div class="rb-row">
            <label>Job:</label>
            <select id="snap-job-sel" onchange="rbSnapJobChange()">
                <option value="">-- Select Job --</option>
                <?php foreach ($jobs as $j): ?>
                <option value="<?= htmlspecialchars($j['id']) ?>"><?= htmlspecialchars($j['name'] ?: 'Unnamed') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rb-row" id="snap-target-row" style="display:none;">
            <label>Target:</label>
            <select id="snap-target-sel" onchange="rbSnapCtx.targetId=this.value"></select>
        </div>
        <div class="rb-row">
            <button class="rb-btn rb-btn-accent" id="btn-load-snaps" onclick="rbLoadSnapshots()">Load Snapshots</button>
        </div>

        <!-- Snapshot list -->
        <div id="snap-list" style="display:none;">
            <div class="snapshots-scroll">
                <table class="rb-snap-table">
                    <thead><tr>
                        <th style="width:28px;"><input type="checkbox" id="snap-check-all" onclick="rbSnapToggleAll(this)"></th>
                        <th>ID</th><th>Date</th><th>Hostname</th><th>Tags</th><th></th>
                    </tr></thead>
                    <tbody id="snap-tbody"></tbody>
                </table>
            </div>
            <!-- Batch actions -->
            <div id="snap-actions-bar" style="display:flex;gap:8px;align-items:center;margin-top:8px;padding:8px;background:var(--bg-secondary);border-radius:4px;flex-wrap:wrap;">
                <strong style="color:var(--text-muted);font-size:.88em;">Selected:</strong>
                <span id="snap-selected-count" style="font-family:monospace;">0</span>
                <button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbSnapDiff()">&#9723; Diff (2 selected)</button>
                <button class="rb-btn rb-btn-red rb-btn-sm" onclick="rbSnapForget(false)">&#128465; Forget</button>
                <button class="rb-btn rb-btn-red rb-btn-sm" onclick="rbSnapForget(true)">&#128465; Forget + Prune</button>
                <button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbSnapRewritePrompt()">&#9998; Rewrite (Exclude Path)</button>
                <div id="snap-action-msg" style="flex:1 1 100%;display:none;padding:6px 10px;font-size:.88em;border-radius:4px;background:var(--bg-card);"></div>
            </div>
            <!-- Diff output -->
            <div id="snap-diff-out" style="display:none;margin-top:8px;max-height:360px;overflow:auto;font-family:monospace;font-size:.88em;background:var(--bg-secondary);padding:10px;border-radius:4px;"></div>
        </div>

        <!-- Find files across snapshots -->
        <div id="snap-find" style="display:none;margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
            <strong style="color:var(--text-muted);font-size:.88em;display:block;margin-bottom:8px;">FIND FILES ACROSS SNAPSHOTS</strong>
            <div class="rb-row" style="align-items:flex-start;">
                <label>Pattern:</label>
                <input type="text" id="snap-find-pattern" placeholder="e.g. *.docx  or  photos/2024/**/*.jpg" style="flex:1;max-width:420px;" autocomplete="off" onkeydown="if(event.key==='Enter'){rbSnapFind();}">
                <button class="rb-btn rb-btn-accent" onclick="rbSnapFind()">&#128269; Search</button>
            </div>
            <div class="rb-row">
                <label>Options:</label>
                <label style="display:inline-flex;align-items:center;gap:4px;font-weight:normal;">
                    <input type="checkbox" id="snap-find-ci" checked> Ignore case
                </label>
                <label style="display:inline-flex;align-items:center;gap:4px;font-weight:normal;margin-left:12px;">Newest only:
                    <select id="snap-find-newest" style="width:auto;">
                        <option value="">(all)</option>
                        <option value="7d">7 days</option>
                        <option value="30d">30 days</option>
                        <option value="90d">90 days</option>
                        <option value="1y">1 year</option>
                    </select>
                </label>
            </div>
            <div class="rb-hint">Glob-style patterns. Use <code>*</code> for any chars in one path segment, <code>**</code> for any depth, <code>?</code> for a single char.</div>
            <div id="snap-find-msg" style="display:none;margin-top:6px;font-size:.88em;padding:6px 10px;border-radius:4px;background:var(--bg-secondary);"></div>
            <div id="snap-find-results" style="margin-top:10px;max-height:380px;overflow-y:auto;"></div>
        </div>

        <!-- Snapshot browser -->
        <div id="snap-browser" style="display:none;margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                <strong style="color:var(--text-muted);font-size:.88em;">SNAPSHOT:</strong>
                <span id="snap-browser-id" style="font-family:monospace;color:var(--accent);font-size:.9em;"></span>
                <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbSnapBrowserUp()">&#8593; Up</button>
                <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="document.getElementById('snap-browser').style.display='none'">&#10005; Close</button>
            </div>
            <div id="snap-browser-path" style="margin-bottom:6px;min-height:1.4em;"></div>
            <div id="snap-browser-list" class="rb-tree" style="max-height:380px;max-width:680px;">
                <div style="padding:10px;color:var(--text-muted);">Select a snapshot to browse</div>
            </div>
            <div class="rb-row" style="margin-top:12px;">
                <label>Restore to:</label>
                <input type="text" id="snap-restore-dest" placeholder="/mnt/user/restore" data-picktree="dir" autocomplete="off" style="flex:1;max-width:360px;">
                <button class="rb-btn rb-btn-green" onclick="rbSnapRestore()">&#8595; Restore Selected</button>
            </div>
            <div class="rb-hint">Check items to select them, then click Restore. Folders restore everything inside them.</div>
            <div id="snap-restore-msg" style="display:none;margin-top:6px;font-size:.88em;padding:6px 10px;border-radius:4px;background:var(--bg-secondary);"></div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- REPOSITORY TOOLS -->
<!-- ================================================================ -->
<div class="rb-section">
    <div class="rb-section-hdr closed" onclick="rbToggle(this)">
        <span>Repository Tools</span><span class="arr">&#9660;</span>
    </div>
    <div class="rb-section-body hidden">
        <p style="color:var(--text-muted);margin:0 0 10px;">
            Advanced repository operations. Select a job + target, then use the buttons below.
            Long-running operations (check, recover, forget) stream their output to a dedicated log file under <code>/boot/logs</code>.
        </p>

        <div class="rb-row">
            <label>Job:</label>
            <select id="repo-job-sel" onchange="rbRepoJobChange()">
                <option value="">-- Select Job --</option>
                <?php foreach ($jobs as $j): ?>
                <option value="<?= htmlspecialchars($j['id']) ?>"><?= htmlspecialchars($j['name'] ?: 'Unnamed') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rb-row" id="repo-target-row" style="display:none;">
            <label>Target:</label>
            <select id="repo-target-sel"></select>
        </div>

        <!-- STATS -->
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
            <strong style="color:var(--text-muted);font-size:.88em;display:block;margin-bottom:8px;">REPOSITORY STATISTICS</strong>
            <div class="rb-row">
                <label>Mode:</label>
                <select id="repo-stats-mode" style="max-width:240px;">
                    <option value="raw-data">raw-data (deduplicated size)</option>
                    <option value="restore-size">restore-size</option>
                    <option value="files-by-contents">files-by-contents</option>
                    <option value="blobs-per-file">blobs-per-file</option>
                </select>
                <button class="rb-btn rb-btn-accent" onclick="rbRepoStats()">&#128202; Show Stats</button>
            </div>
            <div id="repo-stats-out" style="display:none;margin-top:8px;font-family:monospace;font-size:.88em;background:var(--bg-secondary);padding:10px;border-radius:4px;white-space:pre-wrap;"></div>
        </div>

        <!-- UNLOCK -->
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
            <strong style="color:var(--text-muted);font-size:.88em;display:block;margin-bottom:8px;">UNLOCK REPOSITORY</strong>
            <div class="rb-hint">Remove stale locks left by a crashed or killed restic run. Use <code>--remove-all</code> only if you are sure no other restic process is using the repo.</div>
            <div class="rb-row" style="margin-top:6px;">
                <label style="display:inline-flex;align-items:center;gap:4px;font-weight:normal;">
                    <input type="checkbox" id="repo-unlock-all"> Remove ALL locks (--remove-all)
                </label>
                <button class="rb-btn rb-btn-accent" onclick="rbRepoUnlock()">&#128275; Unlock</button>
            </div>
            <div id="repo-unlock-msg" style="display:none;margin-top:6px;font-size:.88em;padding:6px 10px;border-radius:4px;background:var(--bg-secondary);"></div>
        </div>

        <!-- CHECK -->
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
            <strong style="color:var(--text-muted);font-size:.88em;display:block;margin-bottom:8px;">INTEGRITY CHECK</strong>
            <div class="rb-hint">Verify repository integrity. With <em>Subset</em> set (e.g. <code>5%</code> or <code>2G</code>) restic also re-reads and hashes that portion of the data.</div>
            <div class="rb-row" style="margin-top:6px;">
                <label>Subset:</label>
                <input type="text" id="repo-check-subset" placeholder="e.g. 5% or 2G (empty = metadata only)" style="max-width:260px;">
                <button class="rb-btn rb-btn-accent" onclick="rbRepoCheck()">&#9745; Start Check</button>
                <button class="rb-btn" onclick="rbRepoToolStop('check')" id="repo-check-stop" style="display:none;">&#9632; Stop polling</button>
            </div>
            <div id="repo-check-msg" style="display:none;margin-top:6px;font-size:.88em;padding:6px 10px;border-radius:4px;background:var(--bg-secondary);"></div>
            <pre id="repo-check-out" style="display:none;margin-top:6px;max-height:320px;overflow:auto;padding:8px 10px;border-radius:4px;background:var(--bg-secondary);font-family:monospace;font-size:.85em;white-space:pre-wrap;"></pre>
        </div>

        <!-- RECOVER -->
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
            <strong style="color:var(--text-muted);font-size:.88em;display:block;margin-bottom:8px;">RECOVER ORPHANED SNAPSHOTS</strong>
            <div class="rb-hint">Scan the repo for packs not referenced by any snapshot (typically after a crashed backup). Runs in the background.</div>
            <div class="rb-row" style="margin-top:6px;">
                <button class="rb-btn rb-btn-accent" onclick="rbRepoRecover()">&#9998; Start Recover</button>
                <button class="rb-btn" onclick="rbRepoToolStop('recover')" id="repo-recover-stop" style="display:none;">&#9632; Stop polling</button>
            </div>
            <div id="repo-recover-msg" style="display:none;margin-top:6px;font-size:.88em;padding:6px 10px;border-radius:4px;background:var(--bg-secondary);"></div>
            <pre id="repo-recover-out" style="display:none;margin-top:6px;max-height:320px;overflow:auto;padding:8px 10px;border-radius:4px;background:var(--bg-secondary);font-family:monospace;font-size:.85em;white-space:pre-wrap;"></pre>
        </div>

        <!-- RESTIC SELF-UPDATE -->
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
            <strong style="color:var(--text-muted);font-size:.88em;display:block;margin-bottom:8px;">RESTIC BINARY</strong>
            <div class="rb-hint">Upgrade <code>/usr/local/bin/restic</code> to the latest upstream release.</div>
            <div class="rb-row" style="margin-top:6px;">
                <button class="rb-btn rb-btn-accent" onclick="rbResticSelfUpdate()">&#8593; Self-Update</button>
            </div>
            <div id="repo-selfup-msg" style="display:none;margin-top:6px;font-size:.88em;padding:6px 10px;border-radius:4px;background:var(--bg-secondary);white-space:pre-wrap;font-family:monospace;"></div>
        </div>
    </div>
</div>

<!-- SAVE -->
<div style="margin:14px 0;display:flex;gap:10px;align-items:center;">
    <button class="rb-btn rb-btn-accent" onclick="rbSave()" style="padding:8px 22px;font-size:13px;">&#10003; Save Configuration</button>
    <span id="rb-save-msg" style="display:none;font-size:12px;"></span>
</div>

</div><!-- /rb-col right -->
</div><!-- /rb-grid -->
</div><!-- /rb-wrap -->

<script src="<?autov('/plugins/restic-backup/assets/script.js')?>"></script>
<script>rbInitPickTree();</script>
