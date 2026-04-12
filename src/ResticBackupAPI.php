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

        $cmd = "{$env} restic -r " . escapeshellarg($url) . " init 2>&1";
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

        $cmd = "{$env} restic -r " . escapeshellarg($url) . " snapshots --latest 1 2>&1";
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
    // BROWSE DIRECTORIES
    // =========================================================================
    case 'browse':
        $path = $data['path'] ?? '/mnt';
        $path = str_replace(['..', "\0"], '', $path);
        if (!$path || $path[0] !== '/') {
            $path = '/mnt';
        }
        $dirs = restic_list_dirs($path);
        echo json_encode([
            'current' => $path,
            'parent'  => dirname($path),
            'dirs'    => $dirs,
        ]);
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
