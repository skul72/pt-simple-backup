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
        'log_max_mb' => 3, // tamanho máx. do log
'log_keep'   => 5, // quantos arquivos rotacionados manter

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

function ptsb_mask_email($email, $keep = 7) {
    $email = trim((string)$email);
    if ($email === '' || strpos($email, '@') === false) return $email;
    [$left, $domain] = explode('@', $email, 2);
    $keep = max(1, min((int)$keep, strlen($left)));
    return substr($left, 0, $keep) . '...@' . $domain;
}

