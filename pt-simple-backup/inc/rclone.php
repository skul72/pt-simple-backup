<?php
if (!defined('ABSPATH')) {
    exit;
}

function ptsb_can_shell() {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
}

function ptsb_is_readable($p){ return @is_file($p) && @is_readable($p); }

function ptsb_remote_manifest_ttl(): int {
    $def = 6 * HOUR_IN_SECONDS; // janela padrão de 6h
    $ttl = (int) apply_filters('ptsb_remote_manifest_ttl', $def);
    return max(300, $ttl); // pelo menos 5 minutos
}

function ptsb_remote_manifest_meta_get(): array {
    $meta = get_option('ptsb_remote_manifest_meta', []);
    return is_array($meta) ? $meta : [];
}

function ptsb_remote_manifest_path(bool $ensureDir = false): ?string {
    if (!function_exists('wp_upload_dir')) {
        return null;
    }

    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        return null;
    }

    $dir = rtrim((string) $upload['basedir'], "/\\");
    if ($dir === '') {
        return null;
    }

    $dir .= '/pt-simple-backup';

    if ($ensureDir && !is_dir($dir)) {
        if (function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        } else {
            @mkdir($dir, 0755, true);
        }
    }

    if (!is_dir($dir)) {
        return null;
    }

    return $dir . '/remote-list.json';
}

function ptsb_remote_manifest_read(bool $respectTtl = true): ?array {
    $meta = ptsb_remote_manifest_meta_get();
    if (!$meta) {
        return null;
    }

    if ($respectTtl) {
        $ts = (int) ($meta['generated_at'] ?? 0);
        if ($ts <= 0 || (time() - $ts) > ptsb_remote_manifest_ttl()) {
            return null;
        }
    }

    $path = ptsb_remote_manifest_path(false);
    if ($path === null || !@file_exists($path)) {
        return null;
    }

    $json = @file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }

    if (!empty($meta['hash']) && md5($json) !== (string) $meta['hash']) {
        return null;
    }

    return $data;
}

function ptsb_remote_manifest_save(array $rows): void {
    $path = ptsb_remote_manifest_path(true);
    if ($path === null) {
        return;
    }

    $payload = json_encode(array_values($rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }

    @file_put_contents($path, $payload, LOCK_EX);

    $meta = [
        'generated_at' => time(),
        'hash'         => md5($payload),
    ];

    update_option('ptsb_remote_manifest_meta', $meta, false);
}

function ptsb_remote_manifest_invalidate(): void {
    $path = ptsb_remote_manifest_path(false);
    if ($path !== null && @file_exists($path)) {
        @unlink($path);
    }
    delete_option('ptsb_remote_manifest_meta');
    delete_transient('ptsb_totals_v1');
}

function ptsb_drive_info() {
    $cfg  = ptsb_cfg();
    $info = ['email'=>'', 'used'=>null, 'total'=>null];
    if (!ptsb_can_shell()) return $info;

    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $remote   = $cfg['remote'];
    $rem_name = rtrim($remote, ':');

    $aboutJson = shell_exec($env.' rclone about '.escapeshellarg($remote).' --json 2>/dev/null');
    $j = json_decode((string)$aboutJson, true);
    if (is_array($j)) {
        if (isset($j['used']))  $info['used']  = (int)$j['used'];
        if (isset($j['total'])) $info['total'] = (int)$j['total'];
    } else {
        $txt = (string)shell_exec($env.' rclone about '.escapeshellarg($remote).' 2>/dev/null');
        if (preg_match('/Used:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m))  $info['used']  = ptsb_size_to_bytes($m[1], $m[2]);
        if (preg_match('/Total:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m)) $info['total'] = ptsb_size_to_bytes($m[1], $m[2]);
    }

    // tenta userinfo
    $u = (string)shell_exec($env.' rclone backend userinfo '.escapeshellarg($remote).' 2>/dev/null');
    if (trim($u) === '') {
        $u = (string)shell_exec($env.' rclone config userinfo '.escapeshellarg($rem_name).' 2>/dev/null');
    }
    if ($u !== '') {
        $ju = json_decode($u, true);
        if (is_array($ju)) {
            if (!empty($ju['email']))                     $info['email'] = $ju['email'];
            elseif (!empty($ju['user']['email']))         $info['email'] = $ju['user']['email'];
            elseif (!empty($ju['user']['emailAddress']))  $info['email'] = $ju['user']['emailAddress'];
        } else {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $u, $m)) $info['email'] = $m[0];
        }
    }
    return $info;
}

function ptsb_backups_totals_cached(): array {
    $key = 'ptsb_totals_v1';
    $cached = get_transient($key);
    if (is_array($cached) && isset($cached['count'], $cached['bytes'])) {
        return $cached;
    }
    $rows = ptsb_list_remote_files(); // usa manifest local (invalida quando necessário)
    $count = count($rows);
    $bytes = 0;
    foreach ($rows as $r) { $bytes += (int)($r['size'] ?? 0); }
    $out = ['count'=>$count, 'bytes'=>$bytes];
    set_transient($key, $out, 10 * MINUTE_IN_SECONDS); // 10 min
    return $out;
}

function ptsb_list_remote_files(bool $forceRefresh = false) {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) { return []; }

    if (!$forceRefresh) {
        $cached = ptsb_remote_manifest_read(true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' rclone lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "tsp" --separator ";" --time-format RFC3339 '
         . ' --include ' . escapeshellarg('*.tar.gz') . ' --fast-list';
    $out = shell_exec($cmd);
    $rows = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $ln) {
        $parts = explode(';', $ln, 3);
        if (count($parts) === 3) $rows[] = ['time'=>$parts[0], 'size'=>$parts[1], 'file'=>$parts[2]];
    }
    usort($rows, fn($a,$b) => strcmp($b['time'], $a['time']));

    if ($rows) {
        ptsb_remote_manifest_save($rows);
        return $rows;
    }

    // fallback: se falhar mas existir manifest expirado, reutiliza
    $fallback = ptsb_remote_manifest_read(false);
    if (is_array($fallback)) {
        return $fallback;
    }

    return $rows;
}

function ptsb_keep_map() {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) return [];
    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' rclone lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "p" --separator ";" '
         . ' --include ' . escapeshellarg('*.tar.gz.keep') . ' --fast-list';
    $out = shell_exec($cmd);
    $map = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $p) {
        $base = preg_replace('/\.keep$/', '', $p);
        if ($base) $map[$base] = true;
    }
    return $map;
}

function ptsb_apply_keep_sidecar($file){
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $file==='') return false;
    $touch = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
           . ' rclone touch ' . escapeshellarg($cfg['remote'].$file.'.keep') . ' --no-create-dirs';
    $rcat  = 'printf "" | /usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
           . ' rclone rcat ' . escapeshellarg($cfg['remote'].$file.'.keep');
    shell_exec($touch . ' || ' . $rcat);
    return true;
}

function ptsb_tail_log_raw($path, $n = 50) {
    if (!@file_exists($path)) return "Log nao encontrado em: $path";
    if (ptsb_can_shell()) {
        $txt = shell_exec('tail -n '.intval($n).' '.escapeshellarg($path));
        if ($txt !== null && $txt !== false && $txt !== '') return ptsb_to_utf8((string)$txt);
    }
    $f = @fopen($path, 'rb');
    if (!$f) return "Sem acesso de leitura ao log: $path";
    $lines = []; $buffer = '';
    fseek($f, 0, SEEK_END);
    $filesize = ftell($f);
    $chunk = 4096;
    while ($filesize > 0 && count($lines) <= $n) {
        $seek = max($filesize - $chunk, 0);
        $read = $filesize - $seek;
        fseek($f, $seek);
        $buffer = fread($f, $read) . $buffer;
        $filesize = $seek;
        $lines = explode("\n", $buffer);
    }
    fclose($f);
    $lines = array_slice($lines, -$n);
    return ptsb_to_utf8(implode("\n", $lines));
}

