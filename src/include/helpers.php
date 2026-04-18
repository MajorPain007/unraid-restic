<?php
/**
 * Restic Backup Plugin - Helper Functions
 */

define('RESTIC_PLUGIN_NAME', 'restic-backup');
define('RESTIC_CONFIG_DIR', '/boot/config/plugins/' . RESTIC_PLUGIN_NAME);
define('RESTIC_CONFIG_FILE', RESTIC_CONFIG_DIR . '/restic-backup.json');
define('RESTIC_HOOKS_DIR',  RESTIC_CONFIG_DIR . '/hooks');
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
        'verify' => [
            // Restore-verification: after each successful target backup,
            // restore one known file from the newly-created snapshot and
            // byte-compare it with the live source. Catches silent corruption
            // and "backups that never actually restore" surprises.
            'enabled' => false,
            'path'    => '', // absolute path to a small file inside the sources
        ],
        'max_retries' => 3,
        'retry_wait'  => 30,
        'tags'        => '',
        'hooks' => [
            // Pre-backup hooks run as the very first thing in the job
            // (before ZFS snapshots, before collecting paths, before restic).
            'pre_backup'  => [],
            // Post-backup hooks run after all targets are processed,
            // regardless of success/failure. They receive RESTIC_STATUS.
            'post_backup' => [],
        ],
    ];
}

/**
 * Returns a default hook entry structure.
 */
function restic_default_hook(): array {
    return [
        'id'       => restic_generate_id(),
        'name'     => '',
        'command'  => '',
        'enabled'  => true,
        'timeout'  => 3600,
        'on_error' => 'continue',   // "abort" | "continue" (pre only respects abort)
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
    // Back-fill hook structure for older configs that predate the feature.
    // Also normalize target URLs: strip any doubled protocol prefix (legacy
    // artifact from an earlier UI bug) so reads like snapshot_browse always
    // see exactly one `sftp://` / `s3:` / etc.
    $pfxMap = ['sftp'=>'sftp://','s3'=>'s3:','b2'=>'b2:','rest'=>'rest:','rclone'=>'rclone:'];
    foreach ($config['jobs'] as &$_job) {
        if (!isset($_job['hooks']) || !is_array($_job['hooks'])) {
            $_job['hooks'] = ['pre_backup' => [], 'post_backup' => []];
        }
        if (!isset($_job['hooks']['pre_backup']))  { $_job['hooks']['pre_backup']  = []; }
        if (!isset($_job['hooks']['post_backup'])) { $_job['hooks']['post_backup'] = []; }
        if (isset($_job['targets']) && is_array($_job['targets'])) {
            foreach ($_job['targets'] as &$_tgt) {
                $_ttype = $_tgt['type'] ?? 'local';
                $_pfx   = $pfxMap[$_ttype] ?? '';
                if ($_pfx === '' || !isset($_tgt['url'])) continue;
                $_url = $_tgt['url'];
                while (strncmp($_url, $_pfx . $_pfx, strlen($_pfx) * 2) === 0) {
                    $_url = substr($_url, strlen($_pfx));
                }
                $_tgt['url'] = $_url;
            }
            unset($_tgt);
        }
    }
    unset($_job);
    return $config;
}

/**
 * Saves the plugin config to disk and materializes hook scripts under
 * /boot/config/plugins/restic-backup/hooks/<jobid>/ so the user can inspect
 * (or SSH-edit) the exact scripts that will run.
 *
 * Atomic save: writes to .json.new + fsync + rename, so a power loss during
 * the write cannot corrupt the existing config. Permissions are locked down
 * to 0600 because the config contains passwords/credentials in plaintext.
 */
function restic_save_config(array $config): bool {
    if (!is_dir(RESTIC_CONFIG_DIR)) {
        @mkdir(RESTIC_CONFIG_DIR, 0755, true);
    }
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = RESTIC_CONFIG_FILE . '.new';
    $fh  = @fopen($tmp, 'wb');
    if ($fh === false) {
        return false;
    }
    if (@fwrite($fh, $json) === false) {
        @fclose($fh);
        @unlink($tmp);
        return false;
    }
    @fflush($fh);
    // fsync() requires PHP 8.1; fall back silently on older installs.
    if (function_exists('fsync')) { @fsync($fh); }
    @fclose($fh);
    @chmod($tmp, 0600);

    if (!@rename($tmp, RESTIC_CONFIG_FILE)) {
        @unlink($tmp);
        return false;
    }
    @chmod(RESTIC_CONFIG_FILE, 0600);

    // Best-effort: write hook scripts. Failure here must not fail the save.
    @restic_write_hook_scripts($config);
    return true;
}

/**
 * Materializes all per-job hook commands as real .sh files under
 * /boot/config/plugins/restic-backup/hooks/<jobid>/{pre,post}-<idx>-<slug>.sh
 *
 * Files for jobs/hooks that no longer exist are deleted so the hooks/ tree
 * stays in sync with the config.
 */
function restic_write_hook_scripts(array $config): void {
    $root = RESTIC_HOOKS_DIR;
    if (!is_dir($root)) {
        @mkdir($root, 0700, true);
    }
    @chmod($root, 0700);
    // Collect which directories/files we want to keep.
    $keep_dirs  = [];
    $keep_files = [];

    foreach ($config['jobs'] ?? [] as $job) {
        $jid = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($job['id'] ?? ''));
        if ($jid === '') { continue; }
        $job_dir = $root . '/' . $jid;
        $keep_dirs[$job_dir] = true;
        if (!is_dir($job_dir)) { @mkdir($job_dir, 0700, true); }
        @chmod($job_dir, 0700);

        foreach (['pre_backup' => 'pre', 'post_backup' => 'post'] as $key => $phase) {
            $list = $job['hooks'][$key] ?? [];
            foreach ($list as $idx => $hook) {
                $slug = _restic_slug($hook['name'] ?? '');
                $fname = sprintf('%s-%02d-%s.sh', $phase, $idx + 1, $slug !== '' ? $slug : 'hook');
                $path  = $job_dir . '/' . $fname;
                $keep_files[$path] = true;

                $body  = "#!/bin/bash\n";
                $body .= "# Auto-generated from restic-backup plugin config.\n";
                $body .= "# Job: "   . ($job['name']   ?? '') . "\n";
                $body .= "# Hook: "  . ($hook['name']  ?? '') . "  (phase=$phase, id=" . ($hook['id'] ?? '') . ")\n";
                $body .= "# Enabled=" . (!empty($hook['enabled']) ? 'yes' : 'no')
                       . "  Timeout=" . intval($hook['timeout'] ?? 3600) . "s"
                       . "  OnError=" . ($hook['on_error'] ?? 'continue') . "\n";
                $body .= "# -- edit the command in the Web UI; manual edits here are overwritten on save --\n";
                // set -eo pipefail so a failing command in the middle of a
                // pipeline (e.g. `docker exec | gzip > file`) actually fails.
                $body .= "set -eo pipefail\n\n";
                $body .= ($hook['command'] ?? '') . "\n";

                // Only rewrite if content differs — avoids unnecessary USB writes.
                // is_file() check avoids a warning on first-time creation where
                // the file does not yet exist (@ is not always honored by
                // custom error handlers in PHP 8+).
                $existing = is_file($path) ? @file_get_contents($path) : false;
                if ($existing !== $body) {
                    @file_put_contents($path, $body);
                }
                @chmod($path, 0700);
            }
        }
    }

    // Prune stale files/directories that no longer correspond to any hook.
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $jdir) {
        if (!isset($keep_dirs[$jdir])) {
            // Remove all .sh files then the directory.
            foreach (glob($jdir . '/*.sh') ?: [] as $f) { @unlink($f); }
            @rmdir($jdir);
            continue;
        }
        foreach (glob($jdir . '/*.sh') ?: [] as $f) {
            if (!isset($keep_files[$f])) { @unlink($f); }
        }
    }
}

/**
 * Filesystem-safe slug for hook names.
 */
function _restic_slug(string $name): string {
    $s = strtolower($name);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
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
 * If $job_id is given, returns the per-job log file for that job
 * (restic-backup-<job_id>-YYYYMMDD.log). Empty/null means the
 * combined main log (restic-backup-YYYYMMDD.log).
 */
function restic_log_file(string $job_id = ''): string {
    $date = date('Ymd');
    if ($job_id !== '') {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $job_id);
        return RESTIC_LOG_DIR . "/restic-backup-{$safe}-{$date}.log";
    }
    return RESTIC_LOG_DIR . "/restic-backup-{$date}.log";
}

/**
 * Reads the last N lines of a log file. Pass a $job_id to read the
 * per-job log, or leave empty for the combined main log.
 */
function restic_read_log(int $lines = 100, string $job_id = ''): string {
    $logfile = restic_log_file($job_id);
    if (!file_exists($logfile)) {
        return '';
    }
    $output = [];
    exec("tail -n " . intval($lines) . " " . escapeshellarg($logfile), $output);
    return implode("\n", $output);
}

/**
 * Updates all cron schedules from jobs.
 *
 * Every cron entry is wrapped in `flock -w 21600 /tmp/restic-backup.queue`
 * so that if two jobs fire at the same minute they run sequentially instead
 * of the second one aborting on the PID lock. The 6h wait window is plenty
 * even for big repos; longer waits are treated as a failed backup.
 */
function restic_update_cron(array $config): void {
    $cron_file  = '/etc/cron.d/restic-backup';
    $queue_lock = '/tmp/restic-backup.queue';
    $lines      = [];
    foreach ($config['jobs'] as $job) {
        if (!empty($job['schedule']['enabled']) && !empty($job['schedule']['cron']) && !empty($job['enabled'])) {
            $cron_expr = $job['schedule']['cron'];
            $job_id    = $job['id'];
            $cmd       = "/usr/bin/python3 " . RESTIC_SCRIPT . " --backup --job " . escapeshellarg($job_id);
            // flock blocks for up to 6h; without the lock parallel cron
            // entries at the same minute collide on the restic-backup PID.
            $lines[]   = "{$cron_expr} root /usr/bin/flock -w 21600 {$queue_lock} {$cmd}";
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

    $sftp_args  = function_exists('restic_sftp_args')  ? restic_sftp_args($type, $creds) : [];
    $limit_args = function_exists('restic_limit_args') ? restic_limit_args($target)      : [];

    return compact('url', 'env', 'sftp_args', 'limit_args', 'target', 'job');
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
