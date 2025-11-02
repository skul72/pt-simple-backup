<?php
if (!defined('ABSPATH')) {
    exit;
}

function ptsb_cfg() {
    $cfg = [
        'remote'         => 'gdrive_backup:',
        'prefix'         => 'wpb-',
        'log'            => '/home/plugintema.com/Scripts/backup-wp.log',
        'lock'           => '/tmp/wpbackup.lock',
        'script_backup'  => '/home/plugintema.com/Scripts/wp-backup-to-gdrive.sh',
        'script_restore' => '/home/plugintema.com/Scripts/wp-restore-from-archive.sh',
        'download_dir'   => '/home/plugintema.com/Backups/downloads',
        'db_dump_remote_dir' => 'db-dumps',
        'drive_url'      => 'https://drive.google.com/drive/u/0/folders/18wIaInN0d0ftKhsi1BndrKmkVuOQkFoO',
        'keep_days_def'  => 12,

        // agendamento
        'tz_string'      => 'America/Sao_Paulo',
        'cron_hook'      => 'ptsb_cron_tick',
        'cron_sched'     => 'ptsb_minutely',   // 60s (visitor-based)
        'max_per_day'    => 6,
        'min_gap_min'    => 10,
        'miss_window'    => 15,
        'queue_timeout'  => 5400,              // 90min
        'lock_ttl'       => 5400,              // TTL do lock otimista (s)
        'log_max_mb'     => 3,                 // tamanho máx. do log
        'log_keep'       => 5,                 // quantos arquivos rotacionados manter

        'job_limits'     => [
            'nice'        => 10,
            'ionice'      => [
                'class'    => 2,
                'priority' => 7,
            ],
            'cpu_percent' => 70,
        ],

    ];
    /**
     * Filtros úteis:
     * - ptsb_config           : altera o array completo
     * - ptsb_remote           : altera remote rclone (ex.: 'meudrive:')
     * - ptsb_prefix           : prefixo dos arquivos (ex.: 'site-')
     * - ptsb_default_parts    : CSV padrão para PARTS (ver ptsb_start_backup)
     * - ptsb_default_ui_codes : letras padrão marcadas na UI (P,T,W,S,M,O)
     */
    $cfg = apply_filters('ptsb_config', $cfg);
    $cfg['remote'] = apply_filters('ptsb_remote', $cfg['remote']);
    $cfg['prefix'] = apply_filters('ptsb_prefix', $cfg['prefix']);
    return $cfg;
}

function ptsb_upload_storage_dir(): ?string {
    if (!function_exists('wp_upload_dir')) {
        return null;
    }

    $up   = wp_upload_dir(null, false);
    $base = isset($up['basedir']) ? (string) $up['basedir'] : '';
    if ($base === '') {
        return null;
    }

    $dir = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . 'pt-simple-backup';
    if (!@is_dir($dir)) {
        if (!function_exists('wp_mkdir_p') || !wp_mkdir_p($dir)) {
            return null;
        }
    }

    return $dir;
}

function ptsb_sanitize_blob_key(string $key): string {
    $key = preg_replace('/[^A-Za-z0-9._-]+/', '-', $key);
    $key = trim((string) $key, '.-_');
    if ($key === '') {
        $key = 'blob-' . substr(sha1($key . microtime(true)), 0, 12);
    }
    return $key;
}

function ptsb_upload_storage_path(string $key, string $suffix = '.json'): ?string {
    $dir = ptsb_upload_storage_dir();
    if ($dir === null) {
        return null;
    }

    $suffix = $suffix !== '' && $suffix[0] !== '.' ? '.' . $suffix : $suffix;
    $file   = ptsb_sanitize_blob_key($key) . $suffix;

    return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
}

function ptsb_blob_write_json(string $key, $data): ?array {
    $path = ptsb_upload_storage_path($key, '.json');
    if ($path === null) {
        return null;
    }

    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return null;
    }

    $written = @file_put_contents($path, $json);
    if ($written === false) {
        return null;
    }

    return [
        'path'  => $path,
        'bytes' => (int) $written,
    ];
}

function ptsb_blob_cleanup(string $pattern, int $maxAge = 86400, ?string $dir = null): void {
    $dir = $dir ?? ptsb_upload_storage_dir();
    if ($dir === null) {
        return;
    }

    $globPattern = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pattern;
    $files       = @glob($globPattern);
    if (!is_array($files)) {
        return;
    }

    $now = time();
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $maxAge) {
            @unlink($file);
        }
    }
}

function ptsb_telemetry_pending_key(): string {
    return 'ptsb_telemetry_pending';
}

function ptsb_telemetry_history_key(): string {
    return 'ptsb_telemetry_history';
}

function ptsb_telemetry_prepare_run(array $context = []): ?array {
    $runId = (string) ($context['id'] ?? ptsb_uuid4());
    $path  = ptsb_upload_storage_path('telemetry-' . $runId, '.json');
    if ($path === null) {
        return null;
    }

    $now     = time();
    $pending = get_option(ptsb_telemetry_pending_key(), []);
    if (!is_array($pending)) {
        $pending = [];
    }

    $entry = [
        'id'         => $runId,
        'path'       => $path,
        'created_at' => $now,
        'plan_id'    => isset($context['plan_id']) ? (string) $context['plan_id'] : '',
        'plan_path'  => isset($context['plan_path']) ? (string) $context['plan_path'] : '',
        'parts_csv'  => isset($context['parts_csv']) ? (string) $context['parts_csv'] : '',
        'prefix'     => isset($context['prefix']) ? (string) $context['prefix'] : '',
        'keep_days'  => isset($context['keep_days']) ? (int) $context['keep_days'] : -1,
        'origin'     => isset($context['origin']) ? (string) $context['origin'] : '',
    ];
    if (isset($context['job_id'])) {
        $entry['job_id'] = (string) $context['job_id'];
    }

    $pending[$entry['id']] = $entry;
    update_option(ptsb_telemetry_pending_key(), $pending, false);

    return $entry;
}

function ptsb_telemetry_normalize(array $entry, array $data, string $path, int $now): array {
    $started  = isset($data['started_at']) ? (int) $data['started_at'] : (int) ($entry['created_at'] ?? $now);
    $finished = isset($data['finished_at']) ? (int) $data['finished_at'] : ($data['ended_at'] ?? $started);
    $finished = (int) max($finished, $started);

    $durationMs = isset($data['duration_ms']) ? (int) $data['duration_ms'] : (($finished - $started) * 1000);
    if ($durationMs < 0) {
        $durationMs = 0;
    }

    $summary = [
        'id'                 => (string) ($entry['id'] ?? ''),
        'plan_id'            => (string) ($entry['plan_id'] ?? ''),
        'plan_path'          => (string) ($entry['plan_path'] ?? ''),
        'parts_csv'          => (string) ($entry['parts_csv'] ?? ''),
        'prefix'             => (string) ($entry['prefix'] ?? ''),
        'keep_days'          => (int) ($entry['keep_days'] ?? -1),
        'origin'             => (string) ($entry['origin'] ?? ''),
        'job_id'             => isset($entry['job_id']) ? (string) $entry['job_id'] : '',
        'started_at'         => $started,
        'finished_at'        => $finished,
        'duration_ms'        => $durationMs,
        'transfer_bytes'     => (int) ($data['transfer_bytes'] ?? ($data['bytes_transferred'] ?? ($data['bytes'] ?? 0))),
        'io_wait_ms'         => (int) ($data['io_wait_ms'] ?? ($data['io_wait'] ?? 0)),
        'memory_peak_bytes'  => (int) ($data['memory_peak_bytes'] ?? ($data['memory_peak'] ?? 0)),
        'status'             => isset($data['status']) ? (string) $data['status'] : '',
        'error'              => isset($data['error']) ? (string) $data['error'] : '',
        'steps'              => [],
    ];

    if (!empty($data['steps']) && is_array($data['steps'])) {
        $steps = [];
        foreach ($data['steps'] as $step) {
            if (!is_array($step)) {
                continue;
            }

            $label = isset($step['name']) ? (string) $step['name'] : (string) ($step['stage'] ?? '');
            $label = trim($label);
            if ($label === '') {
                continue;
            }

            $shortLabel = function_exists('mb_substr') ? mb_substr($label, 0, 60) : substr($label, 0, 60);
            $steps[] = [
                'name'        => $shortLabel,
                'duration_ms' => isset($step['duration_ms']) ? (int) $step['duration_ms'] : (int) ($step['duration'] ?? 0),
                'bytes'       => isset($step['bytes']) ? (int) $step['bytes'] : (int) ($step['transfer_bytes'] ?? 0),
            ];

            if (count($steps) >= 8) {
                break;
            }
        }

        if ($steps) {
            $summary['steps'] = $steps;
        }
    }

    if (!empty($path)) {
        $summary['source'] = basename($path);
    }

    return $summary;
}

function ptsb_telemetry_collect(): void {
    $pending = get_option(ptsb_telemetry_pending_key(), []);
    if (!is_array($pending)) {
        $pending = [];
    }

    $history = get_option(ptsb_telemetry_history_key(), []);
    if (!is_array($history)) {
        $history = [];
    }

    $now             = time();
    $pendingChanged  = false;
    $historyChanged  = false;
    $staleThreshold  = 3 * DAY_IN_SECONDS;

    foreach ($pending as $id => $entry) {
        $path = isset($entry['path']) ? (string) $entry['path'] : '';
        if ($path === '') {
            unset($pending[$id]);
            $pendingChanged = true;
            continue;
        }

        if (!@file_exists($path)) {
            $created = (int) ($entry['created_at'] ?? $now);
            if (($now - $created) > $staleThreshold) {
                unset($pending[$id]);
                $pendingChanged = true;
            }
            continue;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim((string) $raw) === '') {
            $mtime = @filemtime($path);
            if ($mtime !== false && ($now - $mtime) > $staleThreshold) {
                unset($pending[$id]);
                $pendingChanged = true;
                @unlink($path);
            }
            continue;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            unset($pending[$id]);
            $pendingChanged = true;
            @unlink($path);
            continue;
        }

        $summary = ptsb_telemetry_normalize($entry, $data, $path, $now);
        array_unshift($history, $summary);
        $historyChanged = true;
        unset($pending[$id]);
        $pendingChanged = true;
        @unlink($path);
    }

    if ($historyChanged) {
        $maxAge = 45 * DAY_IN_SECONDS;
        $history = array_values(array_filter($history, function ($row) use ($now, $maxAge) {
            $ts = isset($row['finished_at']) ? (int) $row['finished_at'] : (int) ($row['started_at'] ?? 0);
            if ($ts <= 0) {
                return true;
            }
            return ($now - $ts) <= $maxAge;
        }));
        if (count($history) > 40) {
            $history = array_slice($history, 0, 40);
        }
        update_option(ptsb_telemetry_history_key(), $history, false);
    }

    if ($pendingChanged) {
        if ($pending) {
            update_option(ptsb_telemetry_pending_key(), $pending, false);
        } else {
            delete_option(ptsb_telemetry_pending_key());
        }
    }

    ptsb_blob_cleanup('telemetry-*.json', 60 * DAY_IN_SECONDS);
}

function ptsb_tz() {
    $cfg = ptsb_cfg();
    try { return new DateTimeZone($cfg['tz_string']); } catch(Throwable $e){ return new DateTimeZone('America/Sao_Paulo'); }
}

function ptsb_now_brt() { return new DateTimeImmutable('now', ptsb_tz()); }

function ptsb_fmt_local_dt($iso) {
    try {
        $tz  = ptsb_tz();
        $dt  = new DateTimeImmutable($iso);
        $dt2 = $dt->setTimezone($tz);
        return $dt2->format('d/m/Y - H:i:s');
    } catch (Throwable $e) { return $iso; }
}

function ptsb_hsize($bytes) {
    $b = (float)$bytes;
    if ($b >= 1073741824) return number_format_i18n($b/1073741824, 2) . ' GB';
    return number_format_i18n(max($b/1048576, 0.01), 2) . ' MB';
}

function ptsb_hsize_compact($bytes) {
    $b = (float)$bytes;
    $tb = 1099511627776; $gb = 1073741824; $mb = 1048576;
    if ($b >= $tb) return number_format_i18n($b/$tb, 1).' TB';
    if ($b >= $gb) return number_format_i18n($b/$gb, 1).' GB';
    return number_format_i18n(max($b/$mb,0.01), 1).' MB';
}

function ptsb_size_to_bytes($numStr, $unit) {
    $num  = (float)str_replace(',', '.', $numStr);
    $unit = strtoupper(trim($unit));
    $map = ['B'=>1,'KB'=>1024,'MB'=>1024**2,'GB'=>1024**3,'TB'=>1024**4,'KIB'=>1024,'MIB'=>1024**2,'GIB'=>1024**3,'TIB'=>1024**4];
    return (int)round($num * ($map[$unit] ?? 1));
}

function ptsb_tar_to_json(string $tar): string {
    return preg_replace('/\.tar\.gz$/i', '.json', $tar);
}

function ptsb_slug_prefix(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    if (function_exists('sanitize_title')) {
        $slug = sanitize_title($name);
    } else {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)));
        $slug = trim($slug, '-');
    }
    return $slug ? ($slug . '-') : '';
}

function ptsb_to_utf8($s) {
    if ($s === null) return '';
    if (function_exists('seems_utf8') && seems_utf8($s)) return $s;
    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $enc = mb_detect_encoding($s, ['UTF-8','ISO-8859-1','Windows-1252','ASCII'], true);
        if ($enc && $enc !== 'UTF-8') return mb_convert_encoding($s, 'UTF-8', $enc);
    }
    $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $out !== false ? $out : $s;
}

function ptsb_settings() {
    $cfg = ptsb_cfg();
     $d = max(1, (int) get_option('ptsb_keep_days', $cfg['keep_days_def']));
    $d = min($d, 3650);
    return ['keep_days'=>$d];
}

function ptsb_plan_mark_keep_next($prefix){
    $prefix = (string)$prefix;
    if ($prefix === '') $prefix = ptsb_cfg()['prefix'];
    update_option('ptsb_mark_keep_plan', ['prefix'=>$prefix, 'set_at'=>time()], true);
}

function ptsb_lock_option_name(): string {
    return 'ptsb_lock_active';
}

function ptsb_lock_defaults(array $lock = []): array {
    return $lock + [
        'pid'      => 0,
        'timestamp'=> 0,
        'token'    => '',
        'context'  => '',
    ];
}

function ptsb_lock_ttl(): int {
    $cfg = ptsb_cfg();
    $ttl = (int)($cfg['lock_ttl'] ?? $cfg['queue_timeout'] ?? 3600);
    return max(60, $ttl);
}

function ptsb_lock_new_token(): string {
    try {
        return bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        return substr(sha1(uniqid('', true)), 0, 12);
    }
}

function ptsb_lock_read(): array {
    $lock = get_option(ptsb_lock_option_name(), []);
    if (!is_array($lock)) {
        delete_option(ptsb_lock_option_name());
        return [];
    }
    return ptsb_lock_defaults($lock);
}

function ptsb_lock_write(array $lock): void {
    $lock = ptsb_lock_defaults($lock);
    if (!add_option(ptsb_lock_option_name(), $lock, '', 'no')) {
        update_option(ptsb_lock_option_name(), $lock, false);
    }
}

function ptsb_lock_clear(): void {
    delete_option(ptsb_lock_option_name());
}

function ptsb_lock_is_expired(array $lock, ?int $now = null): bool {
    $now = $now ?? time();
    $ts  = (int)($lock['timestamp'] ?? 0);
    if ($ts <= 0) {
        return true;
    }
    return ($now - $ts) > ptsb_lock_ttl();
}

function ptsb_lock_is_locked(): bool {
    $cfg  = ptsb_cfg();
    $file = (string)($cfg['lock'] ?? '');
    $now  = time();
    $lock = ptsb_lock_read();

    if ($file !== '' && @file_exists($file)) {
        $lock['timestamp'] = $now;
        if (empty($lock['pid']) && function_exists('getmypid')) {
            $lock['pid'] = (int) getmypid();
        }
        if (empty($lock['token'])) {
            $lock['token'] = ptsb_lock_new_token();
        }
        $lock['context'] = $lock['context'] ?: 'external';
        ptsb_lock_write($lock);
        return true;
    }

    if (!$lock) {
        return false;
    }

    if (($lock['context'] ?? '') === 'external') {
        ptsb_lock_release(null, true);
        return false;
    }

    if (ptsb_lock_is_expired($lock, $now)) {
        ptsb_lock_clear();
        return false;
    }

    return true;
}

function ptsb_lock_acquire(string $context = 'generic', int $attempts = 3): ?string {
    $pid   = function_exists('getmypid') ? (int) getmypid() : 0;
    $token = ptsb_lock_new_token();
    $now   = time();
    $data  = [
        'pid'       => $pid,
        'timestamp' => $now,
        'token'     => $token,
        'context'   => $context,
    ];

    for ($i = 0; $i < $attempts; $i++) {
        if (!ptsb_lock_is_locked()) {
            if (add_option(ptsb_lock_option_name(), ptsb_lock_defaults($data), '', 'no')) {
                return $token;
            }

            $current = ptsb_lock_read();
            if (!$current || ptsb_lock_is_expired($current, $now)) {
                ptsb_lock_write($data);
                return $token;
            }
        }

        usleep((int) min(500000, 50000 * ($i + 1))); // backoff exponencial simples
    }

    return null;
}

function ptsb_lock_release(?string $token = null, bool $force = false): void {
    $lock = ptsb_lock_read();
    if (!$lock) {
        return;
    }

    if ($force || $token === null || (string) $lock['token'] === (string) $token) {
        ptsb_lock_clear();
    }
}

function ptsb_lock_release_when_idle(): void {
    $cfg  = ptsb_cfg();
    $file = (string)($cfg['lock'] ?? '');
    if ($file !== '' && @file_exists($file)) {
        return;
    }

    $lock = ptsb_lock_read();
    if (!$lock) {
        return;
    }

    $age = time() - (int) ($lock['timestamp'] ?? 0);
    if ($age < 30 && !$lock['context']) {
        return;
    }

    ptsb_lock_release(null, true);
}

