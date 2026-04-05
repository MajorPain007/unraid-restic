<?php
/**
 * Restic Backup Plugin - AJAX Endpoint
 * Handles all async actions from the GUI.
 */
require_once '/usr/local/emhttp/plugins/restic-backup/include/helpers.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {

    // =========================================================================
    // SAVE CONFIG
    // =========================================================================
    case 'save':
        $input = file_get_contents('php://input');
        $config = json_decode($input, true);

        if (!is_array($config)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            break;
        }

        // Merge with defaults so we don't lose structure
        $merged = array_replace_recursive(restic_default_config(), $config);

        // Overwrite arrays (array_replace_recursive merges arrays by index, not what we want)
        $merged['targets']  = $config['targets'] ?? [];
        $merged['sources']  = $config['sources'] ?? [];
        $merged['excludes'] = $config['excludes'] ?? ['global' => [], 'optional' => []];
        $merged['zfs']['datasets'] = $config['zfs']['datasets'] ?? [];

        if (restic_save_config($merged)) {
            restic_update_cron($merged);
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Could not write config file']);
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

        $cmd = '/usr/bin/python3 ' . escapeshellarg(RESTIC_SCRIPT) . ' --backup';
        $logfile = restic_log_file();
        exec("nohup {$cmd} >> " . escapeshellarg($logfile) . " 2>&1 &");

        // Brief wait to let the process start and create its PID file
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
            // Wait briefly, then force if still running
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
        $target_id = $_GET['target_id'] ?? '';
        if (!$target_id) {
            echo json_encode(['status' => 'error', 'message' => 'No target_id provided']);
            break;
        }

        $cmd = '/usr/bin/python3 ' . escapeshellarg(RESTIC_SCRIPT)
             . ' --init ' . escapeshellarg($target_id);
        $output = [];
        exec($cmd . ' 2>&1', $output, $ret);

        $result_text = implode("\n", $output);
        // Try to parse JSON from script output
        foreach ($output as $line) {
            $parsed = json_decode($line, true);
            if (is_array($parsed) && isset($parsed['status'])) {
                echo json_encode($parsed);
                break 2;
            }
        }
        echo json_encode([
            'status' => $ret === 0 ? 'success' : 'error',
            'message' => $result_text
        ]);
        break;

    // =========================================================================
    // TEST CONNECTION
    // =========================================================================
    case 'test':
        $target_id = $_GET['target_id'] ?? '';
        if (!$target_id) {
            echo json_encode(['status' => 'error', 'message' => 'No target_id provided']);
            break;
        }

        $cmd = '/usr/bin/python3 ' . escapeshellarg(RESTIC_SCRIPT)
             . ' --test ' . escapeshellarg($target_id);
        $output = [];
        exec($cmd . ' 2>&1', $output, $ret);

        $result_text = implode("\n", $output);
        foreach ($output as $line) {
            $parsed = json_decode($line, true);
            if (is_array($parsed) && isset($parsed['status'])) {
                echo json_encode($parsed);
                break 2;
            }
        }
        echo json_encode([
            'status' => $ret === 0 ? 'success' : 'error',
            'message' => $result_text
        ]);
        break;

    // =========================================================================
    // STATUS
    // =========================================================================
    case 'status':
        echo json_encode([
            'running' => restic_is_running(),
            'pid'     => restic_get_pid(),
        ]);
        break;

    // =========================================================================
    // LOG
    // =========================================================================
    case 'log':
        header('Content-Type: text/plain');
        echo restic_read_log(200);
        break;

    // =========================================================================
    // SNAPSHOTS
    // =========================================================================
    case 'snapshots':
        $target_id = $_GET['target_id'] ?? '';
        if (!$target_id) {
            echo json_encode(['status' => 'error', 'message' => 'No target_id provided']);
            break;
        }

        $cmd = '/usr/bin/python3 ' . escapeshellarg(RESTIC_SCRIPT)
             . ' --snapshots ' . escapeshellarg($target_id);
        $output = [];
        exec($cmd . ' 2>&1', $output, $ret);
        echo implode("\n", $output);
        break;

    // =========================================================================
    // DEFAULT
    // =========================================================================
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
        break;
}
