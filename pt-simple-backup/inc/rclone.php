<?php
if (!defined('ABSPATH')) {
    exit;
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

function ptsb_plan_mark_keep_next($prefix){
    $prefix = (string)$prefix;
    if ($prefix === '') $prefix = ptsb_cfg()['prefix'];
    update_option('ptsb_mark_keep_plan', ['prefix'=>$prefix, 'set_at'=>time()], true);
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

function ptsb_list_remote_files() {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) { return []; }
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
