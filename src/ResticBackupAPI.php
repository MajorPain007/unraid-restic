<?php
/**
 * Restic Backup Plugin - API Endpoint (v3)
 *
 * ALL requests are POST-only with JSON body.
 * No query parameters are used - avoids ad-blocker interference.
 */
require_once '/usr/local/emhttp/plugins/restic-backup/include/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Read JSON body (all requests are POST)
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
}
$action = $input['action'] ?? '';

switch ($action) {

    // =========================================================================
    // SAVE CONFIG
    // =========================================================================
    case 'save':
        $config = $input['config'] ?? null;

        if (!is_array($config)) {
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'message' => 'No config data received. Raw input length: ' . strlen($raw)
            ]);
            break;
        }

        // Ensure structure
        if (!isset($config['general'])) {
            $config['general'] = restic_default_config()['general'];
        }
        if (!isset($config['jobs']) || !is_array($config['jobs'])) {
            $config['jobs'] = [];
        }

        $jobCount = count($config['jobs']);

        if (restic_save_config($config)) {
            // Verify the file was actually written
            $fileSize = file_exists(RESTIC_CONFIG_FILE) ? filesize(RESTIC_CONFIG_FILE) : 0;
            restic_update_cron($config);
            echo json_encode([
                'status'  => 'success',
                'message' => "Saved ({$fileSize} bytes, {$jobCount} jobs)",
                'file'    => RESTIC_CONFIG_FILE,
                'size'    => $fileSize,
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Write failed!',
                'dir_exists'  => is_dir(RESTIC_CONFIG_DIR),
                'dir_writable' => is_writable(RESTIC_CONFIG_DIR),
                'dir'     => RESTIC_CONFIG_DIR,
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

        $job_id = $input['job_id'] ?? '';
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
        $url = $input['url'] ?? '';
        $pw_mode = $input['password_mode'] ?? 'file';
        $pw_file = $input['password_file'] ?? '';
        $pw_inline = $input['password_inline'] ?? '';

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
        $url = $input['url'] ?? '';
        $pw_mode = $input['password_mode'] ?? 'file';
        $pw_file = $input['password_file'] ?? '';
        $pw_inline = $input['password_inline'] ?? '';

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
            'api_version' => 3,
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
        $url = $input['url'] ?? '';
        $pw_mode = $input['password_mode'] ?? 'file';
        $pw_file = $input['password_file'] ?? '';
        $pw_inline = $input['password_inline'] ?? '';

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
    // DEBUG - Check config file on disk
    // =========================================================================
    case 'debug':
        $exists = file_exists(RESTIC_CONFIG_FILE);
        $content = $exists ? file_get_contents(RESTIC_CONFIG_FILE) : null;
        $size = $exists ? filesize(RESTIC_CONFIG_FILE) : 0;
        echo json_encode([
            'api_version'  => 3,
            'config_dir'   => RESTIC_CONFIG_DIR,
            'config_file'  => RESTIC_CONFIG_FILE,
            'dir_exists'   => is_dir(RESTIC_CONFIG_DIR),
            'dir_writable' => is_dir(RESTIC_CONFIG_DIR) ? is_writable(RESTIC_CONFIG_DIR) : false,
            'file_exists'  => $exists,
            'file_size'    => $size,
            'content'      => $content ? json_decode($content, true) : null,
        ]);
        break;

    // =========================================================================
    // DEFAULT
    // =========================================================================
    default:
        http_response_code(400);
        echo json_encode([
            'status'    => 'error',
            'message'   => 'Unknown or missing action: ' . $action,
            'raw_len'   => strlen($raw),
        ]);
        break;
}
