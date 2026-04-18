<?php
/**
 * Restic Backup Plugin - API Endpoint (v5)
 *
 * Reads action + data from multiple sources for maximum compatibility:
 *   1. $_POST (standard form-encoded, preferred)
 *   2. php://input parsed as form-encoded
 *   3. php://input parsed as JSON
 *
 * Never uses http_response_code() - some reverse proxies strip the body
 * on non-200 responses, causing empty responses in the browser.
 */
while (ob_get_level() > 0) ob_end_clean();

set_error_handler(function(int $errno, string $errstr): bool {
    if (error_reporting() === 0) return true; // suppressed with @
    echo json_encode(['status' => 'error', 'message' => "PHP[$errno]: $errstr"]);
    exit(1);
});
register_shutdown_function(function(): void {
    $e = error_get_last();
    if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Fatal: ' . $e['message']]);
    }
});

require_once '/usr/local/emhttp/plugins/restic-backup/include/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// --- Determine action and data from request ---
$action = '';
$data   = [];

// Method 1: $_POST (standard form-encoded)
if (!empty($_POST['action'])) {
    $action = $_POST['action'];
    $raw_data = $_POST['data'] ?? '{}';
    $data = json_decode($raw_data, true);
    if (!is_array($data)) $data = [];
}

// Method 2+3: php://input fallback
if ($action === '') {
    $raw = @file_get_contents('php://input');
    if ($raw && strlen($raw) > 0) {
        // Try JSON first
        $input = @json_decode($raw, true);
        if (is_array($input) && !empty($input['action'])) {
            $action = $input['action'];
            $data = $input;
        } else {
            // Try form-encoded
            parse_str($raw, $parsed);
            if (!empty($parsed['action'])) {
                $action = $parsed['action'];
                $raw_data = $parsed['data'] ?? '{}';
                $data = json_decode($raw_data, true);
                if (!is_array($data)) $data = [];
            }
        }
    }
}

/**
 * Build env-var prefix for restic based on target type + credentials.
 * Returns a string like "AWS_ACCESS_KEY_ID='...' AWS_SECRET_ACCESS_KEY='...' "
 */
function restic_creds_env(string $type, array $creds): string {
    $env = '';
    if ($type === 's3') {
        if (!empty($creds['aws_access_key_id']))
            $env .= 'AWS_ACCESS_KEY_ID=' . escapeshellarg($creds['aws_access_key_id']) . ' ';
        if (!empty($creds['aws_secret_access_key']))
            $env .= 'AWS_SECRET_ACCESS_KEY=' . escapeshellarg($creds['aws_secret_access_key']) . ' ';
        if (!empty($creds['aws_region']))
            $env .= 'AWS_DEFAULT_REGION=' . escapeshellarg($creds['aws_region']) . ' ';
    } elseif ($type === 'b2') {
        if (!empty($creds['b2_account_id']))
            $env .= 'B2_ACCOUNT_ID=' . escapeshellarg($creds['b2_account_id']) . ' ';
        if (!empty($creds['b2_account_key']))
            $env .= 'B2_ACCOUNT_KEY=' . escapeshellarg($creds['b2_account_key']) . ' ';
    }
    return $env;
}

/**
 * Return extra restic CLI args for SFTP StrictHostKeyChecking.
 */
function restic_sftp_args(string $type, array $creds): array {
    if ($type !== 'sftp') return [];
    if ($creds['sftp_accept_hostkey'] ?? true) {
        return ['-o', 'sftp.args=-o StrictHostKeyChecking=accept-new'];
    }
    return [];
}

/**
 * Inject REST credentials into the URL.
 * rest:https://host:8000/ + user/pass → rest:https://user:pass@host:8000/
 */
function restic_inject_rest_creds(string $url, array $creds): string {
    $user = $creds['rest_user'] ?? '';
    $pass = $creds['rest_pass'] ?? '';
    if (!$user || !$pass || substr($url, 0, 5) !== 'rest:') return $url;
    $inner = substr($url, 5);
    if (preg_match('#^(https?://)(.+)$#', $inner, $m)) {
        return 'rest:' . $m[1] . rawurlencode($user) . ':' . rawurlencode($pass) . '@' . $m[2];
    }
    return $url;
}

switch ($action) {

    // =========================================================================
    // SAVE CONFIG
    // =========================================================================
    case 'save':
        $config = $data['config'] ?? null;

        if (!is_array($config)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'No config data received',
            ]);
            break;
        }

        if (!isset($config['general'])) {
            $config['general'] = restic_default_config()['general'];
        }
        if (!isset($config['jobs']) || !is_array($config['jobs'])) {
            $config['jobs'] = [];
        }

        $jobCount = count($config['jobs']);

        if (restic_save_config($config)) {
            $fileSize = file_exists(RESTIC_CONFIG_FILE) ? filesize(RESTIC_CONFIG_FILE) : 0;
            restic_update_cron($config);
            echo json_encode([
                'status'  => 'success',
                'message' => "Saved ({$fileSize} bytes, {$jobCount} jobs)",
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Write failed! dir=' . RESTIC_CONFIG_DIR
                    . ' exists=' . (is_dir(RESTIC_CONFIG_DIR) ? 'yes' : 'no')
                    . ' writable=' . (is_writable(RESTIC_CONFIG_DIR) ? 'yes' : 'no'),
            ]);
        }
        break;

    // =========================================================================
    // START BACKUP
    // =========================================================================
    case 'backup':
        if (restic_is_running()) {
            echo json_encode(['status' => 'error', 'message' => 'Backup already running']);
            break;
        }

        $job_id = $data['job_id'] ?? '';
        $cmd = '/usr/bin/python3 ' . escapeshellarg(RESTIC_SCRIPT) . ' --backup';
        if ($job_id) {
            $cmd .= ' --job ' . escapeshellarg($job_id);
        }
        $logfile = restic_log_file();
        exec("nohup {$cmd} >> " . escapeshellarg($logfile) . " 2>&1 &");
        usleep(500000);

        echo json_encode(['status' => 'started']);
        break;

    // =========================================================================
    // STOP BACKUP
    // =========================================================================
    case 'stop':
        $pid = restic_get_pid();
        if ($pid > 0) {
            posix_kill($pid, SIGTERM);
            usleep(500000);
            if (file_exists("/proc/{$pid}")) {
                posix_kill($pid, SIGKILL);
            }
            @unlink(RESTIC_PID_FILE);
            @unlink(RESTIC_LOCK_FILE);
            echo json_encode(['status' => 'stopped']);
        } else {
            echo json_encode(['status' => 'not_running']);
        }
        break;

    // =========================================================================
    // INIT REPO
    // =========================================================================
    case 'init':
        $url = $data['url'] ?? '';
        $pw_mode = $data['password_mode'] ?? 'file';
        $pw_file = $data['password_file'] ?? '';
        $pw_inline = $data['password_inline'] ?? '';
        $type = $data['type'] ?? 'local';
        $creds = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

        if (!$url) {
            echo json_encode(['status' => 'error', 'message' => 'No repository URL provided']);
            break;
        }

        if ($type === 'rest') $url = restic_inject_rest_creds($url, $creds);

        $env = restic_creds_env($type, $creds);
        if ($pw_mode === 'file' && $pw_file) {
            $env .= 'RESTIC_PASSWORD_FILE=' . escapeshellarg($pw_file);
        } elseif ($pw_mode === 'inline' && $pw_inline) {
            $env .= 'RESTIC_PASSWORD=' . escapeshellarg($pw_inline);
        }

        $sftp_args = restic_sftp_args($type, $creds);
        $sfx = implode(' ', array_map('escapeshellarg', $sftp_args));
        $cmd = "{$env} restic -r " . escapeshellarg($url) . ($sfx ? " $sfx" : '') . " init 2>&1";
        $output = [];
        exec($cmd, $output, $ret);
        $text = implode("\n", $output);

        if ($ret === 0 || stripos($text, 'already') !== false) {
            echo json_encode(['status' => 'success', 'message' => $text ?: 'Repository initialized']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $text ?: 'Init failed (exit code ' . $ret . ')']);
        }
        break;

    // =========================================================================
    // TEST CONNECTION
    // =========================================================================
    case 'test':
        $url = $data['url'] ?? '';
        $pw_mode = $data['password_mode'] ?? 'file';
        $pw_file = $data['password_file'] ?? '';
        $pw_inline = $data['password_inline'] ?? '';
        $type = $data['type'] ?? 'local';
        $creds = is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

        if (!$url) {
            echo json_encode(['status' => 'error', 'message' => 'No repository URL provided']);
            break;
        }

        if ($type === 'rest') $url = restic_inject_rest_creds($url, $creds);

        $env = restic_creds_env($type, $creds);
        if ($pw_mode === 'file' && $pw_file) {
            $env .= 'RESTIC_PASSWORD_FILE=' . escapeshellarg($pw_file);
        } elseif ($pw_mode === 'inline' && $pw_inline) {
            $env .= 'RESTIC_PASSWORD=' . escapeshellarg($pw_inline);
        }

        $sftp_args = restic_sftp_args($type, $creds);
        $sfx = implode(' ', array_map('escapeshellarg', $sftp_args));
        $cmd = "{$env} restic -r " . escapeshellarg($url) . ($sfx ? " $sfx" : '') . " snapshots --latest 1 2>&1";
        $output = [];
        exec($cmd, $output, $ret);
        $text = implode("\n", $output);

        if ($ret === 0) {
            echo json_encode(['status' => 'success', 'message' => 'Connection OK']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $text ?: 'Connection failed (exit code ' . $ret . ')']);
        }
        break;

    // =========================================================================
    // STATUS
    // =========================================================================
    case 'status':
        echo json_encode([
            'running'     => restic_is_running(),
            'pid'         => restic_get_pid(),
            'api_version' => 5,
        ]);
        break;

    // =========================================================================
    // LOG
    // =========================================================================
    case 'log':
        echo json_encode([
            'status' => 'success',
            'log'    => restic_read_log(200),
        ]);
        break;

    // =========================================================================
    // BROWSE DIRECTORIES / FILES
    //
    // Parameters:
    //   path: absolute path to list (default /mnt)
    //   mode: 'dirs' (default) | 'files' — in 'files' mode, the response
    //         additionally contains a files[] array so the frontend can show
    //         selectable files alongside subdirectories.
    // =========================================================================
    case 'browse':
        $path = $data['path'] ?? '/mnt';
        $path = str_replace(['..', "\0"], '', $path);
        if (!$path || $path[0] !== '/') {
            $path = '/mnt';
        }
        $mode  = $data['mode'] ?? 'dirs';
        $dirs  = restic_list_dirs($path);
        $resp  = [
            'current' => $path,
            'parent'  => dirname($path),
            'dirs'    => $dirs,
        ];
        if ($mode === 'files' || $mode === 'both') {
            $resp['files'] = restic_list_files($path);
        }
        echo json_encode($resp);
        break;

    // =========================================================================
    // LIST ZFS DATASETS
    // =========================================================================
    case 'datasets':
        $datasets = restic_list_zfs_datasets();
        echo json_encode([
            'available' => !empty($datasets),
            'datasets'  => $datasets,
        ]);
        break;

    // =========================================================================
    // SNAPSHOTS
    // =========================================================================
    case 'snapshots':
        $url = $data['url'] ?? '';
        $pw_mode = $data['password_mode'] ?? 'file';
        $pw_file = $data['password_file'] ?? '';
        $pw_inline = $data['password_inline'] ?? '';

        if (!$url) {
            echo json_encode(['status' => 'error', 'message' => 'No repository URL provided']);
            break;
        }

        $env = '';
        if ($pw_mode === 'file' && $pw_file) {
            $env = 'RESTIC_PASSWORD_FILE=' . escapeshellarg($pw_file);
        } elseif ($pw_mode === 'inline' && $pw_inline) {
            $env = 'RESTIC_PASSWORD=' . escapeshellarg($pw_inline);
        }

        $cmd = "{$env} restic -r " . escapeshellarg($url) . " snapshots --json 2>&1";
        $output = [];
        exec($cmd, $output, $ret);
        $text = implode("\n", $output);

        if ($ret === 0) {
            echo $text;
        } else {
            echo json_encode(['status' => 'error', 'message' => $text]);
        }
        break;

    // =========================================================================
    // JOB SNAPSHOTS  (uses saved job/target config)
    // =========================================================================
    case 'job_snapshots':
        $job_id    = $data['job_id']    ?? '';
        $target_id = $data['target_id'] ?? '';

        if (!$job_id) {
            echo json_encode(['status' => 'error', 'message' => 'No job_id']);
            break;
        }

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }

        $env_str  = restic_build_env_str($tc['env']);
        $sfx      = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '')
             . " snapshots --json 2>&1";

        $output = [];
        exec($cmd, $output, $ret);
        $text = implode("\n", $output);

        if ($ret === 0) {
            echo $text;
        } else {
            echo json_encode(['status' => 'error', 'message' => $text ?: 'snapshots failed (exit ' . $ret . ')']);
        }
        break;

    // =========================================================================
    // SNAPSHOT PREFETCH  (return compact full flat listing for JS-side filtering)
    // =========================================================================
    case 'snapshot_prefetch': {
        $job_id      = $data['job_id']      ?? '';
        $target_id   = $data['target_id']   ?? '';
        $snapshot_id = $data['snapshot_id'] ?? '';

        if (!$job_id || !$snapshot_id) {
            echo json_encode(['status' => 'error', 'message' => 'Missing job_id or snapshot_id']);
            break;
        }

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }

        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $safe_id    = preg_replace('/[^a-f0-9]/', '', strtolower($snapshot_id));
        $cache_file = "/tmp/restic-ls-{$safe_id}.json";
        $cache_ttl  = 300;

        // Build cache file if needed
        if (!file_exists($cache_file) || (time() - filemtime($cache_file)) >= $cache_ttl) {
            $tmp_file = $cache_file . '.tmp';
            $err_file = $cache_file . '.err';
            $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
                 . ($sfx ? " $sfx" : '')
                 . " ls --json " . escapeshellarg($snapshot_id)
                 . " > " . escapeshellarg($tmp_file)
                 . " 2> " . escapeshellarg($err_file);
            $unused = [];
            exec($cmd, $unused, $ret);
            if ($ret !== 0) {
                $err = trim(@file_get_contents($err_file) ?: 'restic ls failed (exit ' . $ret . ')');
                @unlink($tmp_file); @unlink($err_file);
                echo json_encode(['status' => 'error', 'message' => $err]);
                break;
            }
            @unlink($err_file);
            rename($tmp_file, $cache_file);
        }

        // Stream cache file line-by-line, emit compact entries to avoid
        // building a huge array in PHP memory.
        $fh = @fopen($cache_file, 'r');
        if (!$fh) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot read snapshot cache']);
            break;
        }
        echo '{"status":"success","entries":[';
        $first = true;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $obj = @json_decode($line, true);
            if (!is_array($obj) || !isset($obj['path'])) continue;
            $p = rtrim($obj['path'], '/');
            if ($p === '') continue;
            $entry = [
                'path' => $p,
                'type' => $obj['type'] ?? 'file',
                'name' => $obj['name'] ?? basename($p),
                'size' => $obj['size'] ?? 0,
            ];
            if (!$first) echo ',';
            echo json_encode($entry);
            $first = false;
        }
        fclose($fh);
        echo ']}';
        break;
    }

    // =========================================================================
    // SNAPSHOT LS  (list files in a snapshot at a given path)
    // =========================================================================
    case 'snapshot_ls':
        $job_id      = $data['job_id']      ?? '';
        $target_id   = $data['target_id']   ?? '';
        $snapshot_id = $data['snapshot_id'] ?? '';
        $path        = $data['path']        ?? '/';

        if (!$job_id || !$snapshot_id) {
            echo json_encode(['status' => 'error', 'message' => 'Missing job_id or snapshot_id']);
            break;
        }

        // Sanitize + normalise path — must be absolute, no null bytes, no trailing slash
        $path = str_replace("\0", '', $path);
        $path = rtrim($path, '/');
        if ($path === '' || $path[0] !== '/') $path = '/';

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }

        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));

        // Cache the full snapshot listing in /tmp keyed by snapshot ID.
        // This avoids relying on restic ls path-argument behaviour (which varies
        // across versions and may return only the dir node itself, not its children).
        // The cache is valid for 5 minutes; PHP then filters to direct children.
        $safe_id    = preg_replace('/[^a-f0-9]/', '', strtolower($snapshot_id));
        $cache_file = "/tmp/restic-ls-{$safe_id}.json";
        $cache_ttl  = 300; // seconds

        // If no valid cache, stream restic output directly to a temp file.
        // Using shell redirection avoids loading the full listing into PHP memory
        // (large snapshots can exhaust the 256 MB PHP memory limit).
        if (!file_exists($cache_file) || (time() - filemtime($cache_file)) >= $cache_ttl) {
            $tmp_file = $cache_file . '.tmp';
            $err_file = $cache_file . '.err';
            $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
                 . ($sfx ? " $sfx" : '')
                 . " ls --json " . escapeshellarg($snapshot_id)
                 . " > " . escapeshellarg($tmp_file)
                 . " 2> " . escapeshellarg($err_file);
            $unused = [];
            exec($cmd, $unused, $ret);
            if ($ret !== 0) {
                $err = trim(@file_get_contents($err_file) ?: 'restic ls failed (exit ' . $ret . ')');
                @unlink($tmp_file);
                @unlink($err_file);
                echo json_encode(['status' => 'error', 'message' => $err]);
                break;
            }
            @unlink($err_file);
            rename($tmp_file, $cache_file);
        }

        // Filter to direct children of $path — read line by line, never load full file.
        // $norm   = '' (root) or '/some/path' (subdir, no trailing slash)
        // $prefix = '/' (root) or '/some/path/' (subdir)
        $norm   = ($path === '/') ? '' : $path;
        $prefix = ($norm === '') ? '/' : $norm . '/';
        $items  = [];
        $fh = @fopen($cache_file, 'r');
        if (!$fh) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot read snapshot listing cache']);
            break;
        }
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $obj = @json_decode($line, true);
            if (!is_array($obj) || !isset($obj['path'])) continue; // skip snapshot header line
            $p = rtrim($obj['path'], '/'); // normalise dir paths (restic may add trailing slash)
            if ($p === '' || $p === $norm) continue; // skip root or self
            if (strpos($p, $prefix) !== 0) continue; // not under this dir
            $rest = substr($p, strlen($prefix));
            if ($rest === '' || strpos($rest, '/') !== false) continue; // skip deeper items
            $obj['path'] = $p; // return normalised path (no trailing slash)
            if (!isset($obj['name']) || $obj['name'] === '') $obj['name'] = basename($p);
            $items[] = $obj;
        }
        fclose($fh);

        // Sort: dirs first, then alphabetical by name
        usort($items, function($a, $b) {
            $aD = ($a['type'] ?? '') === 'dir' ? 0 : 1;
            $bD = ($b['type'] ?? '') === 'dir' ? 0 : 1;
            if ($aD !== $bD) return $aD - $bD;
            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });
        echo json_encode(['status' => 'success', 'items' => $items]);
        break;

    // =========================================================================
    // SNAPSHOT RESTORE  (background restore job)
    // =========================================================================
    case 'snapshot_restore':
        $job_id      = $data['job_id']      ?? '';
        $target_id   = $data['target_id']   ?? '';
        $snapshot_id = $data['snapshot_id'] ?? '';
        $dest        = $data['dest']        ?? '';

        // Accept include_paths (array) or legacy include_path (string)
        $raw_paths = $data['include_paths'] ?? $data['include_path'] ?? ['/'];
        $inc_paths = is_array($raw_paths) ? $raw_paths : [$raw_paths];

        if (!$job_id || !$snapshot_id || !$dest) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            break;
        }

        // Sanitize paths
        $dest = str_replace("\0", '', $dest);
        if (!$dest || $dest[0] !== '/') {
            echo json_encode(['status' => 'error', 'message' => 'dest must be an absolute path']);
            break;
        }

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }

        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $logfile = RESTIC_LOG_DIR . '/restic-restore-' . date('Ymd-His') . '.log';

        // Sanitise include paths
        $clean_paths = [];
        foreach ($inc_paths as $ip) {
            $ip = rtrim(str_replace("\0", '', $ip), '/');
            if ($ip && $ip[0] === '/') $clean_paths[] = $ip;
        }
        if (empty($clean_paths)) $clean_paths = ['/'];
        $inc_paths = $clean_paths;

        $include_flags = '';
        foreach ($inc_paths as $ip) {
            $include_flags .= ' --include ' . escapeshellarg($ip);
        }

        // Find the common parent of all included paths.
        // If it is deeper than '/', use a staging dir so that the restored item
        // lands directly in $dest/<basename> instead of $dest/full/snapshot/path/<basename>.
        $parents = array_map('dirname', $inc_paths);
        $common_parent = $parents[0];
        foreach ($parents as $p) {
            while ($common_parent !== '/'
                   && $p !== $common_parent
                   && strpos($p . '/', $common_parent . '/') !== 0) {
                $common_parent = dirname($common_parent);
            }
        }
        $use_staging = ($common_parent !== '/' && $common_parent !== '' && $common_parent !== '.');

        $restic_cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '')
             . " restore " . escapeshellarg($snapshot_id)
             . $include_flags;

        if ($use_staging) {
            // Restore to temp dir, then move each selected item into $dest directly.
            $staging = '/tmp/restic-restore-' . bin2hex(random_bytes(4));
            $restic_cmd .= ' --target ' . escapeshellarg($staging);
            $move_parts = ['mkdir -p ' . escapeshellarg($dest)];
            foreach ($inc_paths as $ip) {
                $move_parts[] = 'mv ' . escapeshellarg($staging . $ip)
                              . ' ' . escapeshellarg(rtrim($dest, '/') . '/' . basename($ip));
            }
            $bash_script = '( ' . $restic_cmd
                . ' && { ' . implode(' && ', $move_parts) . '; }'
                . '; rm -rf ' . escapeshellarg($staging)
                . ' ) >> ' . escapeshellarg($logfile) . ' 2>&1';
            $display_dest = rtrim($dest, '/') . '/' . basename($inc_paths[0]);
        } else {
            $restic_cmd .= ' --target ' . escapeshellarg($dest);
            $bash_script = $restic_cmd . ' >> ' . escapeshellarg($logfile) . ' 2>&1';
            $display_dest = $dest;
        }

        exec('nohup bash -c ' . escapeshellarg($bash_script) . ' &');

        echo json_encode([
            'status'  => 'started',
            'message' => "Restore started \u{2192} {$display_dest}  (log: {$logfile})",
            'logfile' => $logfile,
        ]);
        break;

    // =========================================================================
    // DEFAULT
    // =========================================================================
    default:
        echo json_encode([
            'status'  => 'error',
            'message' => 'Unknown or missing action',
            'got_action' => $action,
            'post_keys' => array_keys($_POST),
        ]);
        break;
}
