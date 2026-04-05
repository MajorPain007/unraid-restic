<?php
/**
 * Restic Backup Plugin - Helper Functions
 */

define('RESTIC_PLUGIN_NAME', 'restic-backup');
define('RESTIC_CONFIG_DIR', '/boot/config/plugins/' . RESTIC_PLUGIN_NAME);
define('RESTIC_CONFIG_FILE', RESTIC_CONFIG_DIR . '/restic-backup.json');
define('RESTIC_PLUGIN_DIR', '/usr/local/emhttp/plugins/' . RESTIC_PLUGIN_NAME);
define('RESTIC_SCRIPT', RESTIC_PLUGIN_DIR . '/scripts/restic-backup.py');
define('RESTIC_LOG_DIR', '/tmp');
define('RESTIC_PID_FILE', '/tmp/restic-backup.pid');
define('RESTIC_LOCK_FILE', '/tmp/restic-backup.lock');

/**
 * Returns the default (empty) config structure.
 */
function restic_default_config(): array {
    return [
        'general' => [
            'password_mode'    => 'file',
            'password_file'    => '',
            'password_inline'  => '',
            'hostname'         => '',
            'tags'             => '',
            'max_retries'      => 3,
            'retry_wait'       => 30,
            'check_enabled'    => false,
            'check_percentage' => '2%',
            'check_schedule'   => 'sunday',
        ],
        'targets' => [],
        'sources' => [],
        'excludes' => [
            'global'   => [],
            'optional' => [],
        ],
        'zfs' => [
            'enabled'         => false,
            'datasets'        => [],
            'recursive'       => true,
            'snapshot_prefix'  => 'restic-backup',
        ],
        'schedule' => [
            'enabled' => false,
            'cron'    => '',
        ],
        'notifications' => [
            'enabled' => true,
        ],
    ];
}

/**
 * Loads the plugin config. Returns default config if file doesn't exist.
 */
function restic_load_config(): array {
    if (!file_exists(RESTIC_CONFIG_FILE)) {
        return restic_default_config();
    }
    $json = file_get_contents(RESTIC_CONFIG_FILE);
    $config = json_decode($json, true);
    if (!is_array($config)) {
        return restic_default_config();
    }
    return array_replace_recursive(restic_default_config(), $config);
}

/**
 * Saves the plugin config to disk.
 */
function restic_save_config(array $config): bool {
    if (!is_dir(RESTIC_CONFIG_DIR)) {
        mkdir(RESTIC_CONFIG_DIR, 0755, true);
    }
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents(RESTIC_CONFIG_FILE, $json) !== false;
}

/**
 * Generate a simple unique ID for targets/sources.
 */
function restic_generate_id(): string {
    return bin2hex(random_bytes(8));
}

/**
 * Checks if a backup is currently running.
 */
function restic_is_running(): bool {
    if (!file_exists(RESTIC_PID_FILE)) {
        return false;
    }
    $pid = trim(file_get_contents(RESTIC_PID_FILE));
    if (!$pid || !is_numeric($pid)) {
        return false;
    }
    return file_exists("/proc/{$pid}");
}

/**
 * Gets the PID of the running backup, or 0.
 */
function restic_get_pid(): int {
    if (!file_exists(RESTIC_PID_FILE)) {
        return 0;
    }
    $pid = trim(file_get_contents(RESTIC_PID_FILE));
    if (!$pid || !is_numeric($pid)) {
        return 0;
    }
    if (!file_exists("/proc/{$pid}")) {
        @unlink(RESTIC_PID_FILE);
        return 0;
    }
    return (int)$pid;
}

/**
 * Returns the path to today's log file.
 */
function restic_log_file(): string {
    return RESTIC_LOG_DIR . '/restic-backup-' . date('Ymd') . '.log';
}

/**
 * Reads the last N lines of the current log file.
 */
function restic_read_log(int $lines = 100): string {
    $logfile = restic_log_file();
    if (!file_exists($logfile)) {
        return '';
    }
    $output = [];
    exec("tail -n {$lines} " . escapeshellarg($logfile), $output);
    return implode("\n", $output);
}

/**
 * Updates the cron schedule based on config.
 */
function restic_update_cron(array $config): void {
    $cron_file = '/etc/cron.d/restic-backup';
    if ($config['schedule']['enabled'] && !empty($config['schedule']['cron'])) {
        $cron_expr = $config['schedule']['cron'];
        $line = "{$cron_expr} root /usr/bin/python3 " . RESTIC_SCRIPT . " --backup > /dev/null 2>&1\n";
        file_put_contents($cron_file, $line);
    } else {
        @unlink($cron_file);
    }
}

/**
 * Sanitize a config value received from POST.
 */
function restic_sanitize_path(string $path): string {
    $path = trim($path);
    $path = str_replace(['..', "\0"], '', $path);
    return $path;
}
