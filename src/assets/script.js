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
    var params = 'action=' + encodeURIComponent(action);
    if (data && Object.keys(data).length > 0) {
        params += '&data=' + encodeURIComponent(JSON.stringify(data));
    }

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
        // Extract JSON even if PHP warnings appear before it
        var jsonStart = text.indexOf('{');
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
// PASSWORD TOGGLE
// =============================================================================
function rbTogglePw() {
    var mode = document.getElementById('cfg-pw-mode').value;
    document.getElementById('row-pw-file').style.display = mode === 'file' ? 'flex' : 'none';
    document.getElementById('row-pw-inline').style.display = mode === 'inline' ? 'flex' : 'none';
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
    + '<div class="rb-row"><label>Recursive:</label><select class="zfs-recursive"><option value="1" selected>Yes (all child datasets)</option><option value="0">No</option></select></div>'
    + '<div class="rb-row"><label>Snapshot Prefix:</label><input type="text" class="zfs-prefix" value="restic-backup" style="max-width:200px;"></div>'
    + '<div style="margin-top:8px;"><label style="font-weight:bold;display:block;margin-bottom:6px;">Datasets:</label>'
    + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbLoadDatasets(this)" style="margin-bottom:8px;">Load Available Datasets</button>'
    + '<div class="zfs-ds-picker rb-ds-list" style="display:none;"></div>'
    + '<div class="zfs-ds-manual"></div>'
    + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbAddDatasetInput(this)" style="margin-top:4px;">+ Add Manually</button></div></div></div>'
    // Sources
    + '<div class="rb-section"><div class="rb-section-hdr closed" onclick="rbToggle(this)"><span>Source Directories</span><span class="arr">&#9660;</span></div>'
    + '<div class="rb-section-body hidden"><p style="color:var(--text-muted);margin:0 0 10px;">Directories to include in the backup. Click into a path field to browse.</p>'
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
function rbAddTarget(btn) {
    var list = btn.closest('.rb-section-body').querySelector('.job-targets');
    var n = list.querySelectorAll('.rb-card').length + 1;
    var id = rbGenId();
    var html = '<div class="rb-card" data-id="' + id + '">'
        + '<div class="rb-card-hdr"><span class="rb-card-title">Target #' + n + '</span><div style="display:flex;gap:4px;">'
        + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbInitRepo(this)">Init Repo</button>'
        + '<button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbTestTarget(this)">Test</button>'
        + '<button class="rb-btn rb-btn-red rb-btn-sm" onclick="this.closest(\'.rb-card\').remove()">Remove</button></div></div>'
        + '<div class="rb-row"><label>Type:</label><select class="target-type" onchange="rbTargetTypeChange(this)">'
        + '<option value="local">Local Path</option><option value="sftp">SFTP</option><option value="s3">S3 / Minio</option>'
        + '<option value="b2">Backblaze B2</option><option value="rest">REST Server</option><option value="rclone">Rclone</option></select></div>'
        + '<div class="rb-row"><label>Repository URL:</label><input type="text" class="target-url" value="" placeholder="/mnt/disks/backup/restic" data-picktree="dir"></div>'
        + '<div class="rb-row"><label>Name:</label><input type="text" class="target-name" value="" placeholder="e.g. Hetzner Cloud"></div>'
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
        + '<div class="rb-row"><label>Label:</label><input type="text" class="source-label" value="" placeholder="appdata"></div>'
        + '<div class="rb-row"><label>Enabled:</label><select class="source-enabled"><option value="1" selected>Yes</option><option value="0">No</option></select></div>'
        + '</div>';
    list.insertAdjacentHTML('beforeend', html);
    rbInitPickTree();
}

// =============================================================================
// INLINE DIRECTORY PICKER - appears below input on click
// =============================================================================
function rbInitPickTree() {
    document.querySelectorAll('[data-picktree]').forEach(function(input) {
        if (input._rbPickBound) return;
        input._rbPickBound = true;
        input.addEventListener('focus', function() {
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

    var startPath = input.value || '/mnt';
    if (startPath !== '/' && startPath.charAt(startPath.length - 1) !== '/') {
        var lastSlash = startPath.lastIndexOf('/');
        if (lastSlash > 0) startPath = startPath.substring(0, lastSlash);
    }

    var tree = document.createElement('div');
    tree.className = 'rb-tree';

    var hdr = document.createElement('div');
    hdr.className = 'rb-tree-hdr';
    hdr.innerHTML = '<span class="rb-tree-path">' + escHtml(startPath) + '</span>'
        + ' <button class="rb-btn rb-btn-green rb-btn-sm" style="margin-left:8px;" onclick="rbTreeSelect()">Select</button>'
        + ' <button class="rb-btn rb-btn-gray rb-btn-sm" onclick="rbCloseTree()">Close</button>';
    tree.appendChild(hdr);

    var list = document.createElement('div');
    list.className = 'rb-tree-list';
    list.innerHTML = '<div style="padding:8px;color:var(--text-muted);">Loading...</div>';
    tree.appendChild(list);

    input.closest('.rb-row').after(tree);
    rbActiveTree = tree;
    tree._rbInput = input;
    tree._rbPath = startPath;

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

function rbTreeSelect() {
    if (rbActiveTree && rbActiveTree._rbInput) {
        var path = rbActiveTree._rbPath.replace(/\/+$/, '') || '/';
        rbActiveTree._rbInput.value = path;
    }
    rbCloseTree();
}

function rbLoadTree(tree, path) {
    var list = tree.querySelector('.rb-tree-list');
    list.innerHTML = '<div style="padding:8px;color:var(--text-muted);">Loading...</div>';

    rbAjax('browse', { path: path }, function(resp) {
        tree._rbPath = resp.current || path;
        tree.querySelector('.rb-tree-path').textContent = tree._rbPath;

        var html = '';
        if (resp.parent && resp.parent !== resp.current) {
            html += '<div class="rb-tree-item rb-tree-up" data-path="' + escAttr(resp.parent) + '">'
                + '<span class="rb-tree-icon">&#8593;</span> ..</div>';
        }

        var dirs = resp.dirs || [];
        if (dirs.length === 0 && !resp.parent) {
            html = '<div style="padding:8px;color:var(--text-muted);">No directories found</div>';
        }
        dirs.forEach(function(d) {
            var name = d.split('/').pop();
            html += '<div class="rb-tree-item" data-path="' + escAttr(d) + '">'
                + '<span class="rb-tree-icon">&#128193;</span> ' + escHtml(name) + '</div>';
        });

        list.innerHTML = html;

        list.querySelectorAll('.rb-tree-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var p = item.getAttribute('data-path');
                tree._rbPath = p;
                tree.querySelector('.rb-tree-path').textContent = p;
                rbLoadTree(tree, p);
            });
        });
    }, function(err) {
        list.innerHTML = '<div style="padding:8px;color:var(--red);">Error: ' + escHtml(err) + '</div>';
    });
}

// =============================================================================
// TARGET TYPE CHANGE - show/hide browse for local only
// =============================================================================
function rbTargetTypeChange(sel) {
    var card = sel.closest('.rb-card');
    var urlInput = card.querySelector('.target-url');
    var type = sel.value;
    var placeholders = {
        'local': '/mnt/disks/backup/restic',
        'sftp': 'sftp://user@host:/path/to/repo',
        's3': 's3:https://s3.amazonaws.com/bucket/path',
        'b2': 'b2:bucketname:path',
        'rest': 'rest:http://host:8000/',
        'rclone': 'rclone:remote:path'
    };
    urlInput.placeholder = placeholders[type] || '';

    if (type === 'local') {
        urlInput.setAttribute('data-picktree', 'dir');
    } else {
        urlInput.removeAttribute('data-picktree');
        urlInput._rbPickBound = false;
    }
    rbInitPickTree();
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

function rbDsToggle(cb) {
    var picker = cb.closest('.zfs-ds-picker');
    var panel = cb.closest('.rb-job-panel') || cb.closest('.rb-section-body');
    var recursive = panel.querySelector('.zfs-recursive');
    var isRecursive = recursive && recursive.value === '1';
    var val = cb.value;

    if (isRecursive && cb.checked) {
        picker.querySelectorAll('.ds-cb').forEach(function(other) {
            if (other !== cb && other.value.indexOf(val + '/') === 0) {
                other.checked = false;
            }
        });
    }
    rbSyncDsToManual(panel);
}

function rbSyncDsToManual(panel) {
    var picker = panel.querySelector('.zfs-ds-picker');
    var manual = panel.querySelector('.zfs-ds-manual');
    if (!picker) return;
    manual.innerHTML = '';
    picker.querySelectorAll('.ds-cb:checked').forEach(function(cb) {
        var div = document.createElement('div');
        div.className = 'rb-row';
        div.innerHTML = '<input type="text" class="zfs-dataset" value="' + cb.value + '" readonly style="flex:1;opacity:.7;">'
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
            password_mode: document.getElementById('cfg-pw-mode').value,
            password_file: document.getElementById('cfg-pw-file').value.trim(),
            password_inline: document.getElementById('cfg-pw-inline').value,
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
            zfs: {
                enabled: panel.querySelector('.zfs-enabled').value === '1',
                recursive: panel.querySelector('.zfs-recursive').value === '1',
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
            tags: panel.querySelector('.job-tags').value.trim(),
            max_retries: parseInt(panel.querySelector('.job-retries').value) || 3,
            retry_wait: parseInt(panel.querySelector('.job-retry-wait').value) || 30
        };

        panel.querySelectorAll('.job-targets .rb-card').forEach(function(card) {
            job.targets.push({
                id: card.getAttribute('data-id') || rbGenId(),
                type: card.querySelector('.target-type').value,
                url: card.querySelector('.target-url').value.trim(),
                name: card.querySelector('.target-name').value.trim(),
                use_optional_excludes: card.querySelector('.target-opt-exc').value === '1',
                enabled: card.querySelector('.target-enabled').value === '1'
            });
        });

        panel.querySelectorAll('.job-sources .rb-card').forEach(function(card) {
            job.sources.push({
                id: card.getAttribute('data-id') || rbGenId(),
                path: card.querySelector('.source-path').value.trim(),
                label: card.querySelector('.source-label').value.trim(),
                enabled: card.querySelector('.source-enabled').value === '1'
            });
        });

        panel.querySelectorAll('.zfs-dataset').forEach(function(inp) {
            var v = inp.value.trim();
            if (v) job.zfs.datasets.push(v);
        });

        config.jobs.push(job);
    });

    return config;
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
    var pw = {
        password_mode: config.general.password_mode,
        password_file: config.general.password_file,
        password_inline: config.general.password_inline
    };

    config.jobs.forEach(function(job) {
        if (!job.enabled) return;
        job.targets.forEach(function(target) {
            if (!target.enabled || !target.url) return;

            var body = {
                url: target.url,
                password_mode: pw.password_mode,
                password_file: pw.password_file,
                password_inline: pw.password_inline
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
    rbAjax('backup', { job_id: jobId }, function() { rbStartLogPoll(); });
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
function rbGetPwConfig() {
    return {
        password_mode: document.getElementById('cfg-pw-mode').value,
        password_file: document.getElementById('cfg-pw-file').value.trim(),
        password_inline: document.getElementById('cfg-pw-inline').value
    };
}

function rbInitRepo(btn) {
    var card = btn.closest('.rb-card');
    var url = card.querySelector('.target-url').value.trim();
    if (!url) { rbMsg('Please enter a repository URL first.', 'error'); return; }
    btn.disabled = true; btn.textContent = 'Init...';
    var body = rbGetPwConfig();
    body.url = url;
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
    var body = rbGetPwConfig();
    body.url = url;
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
    rbAjax('log', {}, function(resp) {
        var el = document.getElementById('rb-log');
        el.textContent = (resp.log || '').trim() || 'No log data.';
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
            rbRefreshLog();
        }
    });
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
