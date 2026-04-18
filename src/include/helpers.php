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
            'password_mode'   => 'file',
            'password_file'   => '',
            'password_inline' => '',
            'hostname'        => '',
            'notifications'   => true,
        ],
        'jobs' => [],
    ];
}

/**
 * Returns a default job structure.
 */
function restic_default_job(): array {
    return [
        'id'      => restic_generate_id(),
        'name'    => '',
        'enabled' => true,
        'targets' => [],
        'sources' => [],
        'zfs' => [
            'enabled'        => false,
            'datasets'       => [],
            'recursive'      => true,
            'snapshot_prefix' => 'restic-backup',
        ],
        'excludes' => [
            'global'   => [],
            'optional' => [],
        ],
        'retention' => [
            'keep_daily'   => 7,
            'keep_weekly'  => 4,
            'keep_monthly' => 0,
            'keep_yearly'  => 0,
        ],
        'schedule' => [
            'enabled' => false,
            'cron'    => '',
        ],
        'check' => [
            'enabled'    => false,
            'percentage' => '2%',
            'schedule'   => 'sunday',
        ],
        'max_retries' => 3,
        'retry_wait'  => 30,
        'tags'        => '',
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
    if ($json === false) {
        return restic_default_config();
    }
    $config = json_decode($json, true);
    if (!is_array($config)) {
        return restic_default_config();
    }
    // Ensure general defaults exist
    $defaults = restic_default_config();
    if (!isset($config['general'])) {
        $config['general'] = $defaults['general'];
    } else {
        $config['general'] = array_merge($defaults['general'], $config['general']);
    }
    if (!isset($config['jobs']) || !is_array($config['jobs'])) {
        $config['jobs'] = [];
    }
    return $config;
}

/**
 * Saves the plugin config to disk.
 */
function restic_save_config(array $config): bool {
    if (!is_dir(RESTIC_CONFIG_DIR)) {
        @mkdir(RESTIC_CONFIG_DIR, 0755, true);
    }
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $result = @file_put_contents(RESTIC_CONFIG_FILE, $json);
    return $result !== false;
}

/**
 * Generate a simple unique ID.
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
    exec("tail -n " . intval($lines) . " " . escapeshellarg($logfile), $output);
    return implode("\n", $output);
}

/**
 * Updates all cron schedules from jobs.
 */
function restic_update_cron(array $config): void {
    $cron_file = '/etc/cron.d/restic-backup';
    $lines = [];
    foreach ($config['jobs'] as $job) {
        if (!empty($job['schedule']['enabled']) && !empty($job['schedule']['cron']) && !empty($job['enabled'])) {
            $cron_expr = $job['schedule']['cron'];
            $job_id = $job['id'];
            $lines[] = "{$cron_expr} root /usr/bin/python3 " . RESTIC_SCRIPT . " --backup --job {$job_id}";
        }
    }
    if (!empty($lines)) {
        @file_put_contents($cron_file, implode("\n", $lines) . "\n");
    } elseif (file_exists($cron_file)) {
        @unlink($cron_file);
    }
}

/**
 * List directories at a given path.
 */
function restic_list_dirs(string $path): array {
    $path = rtrim($path, '/');
    if (!is_dir($path)) {
        return [];
    }
    $dirs = [];
    $items = @scandir($path);
    if ($items === false) {
        return [];
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $path . '/' . $item;
        if (is_dir($full) && $item[0] !== '.') {
            $dirs[] = $full;
        }
    }
    sort($dirs);
    return $dirs;
}

/**
 * List regular files at a given path.
 * Returns array of ['path' => full, 'name' => basename, 'size' => bytes].
 * Hidden files (starting with '.') are skipped.
 */
function restic_list_files(string $path): array {
    $path = rtrim($path, '/');
    if (!is_dir($path)) {
        return [];
    }
    $files = [];
    $items = @scandir($path);
    if ($items === false) {
        return [];
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item[0] === '.') continue;
        $full = $path . '/' . $item;
        if (is_file($full)) {
            $files[] = [
                'path' => $full,
                'name' => $item,
                'size' => @filesize($full) ?: 0,
            ];
        }
    }
    // sort by name
    usort($files, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return $files;
}

/**
 * Build a shell env-var prefix string from an associative array.
 * Keys must be [A-Z_][A-Z0-9_]* to be included.
 */
function restic_build_env_str(array $env): string {
    $parts = [];
    foreach ($env as $k => $v) {
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) {
            $parts[] = $k . '=' . escapeshellarg((string)$v);
        }
    }
    return $parts ? implode(' ', $parts) . ' ' : '';
}

/**
 * Load job + target from saved config and build everything needed to run restic.
 * Returns array with keys: url, env, sftp_args, target, job  — or null on error.
 */
function restic_get_target_config(string $job_id, string $target_id): ?array {
    $config = restic_load_config();
    $general = $config['general'] ?? [];

    $job = null;
    foreach ($config['jobs'] as $j) {
        if (($j['id'] ?? '') === $job_id) { $job = $j; break; }
    }
    if (!$job) return null;

    $target = null;
    foreach ($job['targets'] as $t) {
        if (($t['id'] ?? '') === $target_id) { $target = $t; break; }
    }
    if (!$target) return null;

    $type  = $target['type'] ?? 'local';
    $creds = is_array($target['credentials'] ?? null) ? $target['credentials'] : [];

    // Build URL (inject REST creds if needed)
    $url = $target['url'] ?? '';
    if ($type === 'rest' && function_exists('restic_inject_rest_creds')) {
        $url = restic_inject_rest_creds($url, $creds);
    }

    // Build env array — password is per-target; fall back to general for old configs
    $env = [];
    $pw_mode = $target['password_mode'] ?? $general['password_mode'] ?? 'file';
    if ($pw_mode === 'file') {
        $pw_file = $target['password_file'] ?? $general['password_file'] ?? '';
        if ($pw_file) $env['RESTIC_PASSWORD_FILE'] = $pw_file;
    } elseif ($pw_mode === 'inline') {
        $pw_inline = $target['password_inline'] ?? $general['password_inline'] ?? '';
        if ($pw_inline) $env['RESTIC_PASSWORD'] = $pw_inline;
    }
    if ($type === 's3') {
        if (!empty($creds['aws_access_key_id']))     $env['AWS_ACCESS_KEY_ID']     = $creds['aws_access_key_id'];
        if (!empty($creds['aws_secret_access_key'])) $env['AWS_SECRET_ACCESS_KEY'] = $creds['aws_secret_access_key'];
        if (!empty($creds['aws_region']))             $env['AWS_DEFAULT_REGION']    = $creds['aws_region'];
    } elseif ($type === 'b2') {
        if (!empty($creds['b2_account_id']))  $env['B2_ACCOUNT_ID']  = $creds['b2_account_id'];
        if (!empty($creds['b2_account_key'])) $env['B2_ACCOUNT_KEY'] = $creds['b2_account_key'];
    }

    $sftp_args = function_exists('restic_sftp_args') ? restic_sftp_args($type, $creds) : [];

    return compact('url', 'env', 'sftp_args', 'target', 'job');
}

/**
 * List ZFS datasets.
 */
function restic_list_zfs_datasets(): array {
    $output = [];
    exec('zfs list -H -t filesystem -o name 2>/dev/null', $output, $ret);
    if ($ret !== 0) {
        return [];
    }
    $datasets = [];
    foreach ($output as $line) {
        $name = trim($line);
        if ($name !== '') {
            $datasets[] = $name;
        }
    }
    sort($datasets);
    return $datasets;
}
