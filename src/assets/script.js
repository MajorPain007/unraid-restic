/**
 * Restic Backup Plugin - Frontend (v5)
 *
 * Uses form-encoded POST (compatible with Unraid emhttpd).
 * Inline directory picker on focus for path fields.
 */
var rbUrl = '/plugins/restic-backup/ResticBackupAPI.php';
var rbLogTimer = null;
var rbActiveJobIdx = 0;
var rbActiveTree = null;

// =============================================================================
// CORE AJAX - form-encoded POST, Unraid-compatible
// =============================================================================
function rbAjax(action, data, onSuccess, onError) {
    var params = new URLSearchParams();
    params.append('action', action);
    if (data && Object.keys(data).length > 0) {
        params.append('data', JSON.stringify(data));
    }
    if (typeof csrf_token !== 'undefined') params.append('csrf_token', csrf_token);

    fetch(rbUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(function(r) { return r.text(); })
    .then(function(text) {
        if (!text || text.trim() === '') {
            var msg = 'Empty response from server';
            if (onError) onError(msg);
            else rbMsg(msg, 'error');
            return;
        }
        // Extract JSON even if PHP warnings appear before it (may be object or array)
        var idxObj = text.indexOf('{');
        var idxArr = text.indexOf('[');
        var jsonStart = (idxObj < 0) ? idxArr : (idxArr < 0 ? idxObj : Math.min(idxObj, idxArr));
        if (jsonStart < 0) {
            var msg = 'No JSON in response: ' + text.substring(0, 200);
            if (onError) onError(msg);
            else rbMsg(msg, 'error');
            return;
        }
        try {
            var resp = JSON.parse(text.substring(jsonStart));
            if (onSuccess) onSuccess(resp);
        } catch(e) {
            var msg = 'JSON parse error: ' + text.substring(0, 200);
            if (onError) onError(msg);
            else rbMsg(msg, 'error');
        }
    })
    .catch(function(err) {
        if (onError) onError(String(err));
        else rbMsg('Request failed: ' + err, 'error');
    });
}

// =============================================================================
// SECTION TOGGLE
// =============================================================================
function rbToggle(hdr) {
    hdr.classList.toggle('closed');
    hdr.nextElementSibling.classList.toggle('hidden');
}

// =============================================================================
// PASSWORD TOGGLE (per target)
// =============================================================================
function rbTargetPwToggle(sel) {
    var card = sel.closest('.rb-card');
    var mode = sel.value;
    card.querySelector('.target-pw-file-row').style.display   = mode === 'file'   ? '' : 'none';
    card.querySelector('.target-pw-inline-row').style.display = mode === 'inline' ? '' : 'none';
}

// =============================================================================
// JOB TABS
// =============================================================================
function rbSwitchJob(idx) {
    rbActiveJobIdx = idx;
    var tabs = document.querySelectorAll('.rb-job-tab');
    var panels = document.querySelectorAll('.rb-job-panel');
    tabs.forEach(function(t, i) { t.classList.toggle('active', i === idx); });
    panels.forEach(function(p, i) { p.style.display = i === idx ? '' : 'none'; });
}

function rbAddJob() {
    var container = document.getElementById('rb-jobs-container');
    var tabs = document.getElementById('rb-job-tabs');
    var idx = container.querySelectorAll('.rb-job-panel').length;
    var id = rbGenId();

    var addBtn = tabs.querySelector('button');
    var tab = document.createElement('div');
    tab.className = 'rb-job-tab';
    tab.textContent = 'New Job';
    tab.onclick = function() { rbSwitchJob(idx); };
    tabs.insertBefore(tab, addBtn);

    var noJobs = container.parentElement.querySelector('p');
    if (noJobs) noJobs.remove();

    container.insertAdjacentHTML('beforeend', rbJobPanelHtml(id, idx));
    rbSwitchJob(idx);
    rbInitPickTree();
}

function rbRemoveJob(btn) {
    if (!confirm('Delete this job?')) return;
    var panel = btn.closest('.rb-job-panel');
    panel.remove();
    var tabs = document.querySelectorAll('.rb-job-tab');
    var allPanels = document.querySelectorAll('.rb-job-panel');
    // Remove the tab that matches
    var tabArr = Array.from(document.querySelectorAll('.rb-job-tab'));
    // Re-index everything
    allPanels.forEach(function(p, i) { p.setAttribute('data-job-idx', i); });
    // Rebuild tabs
    var tabContainer = document.getElementById('rb-job-tabs');
    var addBtn = tabContainer.querySelector('button');
    tabContainer.querySelectorAll('.rb-job-tab').forEach(function(t) { t.remove(); });
    allPanels.forEach(function(p, i) {
        var t = document.createElement('div');
        t.className = 'rb-job-tab';
        t.textContent = p.querySelector('.job-name').value || 'Job ' + (i + 1);
        t.onclick = function() { rbSwitchJob(i); };
        tabContainer.insertBefore(t, addBtn);
    });
    if (allPanels.length > 0) rbSwitchJob(0);
}

function rbJobPanelHtml(id, idx) {
    return '<div class="rb-job-panel" data-job-idx="' + idx + '" data-job-id="' + id + '">'
    + '<div class="rb-row">'
    + '  <label>Job Name:</label><input type="text" class="job-name" value="" placeholder="e.g. Daily Backup">'
    + '  <select class="job-enabled"><option value="1" selected>Enabled</option><option value="0">Disabled</option></select>'
    + '  <button class="rb-btn rb-btn-red rb-btn-sm" onclick="rbRemoveJob(this)">Delete Job</button>'
    + '</div>'
    // Targets
    + '<div class="rb-section" style="margin-top:10px;"><div class="rb-section-hdr" onclick="rbToggle(this)"><span>Targets</span><span class="arr">&#9660;</span></div>'
    + '<div class="rb-section-body"><div class="job-targets"></div><button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbAddTarget(this)">+ Add Target</button></div></div>'
    // ZFS
    + '<div class="rb-section"><div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>ZFS Snapshots</span><span class="arr">&#9660;</span></div>'
    + '<div class="rb-section-body hidden"><p style="color:var(--text-muted);margin:0 0 10px;">Create ZFS snapshots before backup for data consistency.</p>'
    + '<div class="rb-row"><label>Enable Snapshots:</label><select class="zfs-enabled"><option value="0" selected>Disabled</option><option value="1">Enabled</option></select></div>'
    + '<div class="rb-row"><label>Recursive:</label><select class="zfs-recursive" onchange="rbSyncDsToManual(this.closest(\'.rb-job-panel\'))"><option value="1" selected>Yes — snapshot parent + all children</option><option value="0">No — only selected datasets</option></select></div>'
    + '<div class="rb-row"><label>Snapshot Prefix:</label><input type="text" class="zfs-prefix" value="restic-backup" style="max-width:200px;"></div>'
    + '<div style="margin-top:8px;"><label style="font-weight:bold;display:block;margin-bottom:6px;">Datasets:</label>'
    + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbLoadDatasets(this)" style="margin-bottom:8px;">Load Available Datasets</button>'
    + '<div class="zfs-ds-picker rb-ds-list" style="display:none;"></div>'
    + '<div class="zfs-ds-manual"></div>'
    + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbAddDatasetInput(this)" style="margin-top:4px;">+ Add Manually</button></div></div></div>'
    // Sources
    + '<div class="rb-section"><div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Source Directories</span><span class="arr">&#9660;</span></div>'
    + '<div class="rb-section-body hidden"><p style="color:var(--text-muted);margin:0 0 10px;">Directories to include in the backup. Click into a path field to browse.</p>'
    + '<div class="rb-row" style="margin-bottom:10px;"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;min-width:0;">'
    + '<input type="checkbox" class="job-backup-boot" autocomplete="off"> <span>Include <code>/boot</code> (Unraid USB key)</span></label></div>'
    + '<div class="job-sources"></div><button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbAddSource(this)">+ Add Source</button></div></div>'
    // Excludes
    + '<div class="rb-section"><div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Exclude Patterns</span><span class="arr">&#9660;</span></div>'
    + '<div class="rb-section-body hidden"><p style="color:var(--text-muted);margin:0 0 10px;">One pattern per line.</p>'
    + '<div style="margin-bottom:10px;"><label style="font-weight:bold;display:block;margin-bottom:4px;">Global Excludes:</label><textarea class="rb-excludes job-exc-global" placeholder=".Trash&#10;*.DS_Store"></textarea></div>'
    + '<div><label style="font-weight:bold;display:block;margin-bottom:4px;">Optional Excludes:</label><textarea class="rb-excludes job-exc-optional" placeholder="**/cache/**"></textarea></div></div></div>'
    // Retention
    + '<div class="rb-section"><div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Retention Policy</span><span class="arr">&#9660;</span></div>'
    + '<div class="rb-section-body hidden"><div class="rb-retention-grid">'
    + '<div class="rb-row"><label>Keep Daily:</label><input type="number" class="ret-daily" value="7" min="0"></div>'
    + '<div class="rb-row"><label>Keep Weekly:</label><input type="number" class="ret-weekly" value="4" min="0"></div>'
    + '<div class="rb-row"><label>Keep Monthly:</label><input type="number" class="ret-monthly" value="0" min="0"></div>'
    + '<div class="rb-row"><label>Keep Yearly:</label><input type="number" class="ret-yearly" value="0" min="0"></div>'
    + '</div></div></div>'
    // Schedule
    + '<div class="rb-section"><div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Schedule &amp; Integrity Check</span><span class="arr">&#9660;</span></div>'
    + '<div class="rb-section-body hidden">'
    + '<div class="rb-row"><label>Schedule:</label><select class="sched-enabled"><option value="0" selected>Disabled</option><option value="1">Enabled</option></select></div>'
    + '<div class="rb-row"><label>Preset:</label><select class="sched-preset" onchange="rbApplyPreset(this)"><option value="">Custom</option><option value="0 3 * * *">Daily 3:00 AM</option><option value="0 4 * * *">Daily 4:00 AM</option><option value="0 2 * * 0">Weekly Sunday 2 AM</option></select></div>'
    + '<div class="rb-row"><label>Cron:</label><input type="text" class="sched-cron" value="" placeholder="0 3 * * *" style="max-width:180px;"></div>'
    + '<hr style="border-color:var(--border);margin:10px 0;">'
    + '<div class="rb-row"><label>Integrity Check:</label><select class="chk-enabled"><option value="0" selected>Disabled</option><option value="1">Enabled</option></select></div>'
    + '<div class="rb-row"><label>Data %:</label><input type="text" class="chk-pct" value="2%" style="max-width:80px;"></div>'
    + '<div class="rb-row"><label>Check When:</label><select class="chk-sched"><option value="sunday">Sunday</option><option value="monthly">First of Month</option><option value="always">Every Backup</option></select></div>'
    + '<hr style="border-color:var(--border);margin:10px 0;">'
    + '<div class="rb-row"><label>Tags:</label><input type="text" class="job-tags" value="" placeholder="unraid,daily"></div>'
    + '<div class="rb-row"><label>Max Retries:</label><input type="number" class="job-retries" value="3" min="1" max="10" style="max-width:70px;"></div>'
    + '<div class="rb-row"><label>Retry Wait (s):</label><input type="number" class="job-retry-wait" value="30" min="5" style="max-width:70px;"></div>'
    + '</div></div></div>';
}

// =============================================================================
// ADD TARGET / SOURCE
// =============================================================================
var rbCredsHtml = ''
    + '<div class="target-creds target-creds-sftp" style="display:none;">'
    +   '<div class="rb-hint" style="padding-left:0;margin-bottom:6px;">SFTP uses SSH key auth — configure in <code>/root/.ssh/config</code>.</div>'
    +   '<div class="rb-row"><label>Accept New Host Key:</label>'
    +   '<select class="cred-sftp-hostkey">'
    +   '<option value="1" selected>Yes (StrictHostKeyChecking=accept-new)</option>'
    +   '<option value="0">No (manual known_hosts required)</option>'
    +   '</select></div></div>'
    + '<div class="target-creds target-creds-s3" style="display:none;">'
    +   '<div class="rb-row"><label>Access Key ID:</label><input type="text" class="cred-s3-key" value="" placeholder="AKIAIOSFODNN7EXAMPLE"></div>'
    +   '<div class="rb-row"><label>Secret Access Key:</label><input type="password" class="cred-s3-secret" value="" placeholder="wJalrXUtnFEMI/K7MDENG..."></div>'
    +   '<div class="rb-row"><label>Region:</label><input type="text" class="cred-s3-region" value="" placeholder="us-east-1 (optional)"></div></div>'
    + '<div class="target-creds target-creds-b2" style="display:none;">'
    +   '<div class="rb-row"><label>Account ID:</label><input type="text" class="cred-b2-id" value="" placeholder="B2 Account ID"></div>'
    +   '<div class="rb-row"><label>Application Key:</label><input type="password" class="cred-b2-key" value="" placeholder="B2 Application Key"></div></div>'
    + '<div class="target-creds target-creds-rest" style="display:none;">'
    +   '<div class="rb-row"><label>Username:</label><input type="text" class="cred-rest-user" value="" placeholder="optional"></div>'
    +   '<div class="rb-row"><label>Password:</label><input type="password" class="cred-rest-pass" value="" placeholder="optional"></div></div>'
    + '<div class="target-creds target-creds-rclone" style="display:none;">'
    +   '<div class="rb-hint" style="padding-left:0;margin-bottom:6px;">Rclone uses its own config (<code>rclone config</code>). No credentials needed here.</div></div>';

function rbAddTarget(btn) {
    var list = btn.closest('.rb-section-body').querySelector('.job-targets');
    var n = list.querySelectorAll('.rb-card').length + 1;
    var id = rbGenId();
    var html = '<div class="rb-card" data-id="' + id + '" data-type="local">'
        + '<div class="rb-card-hdr"><span class="rb-card-title">Target #' + n + '</span><div style="display:flex;gap:4px;">'
        + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbInitRepo(this)">Init Repo</button>'
        + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbTestTarget(this)">Test</button>'
        + '<button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest(\'.rb-card\').remove()">Remove</button></div></div>'
        + '<div class="rb-row"><label>Type:</label><select class="target-type" onchange="rbTargetTypeChange(this)">'
        + '<option value="local">Local Path</option><option value="sftp">SFTP</option><option value="s3">S3 / Minio</option>'
        + '<option value="b2">Backblaze B2</option><option value="rest">REST Server</option><option value="rclone">Rclone</option></select></div>'
        + '<div class="rb-row"><label>Repository URL:</label><div class="rb-url-wrap"><span class="rb-url-pfx" style="display:none;"></span><input type="text" class="target-url" value="" placeholder="/mnt/disks/backup/restic" data-picktree="dir"></div></div>'
        + rbCredsHtml
        + '<div class="rb-row"><label>Password Mode:</label>'
        + '<select class="target-pw-mode" onchange="rbTargetPwToggle(this)">'
        + '<option value="file" selected>Password File</option>'
        + '<option value="inline">Inline Password</option>'
        + '</select></div>'
        + '<div class="target-pw-file-row rb-row"><label>Password File:</label>'
        + '<input type="text" class="target-pw-file" placeholder="Path to password file …" data-picktree="file" autocomplete="off"></div>'
        + '<div class="target-pw-inline-row rb-row" style="display:none;"><label>Password:</label>'
        + '<input type="password" class="target-pw-inline" placeholder="Repository password" autocomplete="off"></div>'
        + '<div class="rb-row"><label>Name:</label><input type="text" class="target-name" value="" placeholder="e.g. Hetzner Cloud"></div>'
        + '<div class="rb-row"><label>Upload Limit (KiB/s):</label><input type="number" class="target-limit-up" value="0" min="0" placeholder="0 = unlimited" style="max-width:140px;"><span class="rb-hint">0 = unlimited</span></div>'
        + '<div class="rb-row"><label>Download Limit (KiB/s):</label><input type="number" class="target-limit-down" value="0" min="0" placeholder="0 = unlimited" style="max-width:140px;"><span class="rb-hint">0 = unlimited (restores &amp; checks)</span></div>'
        + '<div class="rb-row"><label>Optional Excludes:</label><select class="target-opt-exc"><option value="0" selected>No</option><option value="1">Yes</option></select></div>'
        + '<div class="rb-row"><label>Enabled:</label><select class="target-enabled"><option value="1" selected>Yes</option><option value="0">No</option></select></div>'
        + '</div>';
    list.insertAdjacentHTML('beforeend', html);
    rbInitPickTree();
}

function rbAddSource(btn) {
    var list = btn.closest('.rb-section-body').querySelector('.job-sources');
    var n = list.querySelectorAll('.rb-card').length + 1;
    var id = rbGenId();
    var html = '<div class="rb-card" data-id="' + id + '">'
        + '<div class="rb-card-hdr"><span class="rb-card-title">Source #' + n + '</span>'
        + '<button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest(\'.rb-card\').remove()">Remove</button></div>'
        + '<div class="rb-row"><label>Path:</label><input type="text" class="source-path" value="" placeholder="/mnt/user/appdata" data-picktree="dir"></div>'
        + '<div class="rb-row"><label>Enabled:</label><select class="source-enabled"><option value="1" selected>Yes</option><option value="0">No</option></select></div>'
        + '</div>';
    list.insertAdjacentHTML('beforeend', html);
    rbInitPickTree();
}

// Add a pre- or post-backup hook card to the current job editor.
// phase must be 'pre' or 'post'. The card layout mirrors the PHP-rendered
// variant in ResticBackup.php so rbCollect() can read both identically.
function rbAddHook(btn, phase) {
    var listClass = phase === 'pre' ? '.job-hooks-pre' : '.job-hooks-post';
    var list = btn.closest('.rb-section-body').querySelector(listClass);
    var n = list.querySelectorAll('.hook-card').length + 1;
    var id = rbGenId();
    var titlePrefix = phase === 'pre' ? 'Pre-Hook #' : 'Post-Hook #';
    var defaultTimeout = phase === 'pre' ? 3600 : 600;
    // Post-hooks default to "continue" so a failing webhook doesn't poison
    // the job. Pre-hooks default to "abort" because a failing DB dump means
    // the backup would be inconsistent.
    var preOnErr  = '<option value="abort" selected>Abort job</option><option value="continue">Continue</option>';
    var postOnErr = '<option value="continue" selected>Continue (ignore)</option><option value="abort">Mark job failed</option>';
    var namePlaceholder = phase === 'pre'
        ? 'e.g. MariaDB dump'
        : 'e.g. ntfy notification';
    var cmdPlaceholder = phase === 'pre'
        ? "docker exec mariadb sh -c 'mariadb-dump …' | gzip > /mnt/user/appdata/_db_dumps/mariadb.sql.gz"
        : 'curl -fsS -d "$RESTIC_JOB_NAME: $RESTIC_STATUS" https://ntfy.sh/mytopic';
    var html = '<div class="rb-card hook-card" data-id="' + id + '" data-phase="' + phase + '">'
        + '<div class="rb-card-hdr"><span class="rb-card-title">' + titlePrefix + n + '</span>'
        +   '<button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest(\'.rb-card\').remove()">Remove</button></div>'
        + '<div class="rb-row"><label>Name:</label><input type="text" class="hook-name" value="" placeholder="' + namePlaceholder + '"></div>'
        + '<div class="rb-row" style="align-items:flex-start;"><label>Command:</label>'
        +   '<textarea class="hook-command" rows="5" spellcheck="false" style="flex:1;font-family:monospace;font-size:.88em;" placeholder="' + cmdPlaceholder.replace(/"/g,'&quot;') + '"></textarea></div>'
        + '<div class="rb-row"><label>Enabled:</label><select class="hook-enabled"><option value="1" selected>Yes</option><option value="0">No</option></select></div>'
        + '<div class="rb-row"><label>Timeout (s):</label><input type="number" class="hook-timeout" value="' + defaultTimeout + '" min="5" max="86400" style="max-width:100px;"></div>'
        + '<div class="rb-row"><label>On Error:</label><select class="hook-onerror">' + (phase === 'pre' ? preOnErr : postOnErr) + '</select></div>'
        + '</div>';
    list.insertAdjacentHTML('beforeend', html);
}

// =============================================================================
// INLINE PICKER - appears below input on click
// Supports two modes:
//   data-picktree="dir"  → directory picker (default)
//   data-picktree="file" → file picker (shows files alongside folders)
// =============================================================================
function rbInitPickTree() {
    document.querySelectorAll('[data-picktree]').forEach(function(input) {
        if (input._rbPickBound) return;
        input._rbPickBound = true;
        input.addEventListener('focus', function() {
            if (!input.hasAttribute('data-picktree')) return; // type may have changed
            rbOpenTree(input);
        });
    });
}

function rbCloseTree() {
    if (rbActiveTree) {
        rbActiveTree.remove();
        rbActiveTree = null;
    }
    document.removeEventListener('mousedown', rbTreeOutsideClick);
}

function rbOpenTree(input) {
    rbCloseTree();

    var mode = input.getAttribute('data-picktree') === 'file' ? 'file' : 'dir';

    var startPath = input.value || '/mnt';
    if (mode === 'file') {
        // For file mode: if current value points to a file, open its parent folder
        if (startPath && startPath !== '/' && startPath.charAt(startPath.length - 1) !== '/') {
            var lastSlash = startPath.lastIndexOf('/');
            if (lastSlash > 0) startPath = startPath.substring(0, lastSlash);
            else startPath = '/mnt';
        }
    } else {
        if (startPath !== '/' && startPath.charAt(startPath.length - 1) !== '/') {
            var lastSlash2 = startPath.lastIndexOf('/');
            if (lastSlash2 > 0) startPath = startPath.substring(0, lastSlash2);
        }
    }

    var tree = document.createElement('div');
    tree.className = 'rb-tree';

    var selectLabel = mode === 'file' ? 'Select File' : 'Select Folder';
    var hdr = document.createElement('div');
    hdr.className = 'rb-tree-hdr';
    hdr.innerHTML = '<span class="rb-tree-path">' + escHtml(startPath) + '</span>'
        + ' <span class="rb-tree-roots">'
        +   '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbTreeGoto(\'/mnt\')">/mnt</button>'
        +   '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbTreeGoto(\'/boot\')">/boot</button>'
        + '</span>'
        + ' <button class="rb-btn rb-btn-green rb-btn-sm" style="margin-left:8px;" onclick="rbTreeSelect()">' + selectLabel + '</button>'
        + ' <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbCloseTree()">Close</button>';
    tree.appendChild(hdr);

    var list = document.createElement('div');
    list.className = 'rb-tree-list';
    list.innerHTML = '<div style="padding:8px;color:var(--text-muted);">Loading...</div>';
    tree.appendChild(list);

    input.closest('.rb-row').after(tree);
    rbActiveTree = tree;
    tree._rbInput = input;
    tree._rbPath  = startPath;
    tree._rbMode  = mode;
    tree._rbPick  = null; // selected file path in file-mode

    rbLoadTree(tree, startPath);

    setTimeout(function() {
        document.addEventListener('mousedown', rbTreeOutsideClick);
    }, 200);
}

function rbTreeOutsideClick(e) {
    if (rbActiveTree && !rbActiveTree.contains(e.target) && e.target !== rbActiveTree._rbInput) {
        rbCloseTree();
    }
}

function rbTreeGoto(path) {
    if (!rbActiveTree) return;
    rbActiveTree._rbPath = path;
    rbActiveTree._rbPick = null;
    rbActiveTree.querySelector('.rb-tree-path').textContent = path;
    rbLoadTree(rbActiveTree, path);
}

function rbTreeSelect() {
    if (rbActiveTree && rbActiveTree._rbInput) {
        var val;
        if (rbActiveTree._rbMode === 'file') {
            // In file-mode: prefer the picked file; otherwise fall back to current dir
            val = rbActiveTree._rbPick || rbActiveTree._rbPath;
        } else {
            val = rbActiveTree._rbPath;
        }
        val = (val || '/').replace(/\/+$/, '') || '/';
        rbActiveTree._rbInput.value = val;
    }
    rbCloseTree();
}

function rbLoadTree(tree, path) {
    var list = tree.querySelector('.rb-tree-list');
    list.innerHTML = '<div style="padding:8px;color:var(--text-muted);">Loading...</div>';

    var mode = tree._rbMode === 'file' ? 'files' : 'dirs';

    rbAjax('browse', { path: path, mode: mode }, function(resp) {
        tree._rbPath = resp.current || path;
        tree._rbPick = null;
        tree.querySelector('.rb-tree-path').textContent = tree._rbPath;

        var html = '';
        if (resp.parent && resp.parent !== resp.current) {
            html += '<div class="rb-tree-item rb-tree-up" data-path="' + escAttr(resp.parent) + '" data-is-dir="1">'
                + '<span class="rb-tree-icon">&#8593;</span> ..</div>';
        }

        var dirs = resp.dirs || [];
        dirs.forEach(function(d) {
            var name = d.split('/').pop();
            html += '<div class="rb-tree-item" data-path="' + escAttr(d) + '" data-is-dir="1">'
                + '<span class="rb-tree-icon">&#128193;</span>'
                + '<span class="rb-tree-name">' + escHtml(name) + '</span></div>';
        });

        var files = resp.files || [];
        files.forEach(function(f) {
            html += '<div class="rb-tree-item rb-tree-file" data-path="' + escAttr(f.path) + '" data-is-dir="0">'
                + '<span class="rb-tree-icon">&#128196;</span>'
                + '<span class="rb-tree-name">' + escHtml(f.name) + '</span>'
                + '<span class="rb-tree-size">' + rbFmtBytes(f.size) + '</span></div>';
        });

        if (dirs.length === 0 && files.length === 0 && !resp.parent) {
            html = '<div style="padding:8px;color:var(--text-muted);">No entries found</div>';
        }

        list.innerHTML = html;

        list.querySelectorAll('.rb-tree-item').forEach(function(item) {
            var isDir = item.getAttribute('data-is-dir') === '1';
            item.addEventListener('click', function() {
                var p = item.getAttribute('data-path');
                if (isDir) {
                    tree._rbPath = p;
                    tree._rbPick = null;
                    tree.querySelector('.rb-tree-path').textContent = p;
                    rbLoadTree(tree, p);
                } else {
                    // File click → highlight (single-click selects, does not close)
                    list.querySelectorAll('.rb-tree-item.picked').forEach(function(n) { n.classList.remove('picked'); });
                    item.classList.add('picked');
                    tree._rbPick = p;
                    tree.querySelector('.rb-tree-path').textContent = p;
                }
            });
            // Double-click on a file = immediately pick and close
            if (!isDir) {
                item.addEventListener('dblclick', function() {
                    tree._rbPick = item.getAttribute('data-path');
                    rbTreeSelect();
                });
            }
        });
    }, function(err) {
        list.innerHTML = '<div style="padding:8px;color:var(--red);">Error: ' + escHtml(err) + '</div>';
    });
}

// =============================================================================
// TARGET TYPE CHANGE - prefix, credentials, file browser
// =============================================================================
// URL prefixes — hardcoded per type (displayed in span, not editable)
var rbPrefixes = {
    local:  '',
    sftp:   'sftp://',
    s3:     's3:',
    b2:     'b2:',
    rest:   'rest:',
    rclone: 'rclone:'
};

// Placeholder for the SUFFIX input (after prefix)
var rbSuffixPlaceholders = {
    local:  '/mnt/disks/backup/restic',
    sftp:   'user@host:/path/to/repo',
    s3:     'https://s3.amazonaws.com/bucket/path',
    b2:     'bucketname/path',
    rest:   'https://host:8000/',
    rclone: 'remote:path'
};

function rbTargetTypeChange(sel) {
    var card = sel.closest('.rb-card');
    var pfxSpan  = card.querySelector('.rb-url-pfx');
    var urlInput = card.querySelector('.target-url');
    var oldType  = card.getAttribute('data-type') || 'local';
    var newType  = sel.value;
    card.setAttribute('data-type', newType);

    var oldPfx = pfxSpan ? pfxSpan.textContent : '';
    var newPfx = rbPrefixes[newType] || '';
    var suffix = urlInput.value;

    // Strip old prefix from suffix so we always work with the bare path/address
    if (oldType !== 'local' && oldPfx && suffix.indexOf(oldPfx) === 0) {
        suffix = suffix.slice(oldPfx.length);
    }
    if (oldType === 'local') {
        // Coming from local: strip any accidental protocol prefix
        suffix = suffix.replace(/^[a-z][a-z0-9+.-]*:(?:\/\/)?/, '');
    }

    // For local the value IS the full path; for others it's the suffix after the prefix span
    urlInput.value = suffix;

    // Update prefix span
    if (pfxSpan) {
        pfxSpan.textContent = newPfx;
        pfxSpan.style.display = newType === 'local' ? 'none' : '';
        // Adjust input border-radius
        urlInput.style.borderRadius = newType === 'local' ? '' : '0 3px 3px 0';
    }

    urlInput.placeholder = rbSuffixPlaceholders[newType] || '';

    // File browser: only local gets the picker
    if (newType === 'local') {
        urlInput.setAttribute('data-picktree', 'dir');
    } else {
        urlInput.removeAttribute('data-picktree');
    }
    rbInitPickTree();

    // Show/hide credential fields
    card.querySelectorAll('.target-creds').forEach(function(d) { d.style.display = 'none'; });
    var credsDiv = card.querySelector('.target-creds-' + newType);
    if (credsDiv) credsDiv.style.display = '';
}

// =============================================================================
// SCHEDULE PRESET
// =============================================================================
function rbApplyPreset(sel) {
    if (sel.value) {
        sel.closest('.rb-section-body').querySelector('.sched-cron').value = sel.value;
    }
}

// =============================================================================
// ZFS DATASETS
// =============================================================================
function rbLoadDatasets(btn) {
    var panel = btn.closest('.rb-job-panel') || btn.closest('.rb-section-body');
    var picker = panel.querySelector('.zfs-ds-picker');
    btn.disabled = true;
    btn.textContent = 'Loading...';

    rbAjax('datasets', {}, function(resp) {
        btn.disabled = false;
        btn.textContent = 'Load Available Datasets';

        if (!resp.available || !resp.datasets || resp.datasets.length === 0) {
            rbMsg('No ZFS datasets found. Is ZFS configured on this system?', 'error');
            return;
        }

        var selected = [];
        panel.querySelectorAll('.zfs-dataset').forEach(function(inp) {
            if (inp.value.trim()) selected.push(inp.value.trim());
        });

        var html = '';
        resp.datasets.forEach(function(ds) {
            var depth = (ds.match(/\//g) || []).length;
            var cls = depth > 0 ? ' child' : '';
            var pad = depth * 18;
            var checked = selected.indexOf(ds) >= 0 ? ' checked' : '';
            html += '<div class="rb-ds-item' + cls + '" style="padding-left:' + (6 + pad) + 'px;">'
                + '<input type="checkbox" class="ds-cb" value="' + ds + '"' + checked + ' onchange="rbDsToggle(this)">'
                + '<label onclick="this.previousElementSibling.click()">' + ds + '</label></div>';
        });
        picker.innerHTML = html;
        picker.style.display = '';
    }, function(err) {
        btn.disabled = false;
        btn.textContent = 'Load Available Datasets';
        rbMsg('Failed to load datasets: ' + err, 'error');
    });
}

function rbDsIsRecursive(panel) {
    var sel = panel.querySelector('.zfs-recursive');
    return !sel || sel.value === '1';
}

function rbDsToggle(cb) {
    var picker = cb.closest('.zfs-ds-picker');
    var panel  = cb.closest('.rb-job-panel') || cb.closest('.rb-section-body');
    var val    = cb.value;
    // Always visually check/uncheck children (optical feedback)
    picker.querySelectorAll('.ds-cb').forEach(function(other) {
        if (other !== cb && other.value.indexOf(val + '/') === 0) {
            other.checked = cb.checked;
        }
    });
    rbSyncDsToManual(panel);
}

// Returns true if any checked ancestor of val exists in picker
function rbDsHasCheckedParent(picker, val) {
    var parts = val.split('/');
    for (var i = 1; i < parts.length; i++) {
        var ancestor = parts.slice(0, i).join('/');
        var acb = picker.querySelector('.ds-cb[value="' + CSS.escape(ancestor) + '"]');
        if (acb && acb.checked) return true;
    }
    return false;
}

function rbSyncDsToManual(panel) {
    var picker    = panel.querySelector('.zfs-ds-picker');
    var manual    = panel.querySelector('.zfs-ds-manual');
    var recursive = rbDsIsRecursive(panel);
    if (!picker) return;
    manual.innerHTML = '';
    picker.querySelectorAll('.ds-cb:checked').forEach(function(cb) {
        // When recursive: only save the parent — skip children that have a checked ancestor
        if (recursive && rbDsHasCheckedParent(picker, cb.value)) return;
        var div = document.createElement('div');
        div.className = 'rb-row';
        div.style.flexWrap = 'nowrap';
        div.innerHTML = '<input type="text" class="zfs-dataset" value="' + cb.value + '" readonly style="flex:1;min-width:0;opacity:.7;">'
            + (recursive ? '<span style="white-space:nowrap;color:var(--accent);font-size:.8em;flex-shrink:0;">recursive</span>' : '')
            + '<button class="rb-btn rb-btn-red rb-btn-sm" onclick="rbRemoveDs(this,\'' + cb.value + '\')">X</button>';
        manual.appendChild(div);
    });
}

function rbRemoveDs(btn, val) {
    var panel = btn.closest('.rb-job-panel') || btn.closest('.rb-section-body');
    var picker = panel.querySelector('.zfs-ds-picker');
    if (picker) {
        picker.querySelectorAll('.ds-cb').forEach(function(cb) {
            if (cb.value === val) cb.checked = false;
        });
    }
    btn.parentElement.remove();
}

function rbAddDatasetInput(btn) {
    var manual = btn.closest('div').querySelector('.zfs-ds-manual');
    manual.insertAdjacentHTML('beforeend',
        '<div class="rb-row"><input type="text" class="zfs-dataset" value="" placeholder="cache/appdata" style="flex:1;">'
        + '<button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.parentElement.remove()">X</button></div>');
}

// =============================================================================
// COLLECT CONFIG
// =============================================================================
function rbCollect() {
    var config = {
        general: {
            hostname: document.getElementById('cfg-hostname').value.trim(),
            notifications: document.getElementById('cfg-notify').value === '1'
        },
        jobs: []
    };

    document.querySelectorAll('.rb-job-panel').forEach(function(panel) {
        var job = {
            id: panel.getAttribute('data-job-id') || rbGenId(),
            name: panel.querySelector('.job-name').value.trim(),
            enabled: panel.querySelector('.job-enabled').value === '1',
            targets: [],
            sources: [],
            hooks: { pre_backup: [], post_backup: [] },
            backup_boot: !!(panel.querySelector('.job-backup-boot') || {}).checked,
            zfs: {
                enabled:   panel.querySelector('.zfs-enabled').value === '1',
                recursive: rbDsIsRecursive(panel),
                snapshot_prefix: panel.querySelector('.zfs-prefix').value.trim() || 'restic-backup',
                datasets: []
            },
            excludes: {
                global: rbTextToArr(panel.querySelector('.job-exc-global')),
                optional: rbTextToArr(panel.querySelector('.job-exc-optional'))
            },
            retention: {
                keep_daily: parseInt(panel.querySelector('.ret-daily').value) || 0,
                keep_weekly: parseInt(panel.querySelector('.ret-weekly').value) || 0,
                keep_monthly: parseInt(panel.querySelector('.ret-monthly').value) || 0,
                keep_yearly: parseInt(panel.querySelector('.ret-yearly').value) || 0
            },
            schedule: {
                enabled: panel.querySelector('.sched-enabled').value === '1',
                cron: panel.querySelector('.sched-cron').value.trim()
            },
            check: {
                enabled: panel.querySelector('.chk-enabled').value === '1',
                percentage: panel.querySelector('.chk-pct').value.trim() || '2%',
                schedule: panel.querySelector('.chk-sched').value
            },
            verify: {
                enabled: (panel.querySelector('.verify-enabled') || {value:'0'}).value === '1',
                path:    (panel.querySelector('.verify-path')    || {value:''}).value.trim()
            },
            tags: panel.querySelector('.job-tags').value.trim(),
            max_retries: parseInt(panel.querySelector('.job-retries').value) || 3,
            retry_wait: parseInt(panel.querySelector('.job-retry-wait').value) || 30
        };

        panel.querySelectorAll('.job-targets .rb-card').forEach(function(card) {
            job.targets.push({
                id: card.getAttribute('data-id') || rbGenId(),
                type: card.querySelector('.target-type').value,
                url: (function() {
                    var pfx = card.querySelector('.rb-url-pfx');
                    var pfxText = (pfx && pfx.style.display !== 'none') ? pfx.textContent : '';
                    return pfxText + card.querySelector('.target-url').value.trim();
                })(),
                name: card.querySelector('.target-name').value.trim(),
                limit_upload: parseInt((card.querySelector('.target-limit-up') || {value:'0'}).value, 10) || 0,
                limit_download: parseInt((card.querySelector('.target-limit-down') || {value:'0'}).value, 10) || 0,
                use_optional_excludes: card.querySelector('.target-opt-exc').value === '1',
                enabled: card.querySelector('.target-enabled').value === '1',
                credentials: rbGetTargetCreds(card),
                password_mode: (card.querySelector('.target-pw-mode') || {value:'file'}).value,
                password_file: (card.querySelector('.target-pw-file') || {value:''}).value.trim(),
                password_inline: (card.querySelector('.target-pw-inline') || {value:''}).value.trim()
            });
        });

        panel.querySelectorAll('.job-sources .rb-card').forEach(function(card) {
            job.sources.push({
                id: card.getAttribute('data-id') || rbGenId(),
                path: card.querySelector('.source-path').value.trim(),
                enabled: card.querySelector('.source-enabled').value === '1'
            });
        });

        panel.querySelectorAll('.zfs-dataset').forEach(function(inp) {
            var v = inp.value.trim();
            if (v) job.zfs.datasets.push(v);
        });

        // Pre/post-backup hooks: same card layout, different list classes
        [['.job-hooks-pre', 'pre_backup'], ['.job-hooks-post', 'post_backup']]
        .forEach(function(pair) {
            panel.querySelectorAll(pair[0] + ' .hook-card').forEach(function(card) {
                job.hooks[pair[1]].push({
                    id:       card.getAttribute('data-id') || rbGenId(),
                    name:     (card.querySelector('.hook-name').value || '').trim(),
                    command:  card.querySelector('.hook-command').value,
                    enabled:  card.querySelector('.hook-enabled').value === '1',
                    timeout:  parseInt(card.querySelector('.hook-timeout').value, 10) || 3600,
                    on_error: card.querySelector('.hook-onerror').value
                });
            });
        });

        config.jobs.push(job);
    });

    return config;
}

// =============================================================================
// TARGET CREDENTIAL HELPER
// =============================================================================
function rbGetTargetCreds(card) {
    function v(sel) { var el = card.querySelector(sel); return el ? el.value.trim() : ''; }
    var hkEl = card.querySelector('.cred-sftp-hostkey');
    return {
        sftp_accept_hostkey:   hkEl ? hkEl.value === '1' : true,
        aws_access_key_id:     v('.cred-s3-key'),
        aws_secret_access_key: v('.cred-s3-secret'),
        aws_region:            v('.cred-s3-region'),
        b2_account_id:         v('.cred-b2-id'),
        b2_account_key:        v('.cred-b2-key'),
        rest_user:             v('.cred-rest-user'),
        rest_pass:             v('.cred-rest-pass')
    };
}

// =============================================================================
// SAVE + AUTO-INIT
// =============================================================================
function rbSave() {
    var config = rbCollect();
    var msg = document.getElementById('rb-save-msg');

    msg.textContent = 'Saving...';
    msg.style.color = '#f39c12';
    msg.style.display = 'inline';

    rbAjax('save', { config: config }, function(resp) {
        if (resp.status === 'success') {
            msg.textContent = resp.message || 'Saved!';
            msg.style.color = '#27ae60';
            rbAutoInit(config);
        } else {
            msg.textContent = 'ERROR: ' + (resp.message || JSON.stringify(resp));
            msg.style.color = '#c0392b';
        }
        setTimeout(function() { msg.style.display = 'none'; }, 6000);
    }, function(err) {
        msg.textContent = 'SAVE FAILED: ' + err;
        msg.style.color = '#c0392b';
        msg.style.display = 'inline';
    });
}

function rbAutoInit(config) {
    config.jobs.forEach(function(job) {
        if (!job.enabled) return;
        job.targets.forEach(function(target) {
            if (!target.enabled || !target.url) return;

            var body = {
                url: target.url,
                type: target.type || 'local',
                credentials: target.credentials || {},
                password_mode:   target.password_mode   || 'file',
                password_file:   target.password_file   || '',
                password_inline: target.password_inline || ''
            };

            rbAjax('test', body, function(resp) {
                if (resp.status !== 'success') {
                    rbAjax('init', body, function(initResp) {
                        if (initResp.status === 'success') {
                            rbMsg('Repository auto-initialized: ' + (target.name || target.url), 'success');
                        }
                    });
                }
            });
        });
    });
}

// =============================================================================
// BACKUP CONTROL
// =============================================================================
function rbStartBackup() {
    document.getElementById('btn-start').disabled = true;
    document.getElementById('btn-stop').disabled = false;
    rbUpdateBadge(true);
    var jobId = document.getElementById('rb-job-select').value;
    rbAjax('backup', { job_id: jobId }, function() {
        rbStartLogPoll();
        rbStartProgressPoll();
    });
}

function rbStopBackup() {
    rbAjax('stop', {}, function() {
        document.getElementById('btn-start').disabled = false;
        document.getElementById('btn-stop').disabled = true;
        rbUpdateBadge(false);
        rbStopLogPoll();
        rbRefreshLog();
    });
}

// =============================================================================
// INIT / TEST
// =============================================================================
function rbCardPwBody(card) {
    return {
        password_mode:   (card.querySelector('.target-pw-mode')   || {value:'file'}).value,
        password_file:   (card.querySelector('.target-pw-file')   || {value:''}).value.trim(),
        password_inline: (card.querySelector('.target-pw-inline') || {value:''}).value.trim()
    };
}

function rbInitRepo(btn) {
    var card = btn.closest('.rb-card');
    var url = card.querySelector('.target-url').value.trim();
    if (!url) { rbMsg('Please enter a repository URL first.', 'error'); return; }
    btn.disabled = true; btn.textContent = 'Init...';
    var body = rbCardPwBody(card);
    body.url = url;
    body.type = card.getAttribute('data-type') || 'local';
    body.credentials = rbGetTargetCreds(card);
    rbAjax('init', body, function(resp) {
        btn.disabled = false; btn.textContent = 'Init Repo';
        rbMsg(resp.message || 'Done', resp.status === 'success' ? 'success' : 'error');
    }, function(err) {
        btn.disabled = false; btn.textContent = 'Init Repo';
        rbMsg('Error: ' + err, 'error');
    });
}

function rbTestTarget(btn) {
    var card = btn.closest('.rb-card');
    var url = card.querySelector('.target-url').value.trim();
    if (!url) { rbMsg('Please enter a repository URL first.', 'error'); return; }
    btn.disabled = true; btn.textContent = 'Testing...';
    var body = rbCardPwBody(card);
    body.url = url;
    body.type = card.getAttribute('data-type') || 'local';
    body.credentials = rbGetTargetCreds(card);
    rbAjax('test', body, function(resp) {
        btn.disabled = false; btn.textContent = 'Test';
        rbMsg(resp.message || 'Done', resp.status === 'success' ? 'success' : 'error');
    }, function(err) {
        btn.disabled = false; btn.textContent = 'Test';
        rbMsg('Error: ' + err, 'error');
    });
}

// =============================================================================
// LOG
// =============================================================================
function rbRefreshLog() {
    // Filter the shown log by the currently selected job. "" (All Jobs) reads
    // the combined main log; a specific job id reads that job's log file only.
    var sel = document.getElementById('rb-job-select');
    var jobId = sel ? sel.value : '';
    rbAjax('log', { job_id: jobId }, function(resp) {
        var el = document.getElementById('rb-log');
        var text = (resp.log || '').trim();
        if (!text) {
            text = jobId
                ? 'No log entries yet for this job.'
                : 'No log data.';
        }
        el.textContent = text;
        el.scrollTop = el.scrollHeight;
    });
}

function rbStartLogPoll() {
    rbRefreshLog();
    if (rbLogTimer) clearInterval(rbLogTimer);
    rbLogTimer = setInterval(function() {
        rbRefreshLog();
        rbCheckStatus();
    }, 3000);
}

function rbStopLogPoll() {
    if (rbLogTimer) { clearInterval(rbLogTimer); rbLogTimer = null; }
}

function rbCheckStatus() {
    rbAjax('status', {}, function(resp) {
        if (!resp.running) {
            document.getElementById('btn-start').disabled = false;
            document.getElementById('btn-stop').disabled = true;
            rbUpdateBadge(false);
            rbStopLogPoll();
            rbStopProgressPoll();
            rbRefreshLog();
        } else {
            rbStartProgressPoll();
        }
    });
}

// =============================================================================
// LIVE PROGRESS (polls /tmp/restic-progress-<jobid>.json via API)
// =============================================================================
var rbProgressTimer = null;

function rbStartProgressPoll() {
    if (rbProgressTimer) return;
    rbTickProgress();
    rbProgressTimer = setInterval(rbTickProgress, 2000);
}

function rbStopProgressPoll() {
    if (rbProgressTimer) { clearInterval(rbProgressTimer); rbProgressTimer = null; }
    var box = document.getElementById('rb-progress');
    if (box) box.style.display = 'none';
}

function rbTickProgress() {
    var sel = document.getElementById('rb-job-select');
    // Dropdown value = job id being viewed. Empty = "All Jobs".
    // Prefer an explicitly selected job; otherwise scan known jobs until we
    // find one with a non-idle progress file.
    var candidates = [];
    if (sel && sel.value) {
        candidates.push(sel.value);
    } else if (sel) {
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value) candidates.push(sel.options[i].value);
        }
    }
    if (candidates.length === 0) { rbRenderProgress(null); return; }

    var idx = 0;
    function tryNext() {
        if (idx >= candidates.length) { rbRenderProgress(null); return; }
        var jid = candidates[idx++];
        rbAjax('job_progress', { job_id: jid }, function(resp) {
            if (resp && resp.status === 'success' && resp.progress &&
                (resp.progress.phase === 'backup' || resp.progress.phase === 'starting')) {
                rbRenderProgress(resp.progress);
            } else {
                tryNext();
            }
        }, function() { tryNext(); });
    }
    tryNext();
}

function rbRenderProgress(p) {
    var box = document.getElementById('rb-progress');
    if (!box) return;
    if (!p) { box.style.display = 'none'; return; }
    box.style.display = '';

    var pct = p.percent_done != null ? Math.max(0, Math.min(100, p.percent_done * 100)) : 0;
    document.getElementById('rb-progress-bar').style.width  = pct.toFixed(1) + '%';

    var tgt = document.getElementById('rb-progress-target');
    tgt.textContent = (p.target ? p.target + '  ' : '') + pct.toFixed(1) + '%';

    var stats = '';
    if (p.bytes_done != null && p.total_bytes != null) {
        stats += rbHumanBytes(p.bytes_done) + ' / ' + rbHumanBytes(p.total_bytes);
    }
    if (p.files_done != null && p.total_files != null) {
        stats += (stats ? '   ' : '') + p.files_done + ' / ' + p.total_files + ' files';
    }
    if (p.seconds_remaining != null && p.seconds_remaining > 0) {
        stats += (stats ? '   ' : '') + 'ETA ' + rbHumanDuration(p.seconds_remaining);
    }
    document.getElementById('rb-progress-stats').textContent = stats;

    var cf = (p.current_files || []);
    document.getElementById('rb-progress-file').textContent = cf.length ? cf[0] : '';
}

function rbHumanDuration(s) {
    s = Math.max(0, Math.round(s));
    if (s < 60)   return s + 's';
    if (s < 3600) return Math.floor(s/60) + 'm ' + (s%60) + 's';
    return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
}

// =============================================================================
// HELPERS
// =============================================================================
function rbUpdateBadge(running) {
    var b = document.getElementById('rb-status');
    b.textContent = running ? 'RUNNING' : 'IDLE';
    b.className = 'rb-badge ' + (running ? 'rb-badge-run' : 'rb-badge-idle');
}

function rbGenId() {
    var a = new Uint8Array(8);
    crypto.getRandomValues(a);
    return Array.from(a, function(b) { return b.toString(16).padStart(2, '0'); }).join('');
}

function rbTextToArr(el) {
    if (!el) return [];
    return el.value.split('\n').map(function(l) { return l.trim(); }).filter(function(l) { return l; });
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
}

function rbMsg(text, type) {
    if (typeof swal === 'function') {
        swal({ title: type === 'success' ? 'Success' : 'Error', text: text, type: type === 'success' ? 'success' : 'error' });
    } else {
        alert(text);
    }
}

// =============================================================================
// BROWSE BACKUPS
// =============================================================================
var rbSnapCtx = { jobId: null, targetId: null, snapshotId: null, shortId: null,
                  currentPath: '/', basePath: '/', pathStack: [] };
var rbSnapCache = {};     // key: snapshotId + ':' + path → items[] (legacy, unused when index present)
var rbSnapIndex = {};     // key: snapshotId → flat entries[] (all files/dirs from one prefetch call)

// Filter flat index to direct children of path, sort dirs-first
function rbSnapFilterLocal(entries, path) {
    var norm   = (path === '/') ? '' : path.replace(/\/$/, '');
    var prefix = (norm === '') ? '/' : norm + '/';
    var items  = entries.filter(function(e) {
        var p = e.path;
        if (!p || p === norm) return false;
        if (p.indexOf(prefix) !== 0) return false;
        var rest = p.slice(prefix.length);
        return rest !== '' && rest.indexOf('/') === -1;
    });
    items.sort(function(a, b) {
        var ad = a.type === 'dir' ? 0 : 1, bd = b.type === 'dir' ? 0 : 1;
        if (ad !== bd) return ad - bd;
        return (a.name || '').localeCompare(b.name || '');
    });
    return items;
}

function rbSnapJobChange() {
    var jobId = document.getElementById('snap-job-sel').value;
    var targetSel = document.getElementById('snap-target-sel');
    var targetRow = document.getElementById('snap-target-row');
    rbSnapCtx.jobId = jobId;
    targetSel.innerHTML = '';

    if (!jobId) { targetRow.style.display = 'none'; return; }

    var panel = document.querySelector('.rb-job-panel[data-job-id="' + jobId + '"]');
    if (!panel) { targetRow.style.display = 'none'; return; }

    var targets = panel.querySelectorAll('.job-targets .rb-card');
    targets.forEach(function(card) {
        var opt = document.createElement('option');
        opt.value = card.getAttribute('data-id');
        var pfx  = card.querySelector('.rb-url-pfx');
        var url  = card.querySelector('.target-url');
        var name = card.querySelector('.target-name');
        var label = (name && name.value.trim()) ||
                    ((pfx && pfx.style.display !== 'none' ? pfx.textContent : '') + (url ? url.value.trim() : '')) ||
                    'Target';
        opt.textContent = label;
        targetSel.appendChild(opt);
    });

    targetRow.style.display = targets.length > 1 ? '' : 'none';
    rbSnapCtx.targetId = targetSel.value;
}

function rbLoadSnapshots() {
    if (!rbSnapCtx.jobId) { rbMsg('Please select a job.', 'error'); return; }
    rbSnapCtx.targetId = document.getElementById('snap-target-sel').value;

    var btn = document.getElementById('btn-load-snaps');
    btn.disabled = true; btn.textContent = 'Loading...';

    rbAjax('job_snapshots', { job_id: rbSnapCtx.jobId, target_id: rbSnapCtx.targetId }, function(resp) {
        btn.disabled = false; btn.textContent = 'Load Snapshots';
        if (!Array.isArray(resp)) {
            rbMsg('Error: ' + (resp.message || JSON.stringify(resp)), 'error'); return;
        }

        var tbody = document.getElementById('snap-tbody');
        tbody.innerHTML = '';
        if (resp.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="padding:12px;text-align:center;color:var(--text-muted);">No snapshots found</td></tr>';
        } else {
            // restic returns snapshots oldest-first; latest = last entry
            var latest = resp[resp.length - 1];

            // Pinned "latest" row
            (function(snap) {
                var d = new Date(snap.time);
                var date = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0')
                         + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
                var tags = (snap.tags || []).join(', ') || '-';
                var paths = snap.paths || [];
                var tr = document.createElement('tr');
                tr.style.background = 'rgba(240,140,0,.07)';
                tr.innerHTML = '<td><input type="checkbox" class="snap-check" value="' + escAttr(snap.id) + '" onchange="rbSnapUpdateCount()"></td>'
                    + '<td style="font-family:monospace;"><span style="color:var(--accent);font-size:.78em;font-weight:700;margin-right:4px;">LATEST</span>' + escHtml(snap.short_id) + '</td>'
                    + '<td>' + escHtml(date) + '</td>'
                    + '<td>' + escHtml(snap.hostname || '-') + '</td>'
                    + '<td>' + escHtml(tags) + '</td>'
                    + '<td><button class="rb-btn rb-btn-accent rb-btn-sm" onclick="rbOpenSnapBrowser('
                    + escAttr(JSON.stringify(snap.id)) + ',' + escAttr(JSON.stringify(snap.short_id)) + ',' + escAttr(JSON.stringify(paths))
                    + ')">Browse</button></td>';
                tbody.appendChild(tr);
            })(latest);

            resp.forEach(function(snap) {
                var d = new Date(snap.time);
                var date = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0')
                         + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
                var tags = (snap.tags || []).join(', ') || '-';
                var paths = snap.paths || [];
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><input type="checkbox" class="snap-check" value="' + escAttr(snap.id) + '" onchange="rbSnapUpdateCount()"></td>'
                    + '<td style="font-family:monospace;">' + escHtml(snap.short_id) + '</td>'
                    + '<td>' + escHtml(date) + '</td>'
                    + '<td>' + escHtml(snap.hostname || '-') + '</td>'
                    + '<td>' + escHtml(tags) + '</td>'
                    + '<td><button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbOpenSnapBrowser('
                    + escAttr(JSON.stringify(snap.id)) + ',' + escAttr(JSON.stringify(snap.short_id)) + ',' + escAttr(JSON.stringify(paths))
                    + ')">Browse</button></td>';
                tbody.appendChild(tr);
            });
            rbSnapUpdateCount();
        }
        document.getElementById('snap-list').style.display = '';
        document.getElementById('snap-browser').style.display = 'none';
        // Reveal the "Find files across snapshots" panel whenever we have a repo
        var findBox = document.getElementById('snap-find');
        if (findBox) findBox.style.display = '';
    }, function(err) {
        btn.disabled = false; btn.textContent = 'Load Snapshots';
        rbMsg('Failed: ' + err, 'error');
    });
}

function rbOpenSnapBrowser(snapshotId, shortId, paths) {
    // Clear caches when switching to a different snapshot
    if (rbSnapCtx.snapshotId !== snapshotId) {
        rbSnapCache = {};
        // keep rbSnapIndex — entries are valid until page reload
    }
    rbSnapCtx.snapshotId  = snapshotId;
    rbSnapCtx.shortId     = shortId;
    rbSnapCtx.pathStack   = [];
    // Restic stores content paths from '/' regardless of the host backup dir
    rbSnapCtx.basePath    = '/';
    rbSnapCtx.currentPath = '/';

    document.getElementById('snap-browser-id').textContent    = shortId;
    document.getElementById('snap-restore-msg').style.display = 'none';
    document.getElementById('snap-browser').style.display     = '';
    rbSnapBrowse('/');
}

// Build breadcrumb HTML relative to basePath
function rbSnapBuildCrumbs(path) {
    var base = rbSnapCtx.basePath.replace(/\/$/, '');
    var cur  = path.replace(/\/$/, '') || '/';
    var html = '<span class="snap-crumb" onclick="rbSnapBrowse(' + escAttr(JSON.stringify(base)) + ')">&#8962; root</span>';
    if (cur === base) return html;
    var suffix = cur.startsWith(base) ? cur.slice(base.length).replace(/^\//, '') : cur.replace(/^\//, '');
    var parts  = suffix ? suffix.split('/') : [];
    var built  = base;
    parts.forEach(function(part, i) {
        built += '/' + part;
        html += '<span class="snap-crumb-sep"> / </span>';
        if (i === parts.length - 1) {
            html += '<span style="color:var(--text);">' + escHtml(part) + '</span>';
        } else {
            var p = built;
            html += '<span class="snap-crumb" onclick="rbSnapBrowse(' + escAttr(JSON.stringify(p)) + ')">' + escHtml(part) + '</span>';
        }
    });
    return html;
}

function rbSnapRenderItems(items, path) {
    document.getElementById('snap-browser-path').innerHTML = rbSnapBuildCrumbs(path);
    var list = document.getElementById('snap-browser-list');
    if (items.length === 0) {
        list.innerHTML = '<div style="padding:10px;color:var(--text-muted);">Empty directory</div>';
        return;
    }
    var html = '<div class="snap-file-hdr">'
        + '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">'
        + '<input type="checkbox" id="snap-check-all" autocomplete="off" onchange="rbSnapToggleAll(this.checked)"> All</label>'
        + '</div>';
    items.forEach(function(item) {
        var isDir = item.type === 'dir';
        var icon  = isDir ? '&#128193;' : '&#128196;';
        var size  = !isDir ? '<span class="snap-size">' + rbFmtBytes(item.size) + '</span>' : '';
        var enter = isDir ? '<button class="snap-enter-btn" onclick="rbSnapEnter(' + escAttr(JSON.stringify(item.path)) + ')" title="Open folder">&#8594;</button>' : '';
        html += '<div class="snap-file-row">'
            + '<input type="checkbox" class="snap-check" autocomplete="off" data-path="' + escAttr(item.path) + '">'
            + '<span class="snap-file-icon">' + icon + '</span>'
            + '<span class="snap-file-name' + (isDir ? ' snap-dir-name' : '') + '"'
            + (isDir ? ' onclick="rbSnapEnter(' + escAttr(JSON.stringify(item.path)) + ')"' : '')
            + '>' + escHtml(item.name) + '</span>'
            + size + enter
            + '</div>';
    });
    list.innerHTML = html;
}

function rbSnapBrowse(path) {
    rbSnapCtx.currentPath = path;

    // If full index already loaded, filter locally — zero server round-trips
    if (rbSnapIndex[rbSnapCtx.snapshotId]) {
        var items = rbSnapFilterLocal(rbSnapIndex[rbSnapCtx.snapshotId], path);
        rbSnapRenderItems(items, path);
        return;
    }

    document.getElementById('snap-browser-path').innerHTML = rbSnapBuildCrumbs(path);
    var list = document.getElementById('snap-browser-list');
    list.innerHTML = '<div style="padding:10px;color:var(--text-muted);">Loading\u2026 (first load fetches full index)</div>';

    // Fetch the full flat listing once; all navigation will be local after this
    rbAjax('snapshot_prefetch', {
        job_id:      rbSnapCtx.jobId,
        target_id:   rbSnapCtx.targetId,
        snapshot_id: rbSnapCtx.snapshotId
    }, function(resp) {
        if (resp.status !== 'success') {
            list.innerHTML = '<div style="padding:10px;color:var(--red);">Error: ' + escHtml(resp.message || '?') + '</div>';
            return;
        }
        rbSnapIndex[rbSnapCtx.snapshotId] = resp.entries || [];
        var items = rbSnapFilterLocal(rbSnapIndex[rbSnapCtx.snapshotId], path);
        rbSnapRenderItems(items, path);
    }, function(err) {
        list.innerHTML = '<div style="padding:10px;color:var(--red);">Error: ' + escHtml(err) + '</div>';
    });
}

function rbSnapEnter(path) {
    rbSnapCtx.pathStack.push(rbSnapCtx.currentPath);
    rbSnapBrowse(path);
}

function rbSnapBrowserUp() {
    if (rbSnapCtx.pathStack.length > 0) {
        rbSnapBrowse(rbSnapCtx.pathStack.pop());
    } else {
        rbSnapBrowse(rbSnapCtx.basePath);
    }
}

function rbSnapToggleAll(checked) {
    var boxes = document.querySelectorAll('#snap-browser-list .snap-check');
    boxes.forEach(function(b) { b.checked = checked; });
}

function rbSnapRestore() {
    var dest = document.getElementById('snap-restore-dest').value.trim();
    if (!dest) { rbMsg('Please enter a destination path.', 'error'); return; }

    var checked = Array.from(document.querySelectorAll('#snap-browser-list .snap-check:checked'));
    if (checked.length === 0) { rbMsg('Please select at least one item to restore.', 'error'); return; }
    var paths = checked.map(function(c) { return c.getAttribute('data-path'); });

    var msg = document.getElementById('snap-restore-msg');
    msg.textContent = 'Starting restore of ' + paths.length + ' item(s)\u2026';
    msg.style.color = '#e3b341';
    msg.style.display = 'block';

    rbAjax('snapshot_restore', {
        job_id:        rbSnapCtx.jobId,
        target_id:     rbSnapCtx.targetId,
        snapshot_id:   rbSnapCtx.snapshotId,
        include_paths: paths,
        dest:          dest
    }, function(resp) {
        if (resp.status === 'started') {
            msg.textContent = resp.message || 'Restore started.';
            msg.style.color = 'var(--green)';
        } else {
            msg.textContent = 'Error: ' + (resp.message || JSON.stringify(resp));
            msg.style.color = 'var(--red)';
        }
    }, function(err) {
        msg.textContent = 'Error: ' + err;
        msg.style.color = 'var(--red)';
    });
}

function rbFmtBytes(bytes) {
    if (!bytes) return '0 B';
    var u = ['B','KB','MB','GB','TB'], i = 0;
    while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
    return bytes.toFixed(i > 0 ? 1 : 0) + '\u00a0' + u[i];
}

// =============================================================================
// FIND FILES ACROSS SNAPSHOTS (restic find)
//
// Runs a single `restic find --json` over the current job/target and renders
// results grouped by snapshot. Clicking a hit opens that snapshot's browser
// navigated to the containing directory so the file can be restored.
// =============================================================================
function rbSnapFind() {
    if (!rbSnapCtx.jobId) { rbMsg('Please select a job first.', 'error'); return; }
    rbSnapCtx.targetId = document.getElementById('snap-target-sel').value;

    var pattern = document.getElementById('snap-find-pattern').value.trim();
    var msg     = document.getElementById('snap-find-msg');
    var out     = document.getElementById('snap-find-results');
    if (!pattern) {
        msg.textContent = 'Please enter a pattern.';
        msg.style.color = 'var(--red)';
        msg.style.display = 'block';
        return;
    }

    msg.textContent = 'Searching all snapshots for "' + pattern + '"\u2026';
    msg.style.color = '#e3b341';
    msg.style.display = 'block';
    out.innerHTML = '';

    rbAjax('job_find', {
        job_id:      rbSnapCtx.jobId,
        target_id:   rbSnapCtx.targetId,
        pattern:     pattern,
        ignore_case: document.getElementById('snap-find-ci').checked ? '1' : '',
        newest:      document.getElementById('snap-find-newest').value
    }, function(resp) {
        if (resp.status !== 'success') {
            msg.textContent = 'Error: ' + (resp.message || 'unknown');
            msg.style.color = 'var(--red)';
            return;
        }
        var groups = resp.results || [];
        var hitCount = 0;
        groups.forEach(function(g) { hitCount += (g.matches || []).length; });

        if (hitCount === 0) {
            msg.textContent = 'No matches found in any snapshot.';
            msg.style.color = 'var(--text-muted)';
            return;
        }

        msg.textContent = hitCount + ' match(es) in ' + groups.length + ' snapshot(s).';
        msg.style.color = 'var(--green)';

        var html = '';
        groups.forEach(function(g) {
            var snapId  = g.snapshot || '';
            var shortId = snapId.substring(0, 8);
            var matches = g.matches || [];
            html += '<div style="margin-bottom:10px;border:1px solid var(--border);border-radius:4px;overflow:hidden;">'
                +    '<div style="padding:6px 10px;background:var(--bg-secondary);font-size:.85em;">'
                +      '<strong style="font-family:monospace;color:var(--accent);">' + escHtml(shortId) + '</strong>'
                +      '&nbsp;&middot;&nbsp;' + matches.length + ' match' + (matches.length !== 1 ? 'es' : '')
                +    '</div>'
                +    '<div style="max-height:240px;overflow-y:auto;">';
            matches.forEach(function(m) {
                var path = m.path || '';
                var size = typeof m.size === 'number' ? rbFmtBytes(m.size) : '';
                var type = m.type || 'file';
                var dir  = path.replace(/\/[^\/]*$/, '') || '/';
                html += '<div class="rb-find-hit" style="display:flex;align-items:center;gap:8px;padding:4px 10px;border-top:1px solid var(--border-inner);font-size:.85em;">'
                    +     '<span style="flex:1;word-break:break-all;font-family:monospace;">' + escHtml(path) + '</span>'
                    +     (size ? '<span style="color:var(--text-muted);">' + size + '</span>' : '')
                    +     '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbSnapFindOpen('
                    +       escAttr(JSON.stringify(snapId)) + ','
                    +       escAttr(JSON.stringify(shortId)) + ','
                    +       escAttr(JSON.stringify(type === 'dir' ? path : dir))
                    +     ')">Open</button>'
                    +   '</div>';
            });
            html +=   '</div>'
                + '</div>';
        });
        out.innerHTML = html;
    }, function(err) {
        msg.textContent = 'Error: ' + err;
        msg.style.color = 'var(--red)';
    });
}

function rbSnapFindOpen(snapshotId, shortId, targetPath) {
    // Jump to the snapshot browser at the directory containing the hit.
    rbOpenSnapBrowser(snapshotId, shortId, []);
    // rbOpenSnapBrowser navigates to '/' asynchronously; give it a tick,
    // then navigate to the target directory.
    setTimeout(function() {
        if (typeof rbSnapBrowse === 'function') {
            rbSnapBrowse(targetPath || '/');
        }
        var el = document.getElementById('snap-browser');
        if (el && el.scrollIntoView) el.scrollIntoView({behavior:'smooth', block:'start'});
    }, 80);
}

// =============================================================================
// REPOSITORY TOOLS (unlock / stats / check / recover / self-update)
// =============================================================================
var rbRepoCtx = { jobId: null, targetId: null };

function rbRepoJobChange() {
    var jobId = document.getElementById('repo-job-sel').value;
    var targetSel = document.getElementById('repo-target-sel');
    var targetRow = document.getElementById('repo-target-row');
    rbRepoCtx.jobId = jobId;
    targetSel.innerHTML = '';
    if (!jobId) { targetRow.style.display = 'none'; rbRepoCopyPopulateDstJobs(); return; }
    var panel = document.querySelector('.rb-job-panel[data-job-id="' + jobId + '"]');
    if (!panel) { targetRow.style.display = 'none'; rbRepoCopyPopulateDstJobs(); return; }
    var targets = panel.querySelectorAll('.job-targets .rb-card');
    targets.forEach(function(card) {
        var opt = document.createElement('option');
        opt.value = card.getAttribute('data-id');
        var pfx  = card.querySelector('.rb-url-pfx');
        var url  = card.querySelector('.target-url');
        var name = card.querySelector('.target-name');
        opt.textContent = (name && name.value.trim()) ||
                          ((pfx && pfx.style.display !== 'none' ? pfx.textContent : '') + (url ? url.value.trim() : '')) ||
                          'Target';
        targetSel.appendChild(opt);
    });
    targetRow.style.display = targets.length > 1 ? '' : 'none';
    targetSel.onchange = function() { rbRepoCtx.targetId = this.value; };
    rbRepoCtx.targetId = targetSel.value;
    rbRepoCopyPopulateDstJobs();
}

// Populate Copy-Whole-Repo destination job + target selects with every job/target
// currently configured, minus the source target itself.
function rbRepoCopyPopulateDstJobs() {
    var dstJob = document.getElementById('repo-copy-dst-job');
    if (!dstJob) return;
    var prev = dstJob.value;
    dstJob.innerHTML = '';
    document.querySelectorAll('.rb-job-panel').forEach(function(panel) {
        var id   = panel.getAttribute('data-job-id');
        var nin  = panel.querySelector('.job-name');
        var name = (nin && nin.value.trim()) || ('Job ' + id);
        var opt  = document.createElement('option');
        opt.value = id;
        opt.textContent = name;
        dstJob.appendChild(opt);
    });
    if (prev) dstJob.value = prev;
    rbRepoCopyDstJobChange();
}

function rbRepoCopyDstJobChange() {
    var dstJob = document.getElementById('repo-copy-dst-job');
    var dstTgt = document.getElementById('repo-copy-dst-target');
    if (!dstJob || !dstTgt) return;
    dstTgt.innerHTML = '';
    var panel = document.querySelector('.rb-job-panel[data-job-id="' + dstJob.value + '"]');
    if (!panel) return;
    panel.querySelectorAll('.job-targets .rb-card').forEach(function(card) {
        var id = card.getAttribute('data-id');
        // Skip source target
        if (dstJob.value === rbRepoCtx.jobId && id === rbRepoCtx.targetId) return;
        var pfx  = card.querySelector('.rb-url-pfx');
        var url  = card.querySelector('.target-url');
        var name = card.querySelector('.target-name');
        var opt = document.createElement('option');
        opt.value = id;
        opt.textContent = (name && name.value.trim()) ||
                          ((pfx && pfx.style.display !== 'none' ? pfx.textContent : '') + (url ? url.value.trim() : '')) ||
                          'Target';
        dstTgt.appendChild(opt);
    });
}

function rbRepoCopyAll() {
    if (!rbRepoRequire()) return;
    var dstJob = document.getElementById('repo-copy-dst-job').value;
    var dstTgt = document.getElementById('repo-copy-dst-target').value;
    if (!dstTgt) { rbRepoShow('repo-copy-msg', 'Please pick a destination target.', 'error'); return; }
    if (dstJob === rbRepoCtx.jobId && dstTgt === rbRepoCtx.targetId) {
        rbRepoShow('repo-copy-msg', 'Source and destination must differ.', 'error'); return;
    }
    if (!confirm('Copy ALL snapshots from the selected source to the destination repository?\n\nNote: for best dedup the destination should have been initialised with `restic init --copy-chunker-params`.')) return;
    rbRepoShow('repo-copy-msg', 'Starting copy…', 'warn');
    rbAjax('snapshot_copy', {
        job_id:        rbRepoCtx.jobId,
        src_target_id: rbRepoCtx.targetId,
        dst_job_id:    dstJob,
        dst_target_id: dstTgt,
        snapshot_ids:  []   // empty = copy all
    }, function(resp) {
        var started = resp && (resp.status === 'started' || resp.status === 'success');
        rbRepoShow('repo-copy-msg', (resp && resp.message) || 'Started', started ? 'ok' : 'error');
        if (started && resp.logfile) rbRepoStartPoll('copy', resp.logfile);
    });
}

function rbRepoShow(id, text, tone) {
    var el = document.getElementById(id);
    if (!el) return;
    el.style.display = '';
    el.textContent = text;
    el.style.borderLeft = '3px solid ' + (tone === 'error' ? '#d33' : tone === 'warn' ? '#d80' : '#2a7');
}

function rbRepoRequire() {
    if (!rbRepoCtx.jobId) { rbMsg('Please select a job.', 'error'); return false; }
    rbRepoCtx.targetId = document.getElementById('repo-target-sel').value;
    return true;
}

function rbHumanBytes(n) {
    if (!n || n <= 0) return '0 B';
    var u = ['B','KiB','MiB','GiB','TiB','PiB'];
    var i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return n.toFixed(n < 10 ? 2 : 1) + ' ' + u[i];
}

function rbRepoStats() {
    if (!rbRepoRequire()) return;
    var mode = document.getElementById('repo-stats-mode').value;
    var out = document.getElementById('repo-stats-out');
    out.style.display = ''; out.textContent = 'Loading stats…';
    rbAjax('repo_stats', { job_id: rbRepoCtx.jobId, target_id: rbRepoCtx.targetId, mode: mode }, function(resp) {
        if (resp && resp.status === 'success' && resp.stats) {
            var s = resp.stats;
            var lines = ['Mode: ' + (resp.mode || mode)];
            if (s.total_size != null)         lines.push('Total Size:           ' + rbHumanBytes(s.total_size) + '   (' + s.total_size + ' bytes)');
            if (s.total_uncompressed_size != null)
                                              lines.push('Uncompressed Size:    ' + rbHumanBytes(s.total_uncompressed_size));
            if (s.compression_ratio != null)  lines.push('Compression Ratio:    ' + Number(s.compression_ratio).toFixed(3));
            if (s.compression_progress != null) lines.push('Compression Progress: ' + s.compression_progress + '%');
            if (s.compression_space_saving != null) lines.push('Space Saved:          ' + Number(s.compression_space_saving).toFixed(1) + '%');
            if (s.total_file_count != null)   lines.push('Total File Count:     ' + s.total_file_count);
            if (s.total_blob_count != null)   lines.push('Total Blob Count:     ' + s.total_blob_count);
            if (s.snapshots_count != null)    lines.push('Snapshots:            ' + s.snapshots_count);
            out.textContent = lines.join('\n');
        } else {
            out.textContent = 'Error: ' + ((resp && resp.message) || 'unknown');
        }
    }, function(err) {
        out.textContent = 'Error: ' + err;
    });
}

function rbRepoUnlock() {
    if (!rbRepoRequire()) return;
    var all = document.getElementById('repo-unlock-all').checked;
    rbRepoShow('repo-unlock-msg', 'Running unlock…', 'warn');
    rbAjax('repo_unlock', { job_id: rbRepoCtx.jobId, target_id: rbRepoCtx.targetId, remove_all: all ? 1 : 0 }, function(resp) {
        rbRepoShow('repo-unlock-msg', (resp && resp.message) || 'Done',
                   resp && resp.status === 'success' ? 'ok' : 'error');
    });
}

// Poll handles keyed by tool name ("check" / "recover"), so we can stop them.
var rbRepoPollTimers = {};

function rbRepoToolStop(tool) {
    if (rbRepoPollTimers[tool]) {
        clearInterval(rbRepoPollTimers[tool]);
        rbRepoPollTimers[tool] = null;
    }
    var btn = document.getElementById('repo-' + tool + '-stop');
    if (btn) btn.style.display = 'none';
}

function rbRepoStartPoll(tool, logfile) {
    rbRepoToolStop(tool);
    var outEl  = document.getElementById('repo-' + tool + '-out');
    var msgEl  = document.getElementById('repo-' + tool + '-msg');
    var stopBt = document.getElementById('repo-' + tool + '-stop');
    if (!outEl || !logfile) return;
    outEl.style.display = '';
    outEl.textContent = '(waiting for output…)';
    if (stopBt) stopBt.style.display = '';

    var tick = function() {
        rbAjax('repo_tool_log', { logfile: logfile }, function(resp) {
            if (!resp) return;
            if (resp.status === 'error') {
                outEl.textContent = 'Error: ' + (resp.message || 'cannot read log');
                rbRepoToolStop(tool);
                return;
            }
            var text = (resp.text || '').replace(/\r/g, '');
            // Keep scroll at bottom if user hasn't scrolled up
            var atBottom = (outEl.scrollTop + outEl.clientHeight + 30 >= outEl.scrollHeight);
            outEl.textContent = text || '(waiting for output…)';
            if (atBottom) outEl.scrollTop = outEl.scrollHeight;
            if (resp.done) {
                rbRepoShow('repo-' + tool + '-msg',
                           /fatal|error|errors were/i.test(text) ? 'Finished with errors.' : 'Finished.',
                           /fatal|error|errors were/i.test(text) ? 'error' : 'ok');
                rbRepoToolStop(tool);
            }
        });
    };
    // First tick immediately, then every 2s
    tick();
    rbRepoPollTimers[tool] = setInterval(tick, 2000);
}

function rbRepoCheck() {
    if (!rbRepoRequire()) return;
    var subset = document.getElementById('repo-check-subset').value.trim();
    if (!confirm('Start integrity check' + (subset ? ' with subset ' + subset : '') + '? This can take a while.')) return;
    rbRepoShow('repo-check-msg', 'Starting…', 'warn');
    rbAjax('repo_check', { job_id: rbRepoCtx.jobId, target_id: rbRepoCtx.targetId, subset: subset, read_data: subset ? 0 : 0 }, function(resp) {
        var started = resp && (resp.status === 'started' || resp.status === 'success');
        rbRepoShow('repo-check-msg', (resp && resp.message) || 'Started', started ? 'ok' : 'error');
        if (started && resp.logfile) rbRepoStartPoll('check', resp.logfile);
    });
}

function rbRepoRecover() {
    if (!rbRepoRequire()) return;
    if (!confirm('Start restic recover? This scans the entire repo for orphaned pack files.')) return;
    rbRepoShow('repo-recover-msg', 'Starting…', 'warn');
    rbAjax('repo_recover', { job_id: rbRepoCtx.jobId, target_id: rbRepoCtx.targetId }, function(resp) {
        var started = resp && (resp.status === 'started' || resp.status === 'success');
        rbRepoShow('repo-recover-msg', (resp && resp.message) || 'Started', started ? 'ok' : 'error');
        if (started && resp.logfile) rbRepoStartPoll('recover', resp.logfile);
    });
}

function rbResticSelfUpdate() {
    if (!confirm('Upgrade /usr/local/bin/restic to the latest upstream release?')) return;
    rbRepoShow('repo-selfup-msg', 'Upgrading…', 'warn');
    rbAjax('restic_self_update', {}, function(resp) {
        var txt = (resp && resp.message) ? resp.message : 'Done';
        if (resp && resp.version) txt += '\n\nVersion: ' + resp.version;
        rbRepoShow('repo-selfup-msg', txt,
                   resp && resp.status === 'success' ? 'ok' : 'error');
    });
}

// =============================================================================
// SNAPSHOT MULTI-SELECT ACTIONS (diff / tag / forget)
// =============================================================================
function rbSnapSelectedIds() {
    var ids = [];
    document.querySelectorAll('#snap-tbody .snap-check:checked').forEach(function(cb) {
        ids.push(cb.value);
    });
    return ids;
}

function rbSnapUpdateCount() {
    var n = rbSnapSelectedIds().length;
    var el = document.getElementById('snap-selected-count');
    if (el) el.textContent = String(n);
}

function rbSnapToggleAll(chk) {
    document.querySelectorAll('#snap-tbody .snap-check').forEach(function(cb) {
        cb.checked = chk.checked;
    });
    rbSnapUpdateCount();
}

function rbSnapActionMsg(text, tone) {
    var el = document.getElementById('snap-action-msg');
    if (!el) return;
    el.style.display = '';
    el.textContent = text;
    el.style.borderLeft = '3px solid ' + (tone === 'error' ? '#d33' : tone === 'warn' ? '#d80' : '#2a7');
}

function rbSnapDiff() {
    var ids = rbSnapSelectedIds();
    if (ids.length !== 2) {
        rbSnapActionMsg('Please select exactly 2 snapshots to diff.', 'error');
        return;
    }
    var out = document.getElementById('snap-diff-out');
    out.style.display = '';
    out.textContent = 'Computing diff…';
    rbAjax('snapshot_diff', {
        job_id:      rbSnapCtx.jobId,
        target_id:   rbSnapCtx.targetId,
        snapshot_a:  ids[0],
        snapshot_b:  ids[1]
    }, function(resp) {
        if (!resp || resp.status !== 'success') {
            out.textContent = 'Error: ' + ((resp && resp.message) || 'unknown');
            return;
        }
        var lines = [];
        var stats = { added: 0, removed: 0, changed: 0 };
        (resp.changes || []).forEach(function(c) {
            var mark = c.modifier || c.type || '?';
            var path = c.path || '';
            if (mark.indexOf('+') !== -1) stats.added++;
            else if (mark.indexOf('-') !== -1) stats.removed++;
            else stats.changed++;
            lines.push(mark + '  ' + path);
        });
        if (resp.summary) {
            var s = resp.summary;
            var hdr = 'Summary: ';
            if (s.changed_files != null)  hdr += 'changed=' + s.changed_files + ' ';
            if (s.added_files != null)    hdr += 'added=' + s.added_files + ' ';
            if (s.removed_files != null)  hdr += 'removed=' + s.removed_files + ' ';
            if (s.added_bytes != null)    hdr += ' +' + rbHumanBytes(s.added_bytes);
            if (s.removed_bytes != null)  hdr += ' -' + rbHumanBytes(s.removed_bytes);
            lines.unshift('');
            lines.unshift(hdr);
        }
        if (lines.length === 0) lines = ['(no changes)'];
        out.textContent = lines.join('\n');
    }, function(err) {
        out.textContent = 'Error: ' + err;
    });
}

function rbSnapTagPrompt(op) {
    var ids = rbSnapSelectedIds();
    if (ids.length === 0) {
        rbSnapActionMsg('Select at least one snapshot.', 'error');
        return;
    }
    var label = op === 'add' ? 'Tags to ADD (comma-separated):'
              : op === 'remove' ? 'Tags to REMOVE (comma-separated):'
              : 'Tags to SET (comma-separated, replaces existing):';
    var tags = prompt(label, '');
    if (tags === null) return;
    rbSnapActionMsg('Updating tags on ' + ids.length + ' snapshot(s)…', 'warn');
    rbAjax('snapshot_tag', {
        job_id:       rbSnapCtx.jobId,
        target_id:    rbSnapCtx.targetId,
        snapshot_ids: ids,
        op:           op,
        tags:         tags
    }, function(resp) {
        if (resp && resp.status === 'success') {
            rbSnapActionMsg('Tags updated. Reload snapshots to see changes.', 'ok');
        } else {
            rbSnapActionMsg('Error: ' + ((resp && resp.message) || 'unknown'), 'error');
        }
    });
}

function rbSnapForget(prune) {
    var ids = rbSnapSelectedIds();
    if (ids.length === 0) {
        rbSnapActionMsg('Select at least one snapshot.', 'error');
        return;
    }
    var msg = 'Forget ' + ids.length + ' snapshot(s)'
            + (prune ? ' AND prune the repository' : '')
            + '? This cannot be undone.';
    if (!confirm(msg)) return;
    rbSnapActionMsg('Running forget' + (prune ? ' --prune' : '') + '…', 'warn');
    rbAjax('snapshot_forget', {
        job_id:       rbSnapCtx.jobId,
        target_id:    rbSnapCtx.targetId,
        snapshot_ids: ids,
        prune:        prune ? 1 : 0
    }, function(resp) {
        if (resp && (resp.status === 'started' || resp.status === 'success')) {
            rbSnapActionMsg((resp.message || 'Started') + (resp.logfile ? '  (log: ' + resp.logfile + ')' : ''), 'ok');
        } else {
            rbSnapActionMsg('Error: ' + ((resp && resp.message) || 'unknown'), 'error');
        }
    });
}

function rbSnapRewritePrompt() {
    var ids = rbSnapSelectedIds();
    if (ids.length === 0) {
        rbSnapActionMsg('Select at least one snapshot.', 'error');
        return;
    }
    var paths = prompt(
        'Path(s) to EXCLUDE from the selected snapshot(s).\n' +
        'One path or pattern per line (restic exclude syntax).\n\n' +
        'This rewrites the snapshots into new snapshots without the excluded files.\n' +
        'The OLD snapshots are kept unless you tick "Forget old snapshots" in the next prompt.',
        ''
    );
    if (paths === null) return;
    var excludes = paths.split(/\r?\n/).map(function(s){ return s.trim(); }).filter(Boolean);
    if (!excludes.length) {
        rbSnapActionMsg('No exclude patterns given.', 'error');
        return;
    }
    var forget = confirm(
        'Also forget the ORIGINAL snapshots after rewriting?\n\n' +
        'OK = forget old  (only the rewritten copies remain)\n' +
        'Cancel = keep both'
    );
    if (!confirm('Rewrite ' + ids.length + ' snapshot(s)?\nExcludes:\n  ' + excludes.join('\n  ')
                 + '\n\n' + (forget ? 'Old snapshots WILL be forgotten.' : 'Old snapshots will be KEPT.'))) return;

    rbSnapActionMsg('Rewriting in background…', 'warn');
    rbAjax('snapshot_rewrite', {
        job_id:       rbSnapCtx.jobId,
        target_id:    rbSnapCtx.targetId,
        snapshot_ids: ids,
        excludes:     excludes,
        forget:       forget ? 1 : 0
    }, function(resp) {
        if (resp && (resp.status === 'started' || resp.status === 'success')) {
            rbSnapActionMsg((resp.message || 'Started') + (resp.logfile ? '  (log: ' + resp.logfile + ')' : ''), 'ok');
        } else {
            rbSnapActionMsg('Error: ' + ((resp && resp.message) || 'unknown'), 'error');
        }
    });
}

// rbSnapCopyPrompt was removed in 2026.04.18.05 — repo-level Copy lives in
// Repository Tools → Copy Entire Repo now. The snapshot_copy API endpoint
// still exists and is reused by rbRepoCopyAll.
