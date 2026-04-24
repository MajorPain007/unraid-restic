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
    // PHP 8+: @ no longer sets error_reporting() to 0 — it masks specific
    // bits instead. The portable check is to test whether this errno is
    // currently enabled in the effective mask.
    if (!(error_reporting() & $errno)) return true; // suppressed with @
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
 * Return bandwidth limit flags (--limit-upload / --limit-download, KiB/s).
 * Restic treats these as global flags, so they go between `restic` and the
 * subcommand. 0 / unset = unlimited and emits no flag.
 */
function restic_limit_args(array $target): array {
    $args = [];
    $up   = (int)($target['limit_upload']   ?? 0);
    $down = (int)($target['limit_download'] ?? 0);
    if ($up   > 0) { $args[] = '--limit-upload';   $args[] = (string)$up; }
    if ($down > 0) { $args[] = '--limit-download'; $args[] = (string)$down; }
    return $args;
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

        // Normalize target URLs: strip any accidental doubled protocol prefix
        // (a legacy artifact from an earlier UI bug where the prefix span was
        // not combined with the input on Test/Init, so users typed `sftp://`
        // into the input, and Save then doubled it to `sftp://sftp://...`).
        $pfxMap = ['sftp'=>'sftp://','s3'=>'s3:','b2'=>'b2:','rest'=>'rest:','rclone'=>'rclone:'];
        foreach ($config['jobs'] as &$_job) {
            if (!isset($_job['targets']) || !is_array($_job['targets'])) continue;
            foreach ($_job['targets'] as &$_tgt) {
                $_ttype = $_tgt['type'] ?? 'local';
                $_pfx   = $pfxMap[$_ttype] ?? '';
                if ($_pfx === '' || !isset($_tgt['url'])) continue;
                $_url = $_tgt['url'];
                // Collapse any doubled occurrences at the start of the URL.
                while (strncmp($_url, $_pfx . $_pfx, strlen($_pfx) * 2) === 0) {
                    $_url = substr($_url, strlen($_pfx));
                }
                // Ensure exactly one prefix is present.
                if (strncmp($_url, $_pfx, strlen($_pfx)) !== 0) {
                    $_url = $_pfx . $_url;
                }
                $_tgt['url'] = $_url;
            }
            unset($_tgt);
        }
        unset($_job);

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
        // Optional job_id filters to the per-job log file; empty = combined main log.
        $log_job_id = isset($data['job_id']) ? (string)$data['job_id'] : '';
        echo json_encode([
            'status' => 'success',
            'job_id' => $log_job_id,
            'log'    => restic_read_log(200, $log_job_id),
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
        $lim      = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '')
             . ($lim ? " $lim" : '')
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
    // JOB FIND — full-text search for paths across all snapshots in a repo.
    //
    // Parameters:
    //   job_id    : id of the saved job
    //   target_id : id of the target (optional, defaults to first enabled)
    //   pattern   : the search pattern (glob-style, restic syntax)
    //   ignore_case : "1" to pass -i
    //   snapshot  : optional single snapshot id to restrict search to
    //   newest    : optional e.g. "30d" to pass -N
    // =========================================================================
    case 'job_find': {
        $job_id      = $data['job_id']    ?? '';
        $target_id   = $data['target_id'] ?? '';
        $pattern     = trim((string)($data['pattern'] ?? ''));
        $ignore_case = !empty($data['ignore_case']);
        $snap_id     = trim((string)($data['snapshot'] ?? ''));
        $newest      = trim((string)($data['newest'] ?? ''));

        if (!$job_id) {
            echo json_encode(['status' => 'error', 'message' => 'No job_id']);
            break;
        }
        if ($pattern === '') {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a search pattern']);
            break;
        }

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }

        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $flags   = ['--json'];
        if ($ignore_case)          { $flags[] = '-i'; }
        if ($snap_id !== '')       { $flags[] = '--snapshot ' . escapeshellarg($snap_id); }
        if ($newest !== '')        { $flags[] = '-N ' . escapeshellarg($newest); }

        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '')
             . ($lim ? " $lim" : '')
             . ' find ' . implode(' ', $flags)
             . ' ' . escapeshellarg($pattern)
             . ' 2>&1';

        $output = [];
        exec($cmd, $output, $ret);
        $text = implode("\n", $output);

        // restic find --json prints a JSON array with objects
        // { "matches": [...], "hits": N, "snapshot": "id" }
        $parsed = json_decode($text, true);
        if ($ret === 0 && is_array($parsed)) {
            // Flatten: one result entry per snapshot, keep original shape.
            echo json_encode([
                'status'  => 'success',
                'pattern' => $pattern,
                'results' => $parsed,
            ]);
        } else {
            $msg = trim($text) ?: 'restic find failed (exit ' . $ret . ')';
            echo json_encode(['status' => 'error', 'message' => $msg]);
        }
        break;
    }

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
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $safe_id    = preg_replace('/[^a-f0-9]/', '', strtolower($snapshot_id));
        $cache_file = "/tmp/restic-ls-{$safe_id}.json";
        $cache_ttl  = 300;

        // Build cache file if needed
        if (!file_exists($cache_file) || (time() - filemtime($cache_file)) >= $cache_ttl) {
            $tmp_file = $cache_file . '.tmp';
            $err_file = $cache_file . '.err';
            $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
                 . ($sfx ? " $sfx" : '')
                 . ($lim ? " $lim" : '')
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
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));

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
                 . ($lim ? " $lim" : '')
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
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
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
             . ($lim ? " $lim" : '')
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
    // UNLOCK — remove stale locks from a repository
    // =========================================================================
    case 'repo_unlock': {
        $job_id    = $data['job_id']    ?? '';
        $target_id = $data['target_id'] ?? '';
        $remove_all = !empty($data['remove_all']);

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $flag    = $remove_all ? ' --remove-all' : '';
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . " unlock{$flag} 2>&1";
        $output = []; exec($cmd, $output, $ret);
        $text = trim(implode("\n", $output));
        echo json_encode([
            'status'  => $ret === 0 ? 'success' : 'error',
            'message' => $text !== '' ? $text : ($ret === 0 ? 'Locks removed.' : 'unlock failed'),
        ]);
        break;
    }

    // =========================================================================
    // STATS — repository statistics (raw-data / restore-size / files-by-contents)
    // =========================================================================
    case 'repo_stats': {
        $job_id    = $data['job_id']    ?? '';
        $target_id = $data['target_id'] ?? '';
        $mode      = $data['mode']      ?? 'raw-data';

        $allowed = ['raw-data', 'restore-size', 'files-by-contents', 'blobs-per-file'];
        if (!in_array($mode, $allowed, true)) $mode = 'raw-data';

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . ' stats --mode ' . escapeshellarg($mode) . ' --json 2>&1';
        $output = []; exec($cmd, $output, $ret);
        $text = implode("\n", $output);
        $parsed = json_decode($text, true);
        if ($ret === 0 && is_array($parsed)) {
            echo json_encode([
                'status' => 'success',
                'mode'   => $mode,
                'stats'  => $parsed,
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => trim($text) ?: "restic stats failed (exit {$ret})",
            ]);
        }
        break;
    }

    // =========================================================================
    // RECOVER — scan the repo for orphaned pack files and rebuild index
    // =========================================================================
    case 'repo_recover': {
        $job_id    = $data['job_id']    ?? '';
        $target_id = $data['target_id'] ?? '';

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $logfile = RESTIC_LOG_DIR . '/restic-recover-' . date('Ymd-His') . '.log';

        // recover can take a while; run in background and stream to a log file.
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . ' recover >> ' . escapeshellarg($logfile) . ' 2>&1';
        exec('nohup bash -c ' . escapeshellarg($cmd) . ' &');
        echo json_encode([
            'status'  => 'started',
            'message' => 'Recover started — see log for details.',
            'logfile' => $logfile,
        ]);
        break;
    }

    // =========================================================================
    // REPO CHECK — full integrity check (on-demand, background)
    // =========================================================================
    case 'repo_check': {
        $job_id      = $data['job_id']    ?? '';
        $target_id   = $data['target_id'] ?? '';
        $read_data   = !empty($data['read_data']);
        $subset      = trim((string)($data['subset'] ?? '')); // e.g. "5%" or "2G"

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $extra   = '';
        if ($subset !== '')      { $extra .= ' --read-data-subset=' . escapeshellarg($subset); }
        elseif ($read_data)      { $extra .= ' --read-data'; }
        $logfile = RESTIC_LOG_DIR . '/restic-check-' . date('Ymd-His') . '.log';
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . ' check' . $extra . ' >> ' . escapeshellarg($logfile) . ' 2>&1';
        exec('nohup bash -c ' . escapeshellarg($cmd) . ' &');
        echo json_encode([
            'status'  => 'started',
            'message' => 'Integrity check started — see log for details.',
            'logfile' => $logfile,
        ]);
        break;
    }

    // =========================================================================
    // RESTIC SELF-UPDATE — binary in-place upgrade (preserves perms)
    // After a successful update we refresh the USB-stick cache so a
    // reboot without internet can restore the new binary.
    // =========================================================================
    case 'restic_self_update': {
        $cmd = '/usr/local/bin/restic self-update 2>&1';
        $output = []; exec($cmd, $output, $ret);
        $text = trim(implode("\n", $output));
        // Grab version after update
        $ver = shell_exec('/usr/local/bin/restic version 2>&1');
        if ($ret === 0 && is_file('/usr/local/bin/restic')) {
            $cache_dir = RESTIC_CONFIG_DIR;
            $cache_bin = $cache_dir . '/restic.bin';
            $cache_ver = $cache_dir . '/restic.version';
            if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
            @copy('/usr/local/bin/restic', $cache_bin);
            @chmod($cache_bin, 0644);
            if (preg_match('/restic\s+(\S+)/', (string)$ver, $m)) {
                @file_put_contents($cache_ver, $m[1] . "\n");
            }
        }
        echo json_encode([
            'status'  => $ret === 0 ? 'success' : 'error',
            'message' => $text,
            'version' => trim((string)$ver),
        ]);
        break;
    }

    // =========================================================================
    // REPO TOOL LOG TAIL — stream the tail of a background tool's logfile
    //   (check/recover). Only allows files inside RESTIC_LOG_DIR with the
    //   expected "restic-check-*" / "restic-recover-*" naming.
    // =========================================================================
    case 'repo_tool_log': {
        $logfile = (string)($data['logfile'] ?? '');
        $max     = 16384; // last 16 KiB is enough for live tail
        // Security: restrict to our log dir and known prefixes.
        $real = realpath($logfile);
        $base = realpath(RESTIC_LOG_DIR) ?: RESTIC_LOG_DIR;
        $name = basename($logfile);
        $ok_prefix = (strpos($name, 'restic-check-')   === 0)
                  || (strpos($name, 'restic-recover-') === 0)
                  || (strpos($name, 'restic-copy-')    === 0);
        if (!$real || !$ok_prefix || strpos($real, $base) !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid logfile']);
            break;
        }
        if (!is_file($real)) {
            echo json_encode(['status' => 'pending', 'text' => '', 'done' => false]);
            break;
        }
        $size = filesize($real);
        $offset = max(0, $size - $max);
        $fh = @fopen($real, 'rb');
        $text = '';
        if ($fh) {
            if ($offset > 0) fseek($fh, $offset);
            $text = stream_get_contents($fh);
            fclose($fh);
        }
        // Heuristic: a background job is "done" when the file hasn't been
        // modified for > 3 s AND the last line looks like a restic summary.
        $age = time() - filemtime($real);
        $done = $age > 3 && (preg_match('/(no errors were found|Fatal:|repository contains errors|will be removed)/i', $text) === 1);
        echo json_encode([
            'status' => 'success',
            'text'   => $text,
            'size'   => $size,
            'mtime'  => filemtime($real),
            'done'   => $done,
        ]);
        break;
    }

    // =========================================================================
    // SNAPSHOT DIFF — diff between two snapshots
    // =========================================================================
    case 'snapshot_diff': {
        $job_id    = $data['job_id']    ?? '';
        $target_id = $data['target_id'] ?? '';
        $snap_a    = $data['snapshot_a'] ?? '';
        $snap_b    = $data['snapshot_b'] ?? '';

        if (!$snap_a || !$snap_b) {
            echo json_encode(['status' => 'error', 'message' => 'Two snapshot IDs required']);
            break;
        }

        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . ' diff --json '
             . escapeshellarg($snap_a) . ' ' . escapeshellarg($snap_b)
             . ' 2>&1';
        $output = []; exec($cmd, $output, $ret);
        $text = implode("\n", $output);

        // restic diff --json emits one JSON object per line (NDJSON).
        $changes = [];
        $summary = null;
        foreach (explode("\n", $text) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $obj = json_decode($ln, true);
            if (!is_array($obj)) continue;
            if (($obj['message_type'] ?? '') === 'statistics') {
                $summary = $obj;
            } else {
                $changes[] = $obj;
            }
        }
        if ($ret !== 0 && !$changes && !$summary) {
            echo json_encode(['status' => 'error', 'message' => trim($text) ?: 'diff failed']);
            break;
        }
        echo json_encode([
            'status'  => 'success',
            'changes' => $changes,
            'summary' => $summary,
        ]);
        break;
    }

    // =========================================================================
    // SNAPSHOT TAG — add/remove/set tags on one or more snapshots
    // =========================================================================
    case 'snapshot_tag': {
        $job_id       = $data['job_id']    ?? '';
        $target_id    = $data['target_id'] ?? '';
        $snapshot_ids = $data['snapshot_ids'] ?? [];
        if (!is_array($snapshot_ids)) $snapshot_ids = [$snapshot_ids];
        $op   = $data['op']   ?? 'add';        // add | remove | set
        $tags = trim((string)($data['tags'] ?? ''));

        if (empty($snapshot_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'No snapshots selected']);
            break;
        }
        if (!in_array($op, ['add', 'remove', 'set'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid tag op']);
            break;
        }
        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));

        // Build flag: --add/--remove/--set, each takes a comma-separated value.
        $flag = '--add';
        if ($op === 'remove') $flag = '--remove';
        if ($op === 'set')    $flag = '--set';

        $ids = [];
        foreach ($snapshot_ids as $sid) {
            $clean = preg_replace('/[^a-fA-F0-9]/', '', (string)$sid);
            if ($clean !== '') $ids[] = $clean;
        }
        if (!$ids) {
            echo json_encode(['status' => 'error', 'message' => 'No valid snapshot IDs']);
            break;
        }
        $ids_str = implode(' ', array_map('escapeshellarg', $ids));

        // For set/add with empty tags, restic still accepts empty value (clears); same for remove.
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . " tag {$flag} " . escapeshellarg($tags)
             . ' ' . $ids_str . ' 2>&1';
        $output = []; exec($cmd, $output, $ret);
        $text = trim(implode("\n", $output));
        echo json_encode([
            'status'  => $ret === 0 ? 'success' : 'error',
            'message' => $text !== '' ? $text : ($ret === 0 ? 'Tags updated.' : 'tag failed'),
        ]);
        break;
    }

    // =========================================================================
    // SNAPSHOT FORGET — delete individual snapshot(s)
    // =========================================================================
    case 'snapshot_forget': {
        $job_id       = $data['job_id']    ?? '';
        $target_id    = $data['target_id'] ?? '';
        $snapshot_ids = $data['snapshot_ids'] ?? [];
        if (!is_array($snapshot_ids)) $snapshot_ids = [$snapshot_ids];
        $prune        = !empty($data['prune']);

        if (empty($snapshot_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'No snapshots selected']);
            break;
        }
        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));

        $ids = [];
        foreach ($snapshot_ids as $sid) {
            $clean = preg_replace('/[^a-fA-F0-9]/', '', (string)$sid);
            if ($clean !== '') $ids[] = $clean;
        }
        if (!$ids) {
            echo json_encode(['status' => 'error', 'message' => 'No valid snapshot IDs']);
            break;
        }
        $ids_str = implode(' ', array_map('escapeshellarg', $ids));
        $flag    = $prune ? ' --prune' : '';
        $logfile = RESTIC_LOG_DIR . '/restic-forget-' . date('Ymd-His') . '.log';
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . " forget{$flag} {$ids_str} >> " . escapeshellarg($logfile) . ' 2>&1';
        exec('nohup bash -c ' . escapeshellarg($cmd) . ' &');
        echo json_encode([
            'status'  => 'started',
            'message' => 'forget' . ($prune ? ' --prune' : '') . ' started in background',
            'logfile' => $logfile,
        ]);
        break;
    }

    // =========================================================================
    // JOB PROGRESS — read the latest /tmp/restic-progress-<jobid>.json
    // =========================================================================
    case 'job_progress': {
        $job_id = $data['job_id'] ?? '';
        if ($job_id === '') {
            echo json_encode(['status' => 'error', 'message' => 'Missing job_id']);
            break;
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $job_id);
        $path = "/tmp/restic-progress-{$safe}.json";
        if (!is_file($path)) {
            echo json_encode(['status' => 'success', 'progress' => null]);
            break;
        }
        $body = @file_get_contents($path);
        $p    = $body !== false ? json_decode($body, true) : null;
        echo json_encode(['status' => 'success', 'progress' => is_array($p) ? $p : null]);
        break;
    }

    // =========================================================================
    // SNAPSHOT REWRITE — exclude paths from existing snapshot(s)
    // =========================================================================
    case 'snapshot_rewrite': {
        $job_id       = $data['job_id']       ?? '';
        $target_id    = $data['target_id']    ?? '';
        $snapshot_ids = $data['snapshot_ids'] ?? [];
        if (!is_array($snapshot_ids)) $snapshot_ids = [$snapshot_ids];
        $excludes     = $data['excludes']     ?? [];
        if (!is_array($excludes)) $excludes = [$excludes];
        $forget       = !empty($data['forget']); // forget old snapshots after rewrite

        if (empty($snapshot_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'No snapshots selected']);
            break;
        }
        if (empty($excludes)) {
            echo json_encode(['status' => 'error', 'message' => 'At least one exclude path is required']);
            break;
        }
        $tc = restic_get_target_config($job_id, $target_id);
        if (!$tc) {
            echo json_encode(['status' => 'error', 'message' => 'Job or target not found']);
            break;
        }
        $env_str = restic_build_env_str($tc['env']);
        $sfx     = implode(' ', array_map('escapeshellarg', $tc['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $tc['limit_args'] ?? []));

        $ids = [];
        foreach ($snapshot_ids as $sid) {
            $clean = preg_replace('/[^a-fA-F0-9]/', '', (string)$sid);
            if ($clean !== '') $ids[] = $clean;
        }
        if (!$ids) {
            echo json_encode(['status' => 'error', 'message' => 'No valid snapshot IDs']);
            break;
        }

        $ex_flags = '';
        foreach ($excludes as $ex) {
            $ex = trim((string)$ex);
            if ($ex !== '') $ex_flags .= ' --exclude ' . escapeshellarg($ex);
        }

        $ids_str = implode(' ', array_map('escapeshellarg', $ids));
        $forget_flag = $forget ? ' --forget' : '';
        $logfile = RESTIC_LOG_DIR . '/restic-rewrite-' . date('Ymd-His') . '.log';
        $cmd = "{$env_str} restic -r " . escapeshellarg($tc['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . " rewrite{$forget_flag}{$ex_flags} {$ids_str} >> "
             . escapeshellarg($logfile) . ' 2>&1';
        exec('nohup bash -c ' . escapeshellarg($cmd) . ' &');
        echo json_encode([
            'status'  => 'started',
            'message' => 'rewrite started in background',
            'logfile' => $logfile,
        ]);
        break;
    }

    // =========================================================================
    // SNAPSHOT COPY — copy snapshots to another target within the same job
    // =========================================================================
    case 'snapshot_copy': {
        $job_id        = $data['job_id']         ?? '';
        $src_target_id = $data['src_target_id']  ?? '';
        $dst_job_id    = $data['dst_job_id']     ?? $job_id;   // default: same job
        $dst_target_id = $data['dst_target_id']  ?? '';
        $snapshot_ids  = $data['snapshot_ids']   ?? [];
        if (!is_array($snapshot_ids)) $snapshot_ids = [$snapshot_ids];

        if ($job_id === $dst_job_id && $src_target_id === $dst_target_id) {
            echo json_encode(['status' => 'error', 'message' => 'Source and destination must differ']);
            break;
        }

        $src = restic_get_target_config($job_id,     $src_target_id);
        $dst = restic_get_target_config($dst_job_id, $dst_target_id);
        if (!$src || !$dst) {
            echo json_encode(['status' => 'error', 'message' => 'Source or destination not found']);
            break;
        }

        // For `restic copy`, the destination repository URL/password go via the
        // --repo2 flag and RESTIC_PASSWORD2/RESTIC_PASSWORD_FILE2 env vars.
        // Source creds (S3/B2/password) go through the normal env.
        $env_pairs = $src['env'];
        foreach (($dst['env'] ?? []) as $k => $v) {
            // Rename RESTIC_PASSWORD/FILE → RESTIC_PASSWORD2/FILE2 so both
            // repos can coexist. Other vars (S3/B2) would collide — in which
            // case the user needs the same credentials on both sides.
            if     ($k === 'RESTIC_PASSWORD')      $env_pairs['RESTIC_PASSWORD2']      = $v;
            elseif ($k === 'RESTIC_PASSWORD_FILE') $env_pairs['RESTIC_PASSWORD_FILE2'] = $v;
            elseif (!isset($env_pairs[$k]))         $env_pairs[$k]                     = $v;
        }
        $env_str = restic_build_env_str($env_pairs);
        $sfx     = implode(' ', array_map('escapeshellarg', $src['sftp_args']));
        $lim     = implode(' ', array_map('escapeshellarg', $src['limit_args'] ?? []));

        $ids = [];
        foreach ($snapshot_ids as $sid) {
            $clean = preg_replace('/[^a-fA-F0-9]/', '', (string)$sid);
            if ($clean !== '') $ids[] = $clean;
        }
        // Empty = copy ALL snapshots
        $ids_str = $ids ? (' ' . implode(' ', array_map('escapeshellarg', $ids))) : '';

        // Optional snapshot-level filters (only applied when no explicit IDs).
        $filter = '';
        if (!$ids) {
            $fh = trim((string)($data['filter_host'] ?? ''));
            $ft = trim((string)($data['filter_tag']  ?? ''));
            $fp = trim((string)($data['filter_path'] ?? ''));
            if ($fh !== '') $filter .= ' --host ' . escapeshellarg($fh);
            if ($ft !== '') $filter .= ' --tag '  . escapeshellarg($ft);
            if ($fp !== '') $filter .= ' --path ' . escapeshellarg($fp);
        }

        $logfile = RESTIC_LOG_DIR . '/restic-copy-' . date('Ymd-His') . '.log';
        $cmd = "{$env_str} restic -r " . escapeshellarg($src['url'])
             . ($sfx ? " $sfx" : '') . ($lim ? " $lim" : '')
             . ' --repo2 ' . escapeshellarg($dst['url'])
             . ' copy' . $filter . $ids_str
             . ' >> ' . escapeshellarg($logfile) . ' 2>&1';
        exec('nohup bash -c ' . escapeshellarg($cmd) . ' &');
        echo json_encode([
            'status'  => 'started',
            'message' => 'copy started in background' . ($ids ? '' : ' (all snapshots)'),
            'logfile' => $logfile,
        ]);
        break;
    }

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
