/**
 * Restic Backup Plugin - Frontend JavaScript
 */

var resticLogTimer = null;
var resticBaseUrl = '/plugins/restic-backup/include/exec.php';

// =============================================================================
// SECTION TOGGLE
// =============================================================================

function toggleSection(header) {
    var body = header.nextElementSibling;
    header.classList.toggle('collapsed');
    body.classList.toggle('hidden');
}

// =============================================================================
// PASSWORD FIELD TOGGLE
// =============================================================================

function togglePasswordFields() {
    var mode = document.getElementById('cfg-password-mode').value;
    document.getElementById('row-password-file').style.display = mode === 'file' ? 'flex' : 'none';
    document.getElementById('row-password-inline').style.display = mode === 'inline' ? 'flex' : 'none';
}

// =============================================================================
// SCHEDULE PRESET
// =============================================================================

function applySchedulePreset() {
    var preset = document.getElementById('cfg-schedule-preset').value;
    if (preset) {
        document.getElementById('cfg-schedule-cron').value = preset;
    }
}

// =============================================================================
// DYNAMIC LIST: TARGETS
// =============================================================================

function addTarget() {
    var list = document.getElementById('targets-list');
    var count = list.children.length + 1;
    var id = generateId();

    var html = '<div class="restic-list-item" data-id="' + id + '">'
        + '<div class="item-header">'
        + '  <span class="item-title">Target #' + count + '</span>'
        + '  <div>'
        + '    <button class="restic-btn restic-btn-secondary" onclick="initRepo(this)">Init Repo</button>'
        + '    <button class="restic-btn restic-btn-secondary" onclick="testTarget(this)">Test</button>'
        + '    <button class="restic-btn restic-btn-danger" onclick="removeListItem(this)">Remove</button>'
        + '  </div>'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Repository URL:</label>'
        + '  <input type="text" class="target-url" value="" placeholder="sftp://host:/path or /mnt/disks/...">'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Name:</label>'
        + '  <input type="text" class="target-name" value="" placeholder="e.g. Hetzner Cloud">'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Use Optional Excludes:</label>'
        + '  <select class="target-optional-excludes">'
        + '    <option value="0" selected>No</option>'
        + '    <option value="1">Yes</option>'
        + '  </select>'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Enabled:</label>'
        + '  <select class="target-enabled">'
        + '    <option value="1" selected>Yes</option>'
        + '    <option value="0">No</option>'
        + '  </select>'
        + '</div>'
        + '</div>';

    list.insertAdjacentHTML('beforeend', html);
}

// =============================================================================
// DYNAMIC LIST: SOURCES
// =============================================================================

function addSource() {
    var list = document.getElementById('sources-list');
    var count = list.children.length + 1;
    var id = generateId();

    var html = '<div class="restic-list-item" data-id="' + id + '">'
        + '<div class="item-header">'
        + '  <span class="item-title">Source #' + count + '</span>'
        + '  <button class="restic-btn restic-btn-danger" onclick="removeListItem(this)">Remove</button>'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Path:</label>'
        + '  <input type="text" class="source-path" value="" placeholder="/mnt/user/appdata">'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Label:</label>'
        + '  <input type="text" class="source-label" value="" placeholder="appdata">'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Read-Only Mount:</label>'
        + '  <select class="source-readonly">'
        + '    <option value="1" selected>Yes</option>'
        + '    <option value="0">No</option>'
        + '  </select>'
        + '</div>'
        + '<div class="restic-row">'
        + '  <label>Enabled:</label>'
        + '  <select class="source-enabled">'
        + '    <option value="1" selected>Yes</option>'
        + '    <option value="0">No</option>'
        + '  </select>'
        + '</div>'
        + '</div>';

    list.insertAdjacentHTML('beforeend', html);
}

// =============================================================================
// DYNAMIC LIST: ZFS DATASETS
// =============================================================================

function addZfsDataset() {
    var list = document.getElementById('zfs-datasets-list');
    var html = '<div class="restic-row">'
        + '  <input type="text" class="zfs-dataset" value="" placeholder="e.g. cache/appdata" style="flex:1;">'
        + '  <button class="restic-btn restic-btn-danger" onclick="this.parentElement.remove()">Remove</button>'
        + '</div>';
    list.insertAdjacentHTML('beforeend', html);
}

// =============================================================================
// REMOVE LIST ITEM
// =============================================================================

function removeListItem(btn) {
    var item = btn.closest('.restic-list-item');
    if (item) {
        item.remove();
    }
}

// =============================================================================
// COLLECT CONFIG FROM DOM
// =============================================================================

function collectConfig() {
    var config = {
        general: {
            password_mode: document.getElementById('cfg-password-mode').value,
            password_file: document.getElementById('cfg-password-file').value.trim(),
            password_inline: document.getElementById('cfg-password-inline').value,
            hostname: document.getElementById('cfg-hostname').value.trim(),
            tags: document.getElementById('cfg-tags').value.trim(),
            max_retries: parseInt(document.getElementById('cfg-max-retries').value) || 3,
            retry_wait: parseInt(document.getElementById('cfg-retry-wait').value) || 30,
            check_enabled: document.getElementById('cfg-check-enabled').value === '1',
            check_percentage: document.getElementById('cfg-check-percentage').value.trim() || '2%',
            check_schedule: document.getElementById('cfg-check-schedule').value
        },
        targets: [],
        sources: [],
        excludes: {
            global: textareaToArray('cfg-excludes-global'),
            optional: textareaToArray('cfg-excludes-optional')
        },
        zfs: {
            enabled: document.getElementById('cfg-zfs-enabled').value === '1',
            recursive: document.getElementById('cfg-zfs-recursive').value === '1',
            snapshot_prefix: document.getElementById('cfg-zfs-prefix').value.trim() || 'restic-backup',
            datasets: []
        },
        schedule: {
            enabled: document.getElementById('cfg-schedule-enabled').value === '1',
            cron: document.getElementById('cfg-schedule-cron').value.trim()
        },
        notifications: {
            enabled: document.getElementById('cfg-notifications').value === '1'
        }
    };

    // Collect targets
    var targetItems = document.querySelectorAll('#targets-list .restic-list-item');
    targetItems.forEach(function(item) {
        config.targets.push({
            id: item.getAttribute('data-id') || generateId(),
            url: item.querySelector('.target-url').value.trim(),
            name: item.querySelector('.target-name').value.trim(),
            use_optional_excludes: item.querySelector('.target-optional-excludes').value === '1',
            enabled: item.querySelector('.target-enabled').value === '1'
        });
    });

    // Collect sources
    var sourceItems = document.querySelectorAll('#sources-list .restic-list-item');
    sourceItems.forEach(function(item) {
        config.sources.push({
            id: item.getAttribute('data-id') || generateId(),
            path: item.querySelector('.source-path').value.trim(),
            label: item.querySelector('.source-label').value.trim(),
            readonly_mount: item.querySelector('.source-readonly').value === '1',
            enabled: item.querySelector('.source-enabled').value === '1'
        });
    });

    // Collect ZFS datasets
    var dsInputs = document.querySelectorAll('#zfs-datasets-list .zfs-dataset');
    dsInputs.forEach(function(input) {
        var val = input.value.trim();
        if (val) {
            config.zfs.datasets.push(val);
        }
    });

    return config;
}

function textareaToArray(id) {
    var text = document.getElementById(id).value;
    return text.split('\n').map(function(line) { return line.trim(); }).filter(function(line) { return line !== ''; });
}

// =============================================================================
// SAVE CONFIG
// =============================================================================

function saveConfig() {
    var config = collectConfig();

    var xhr = new XMLHttpRequest();
    xhr.open('POST', resticBaseUrl + '?action=save');
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function() {
        if (xhr.status === 200) {
            var status = document.getElementById('save-status');
            status.style.display = 'inline';
            status.textContent = 'Configuration saved!';
            status.style.color = '#27ae60';
            setTimeout(function() { status.style.display = 'none'; }, 3000);
        } else {
            showError('Failed to save configuration: ' + xhr.responseText);
        }
    };
    xhr.onerror = function() {
        showError('Network error while saving configuration.');
    };
    xhr.send(JSON.stringify(config));
}

// =============================================================================
// BACKUP CONTROL
// =============================================================================

function startBackup() {
    document.getElementById('btn-start').disabled = true;
    document.getElementById('btn-stop').disabled = false;
    updateStatusBadge(true);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', resticBaseUrl + '?action=backup');
    xhr.onload = function() {
        startLogPolling();
    };
    xhr.send();
}

function stopBackup() {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', resticBaseUrl + '?action=stop');
    xhr.onload = function() {
        document.getElementById('btn-start').disabled = false;
        document.getElementById('btn-stop').disabled = true;
        updateStatusBadge(false);
        stopLogPolling();
        refreshLog();
    };
    xhr.send();
}

// =============================================================================
// REPO ACTIONS
// =============================================================================

function initRepo(btn) {
    var item = btn.closest('.restic-list-item');
    var targetId = item.getAttribute('data-id');
    btn.disabled = true;
    btn.textContent = 'Initializing...';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', resticBaseUrl + '?action=init&target_id=' + encodeURIComponent(targetId));
    xhr.onload = function() {
        btn.disabled = false;
        btn.textContent = 'Init Repo';
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.status === 'success') {
                swal({title: 'Success', text: resp.message, type: 'success'});
            } else {
                swal({title: 'Error', text: resp.message || 'Init failed', type: 'error'});
            }
        } catch(e) {
            swal({title: 'Error', text: xhr.responseText, type: 'error'});
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = 'Init Repo';
        showError('Network error');
    };
    xhr.send();
}

function testTarget(btn) {
    var item = btn.closest('.restic-list-item');
    var targetId = item.getAttribute('data-id');
    btn.disabled = true;
    btn.textContent = 'Testing...';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', resticBaseUrl + '?action=test&target_id=' + encodeURIComponent(targetId));
    xhr.onload = function() {
        btn.disabled = false;
        btn.textContent = 'Test';
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.status === 'success') {
                swal({title: 'Success', text: resp.message, type: 'success'});
            } else {
                swal({title: 'Error', text: resp.message || 'Test failed', type: 'error'});
            }
        } catch(e) {
            swal({title: 'Error', text: xhr.responseText, type: 'error'});
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = 'Test';
        showError('Network error');
    };
    xhr.send();
}

// =============================================================================
// LOG POLLING
// =============================================================================

function refreshLog() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', resticBaseUrl + '?action=log');
    xhr.onload = function() {
        var logDiv = document.getElementById('restic-log');
        if (xhr.responseText.trim()) {
            logDiv.textContent = xhr.responseText;
        } else {
            logDiv.textContent = 'No log data available.';
        }
        logDiv.scrollTop = logDiv.scrollHeight;
    };
    xhr.send();
}

function startLogPolling() {
    refreshLog();
    if (resticLogTimer) clearInterval(resticLogTimer);
    resticLogTimer = setInterval(function() {
        refreshLog();
        checkStatus();
    }, 3000);
}

function stopLogPolling() {
    if (resticLogTimer) {
        clearInterval(resticLogTimer);
        resticLogTimer = null;
    }
}

function checkStatus() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', resticBaseUrl + '?action=status');
    xhr.onload = function() {
        try {
            var resp = JSON.parse(xhr.responseText);
            if (!resp.running) {
                document.getElementById('btn-start').disabled = false;
                document.getElementById('btn-stop').disabled = true;
                updateStatusBadge(false);
                stopLogPolling();
                refreshLog();
            }
        } catch(e) {}
    };
    xhr.send();
}

// =============================================================================
// UI HELPERS
// =============================================================================

function updateStatusBadge(running) {
    var badge = document.getElementById('restic-status-badge');
    badge.textContent = running ? 'RUNNING' : 'IDLE';
    badge.className = 'restic-status-badge ' + (running ? 'restic-status-running' : 'restic-status-idle');
}

function generateId() {
    var arr = new Uint8Array(8);
    crypto.getRandomValues(arr);
    return Array.from(arr, function(b) { return b.toString(16).padStart(2, '0'); }).join('');
}

function showError(msg) {
    if (typeof swal === 'function') {
        swal({title: 'Error', text: msg, type: 'error'});
    } else {
        alert(msg);
    }
}
