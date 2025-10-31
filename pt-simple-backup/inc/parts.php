<?php
if (!defined('ABSPATH')) {
    exit;
}

function ptsb_manifest_read(string $tarFile): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) return [];

    $key       = 'ptsb_m_' . md5($tarFile);
    $skipCache = defined('PTSB_SKIP_MANIFEST_CACHE') && PTSB_SKIP_MANIFEST_CACHE;

    if (!$skipCache) {
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
    }

   $jsonPath = ptsb_tar_to_json($tarFile);
    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $out      = shell_exec($env.' rclone cat '.escapeshellarg($cfg['remote'].$jsonPath).' 2>/dev/null');

    $data = json_decode((string)$out, true);
    if (!is_array($data)) $data = [];

    // Só grava transient se não estivermos no admin dessa página
    if (!$skipCache) {
        // TTL menor (5 min) para evitar “grudar” tanto mesmo fora do admin
        set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
    }
    return $data;
}

function ptsb_manifest_write(string $tarFile, array $add, bool $merge=true): bool {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $tarFile === '') return false;

 $jsonPath = ptsb_tar_to_json($tarFile);
    $cur = $merge ? ptsb_manifest_read($tarFile) : [];
    if (!is_array($cur)) $cur = [];

    $data    = array_merge($cur, $add);
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

    $tmp = @tempnam(sys_get_temp_dir(), 'ptsb');
    if ($tmp === false) return false;
    @file_put_contents($tmp, $payload);

    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' cat ' . escapeshellarg($tmp)
         . ' | rclone rcat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null';
    shell_exec($cmd);
    @unlink($tmp);

    // <<< NOVO: invalida o cache do manifest deste arquivo
    delete_transient('ptsb_m_' . md5($tarFile));

    return true;
}

function ptsb_parts_to_labels($partsStr): array {
    $map = [
        'db'      => 'Banco',
        'plugins' => 'Plugins',
        'themes'  => 'Temas',
        'uploads' => 'Mídia',
        'langs'   => 'Traduções',
        'config'  => 'Config',
        'core'    => 'Core',
        'scripts' => 'Scripts',
        'others'  => 'Outros',
    ];
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', strtolower((string)$partsStr)))) as $p) {
        if (isset($map[$p])) $out[] = $map[$p];
    }
    return $out;
}

function ptsb_letters_from_parts($partsStr): array {
    $p = array_filter(array_map('trim', explode(',', strtolower((string)$partsStr))));
    return [
        'p' => in_array('plugins',$p,true),
        't' => in_array('themes',$p,true),
        'w' => in_array('core',$p,true), // só core
        's' => in_array('scripts',$p,true),
        'm' => in_array('uploads',$p,true),
        'o' => in_array('others',$p,true) || in_array('langs',$p,true) || in_array('config',$p,true),
        'd' => in_array('db',$p,true),
    ];
}

function ptsb_ui_default_codes(){ 
    // Agora DB é um chip (default marcado)
    $def = ['d','p','t','w','s','m','o'];
    return apply_filters('ptsb_default_ui_codes', $def);
}

function ptsb_map_ui_codes_to_parts(array $codes): array {
    $codes = array_unique(array_map('strtolower', $codes));
    $parts = [];
    if (in_array('d',$codes,true)) $parts[] = 'db';
    if (in_array('p',$codes,true)) $parts[] = 'plugins';
    if (in_array('t',$codes,true)) $parts[] = 'themes';
    if (in_array('m',$codes,true)) $parts[] = 'uploads';
    if (in_array('w',$codes,true)) $parts[] = 'core';             // só core
    if (in_array('s',$codes,true)) $parts[] = 'scripts';
    if (in_array('o',$codes,true)) { $parts[]='others'; $parts[]='config'; $parts[]='langs'; }
    $parts = array_values(array_unique($parts));
    return apply_filters('ptsb_map_ui_codes_to_parts', $parts, $codes);
}

function ptsb_letters_to_parts_csv(array $letters): string {
    // Nada "sempre incluso": DB virou chip; langs+config agora vão junto de "Outros"
    $parts = [];
    foreach ($letters as $L) {
        switch (strtoupper(trim($L))) {
            case 'D': $parts[] = 'db'; break;
            case 'P': $parts[] = 'plugins'; break;
            case 'T': $parts[] = 'themes'; break;
            case 'W': $parts[] = 'core'; break;
            case 'S': $parts[] = 'scripts'; break;
            case 'M': $parts[] = 'uploads'; break;
            case 'O': $parts[] = 'others'; $parts[] = 'langs'; $parts[] = 'config'; break;
        }
    }
    return implode(',', array_values(array_unique($parts)));
}

function ptsb_parts_to_letters($partsStr): array {
    // Agora DB é opcional (D) e "Outros" agrega langs+config
    $letters = [];
    foreach (array_filter(array_map('trim', explode(',', strtolower((string)$partsStr)))) as $p) {
        if ($p === 'db')        $letters['D'] = true;
        if ($p === 'plugins')   $letters['P'] = true;
        if ($p === 'themes')    $letters['T'] = true;
        if ($p === 'core')      $letters['W'] = true;
        if ($p === 'scripts')   $letters['S'] = true;
        if ($p === 'uploads')   $letters['M'] = true;
        if (in_array($p, ['others','langs','config'], true)) $letters['O'] = true;
    }
    return array_keys($letters);
}

function ptsb_letter_meta($L){
    switch (strtoupper(trim((string)$L))) {
        case 'D': return ['class'=>'dashicons-database',         'label'=>'Banco'];
        case 'P': return ['class'=>'dashicons-admin-plugins',    'label'=>'Plugins'];
        case 'T': return ['class'=>'dashicons-admin-appearance', 'label'=>'Temas'];
        case 'W': return ['class'=>'dashicons-wordpress-alt',    'label'=>'Core'];
        case 'S': return ['class'=>'dashicons-editor-code',      'label'=>'Scripts'];
        case 'M': return ['class'=>'dashicons-admin-media',      'label'=>'Mídia'];
        case 'O': return ['class'=>'dashicons-image-filter',     'label'=>'Outros'];
        default : return ['class'=>'dashicons-marker',           'label'=>strtoupper($L)];
    }
}

function ptsb_mode_label_br($mode){
    $m = strtolower((string)$mode);
    return $m === 'weekly'   ? 'semanal'
         : ($m === 'every_n' ? 'a cada N dias'
         : ($m === 'interval'? 'intervalo'
         : 'diário'));
}

function ptsb_manifest_keep_days(array $man, ?int $default=null): ?int {
    if (isset($man['keep_days'])) {
        $d = (int)$man['keep_days'];
        if ($d >= 0) return $d;
    }
    // compat: alguns scripts podem ter salvo "keep"
    if (isset($man['keep'])) {
        $d = (int)$man['keep'];
        if ($d >= 0) return $d;
    }
    return $default;
}

function ptsb_retention_calc(string $iso, int $keepDays): array {
    try { $created = new DateTimeImmutable($iso); }
    catch (Throwable $e) { $created = ptsb_now_brt(); }
    $now      = ptsb_now_brt();
    $diffSec  = max(0, $now->getTimestamp() - $created->getTimestamp());
    $elapsed  = (int) floor($diffSec / 86400);
    $x        = min($keepDays, $elapsed + 1); // inclusivo: no dia do backup = 1
    $pct      = (int) round(($x / max(1,$keepDays)) * 100);
    return ['x'=>$x,'y'=>$keepDays,'pct'=>$pct];
}

function ptsb_start_backup_with_parts(string $partsCsv): void {
    if (!ptsb_can_shell()) return;
    if (!ptsb_lock_acquire('parts_trigger')) {
        return;
    }

    ptsb_start_backup($partsCsv);
}

function ptsb_guess_cycle_mode_from_filename(string $file): ?string {
    $cycles = ptsb_cycles_get(); if (!$cycles) return null;
    $cfg = ptsb_cfg(); $bestLen = 0; $bestMode = null;
    foreach ($cycles as $c) {
        $slug = ptsb_slug_prefix((string)($c['name'] ?? '')); if ($slug === '') continue;
        foreach ([$cfg['prefix'].$slug, $slug] as $cand) {
            if ($cand !== '' && strpos($file, $cand) === 0) {
                $L = strlen($cand);
                if ($L > $bestLen) { $bestLen = $L; $bestMode = (string)($c['mode'] ?? 'daily'); }
            }
        }
    }
    return $bestMode;
}

function ptsb_run_kind_label(array $manifest, string $file): string {
    $origin = strtolower((string)($manifest['origin'] ?? ''));
    $mmode  = strtolower((string)($manifest['routine_mode'] ?? ($manifest['mode'] ?? $manifest['auto_mode'] ?? '')));

    if ($origin === 'manual') return 'manual';
    if (in_array($mmode, ['daily','weekly','every_n','interval'], true)) {
        return ptsb_mode_label_br($mmode);
    }
    $gm = ptsb_guess_cycle_mode_from_filename($file);
    if ($gm) return ptsb_mode_label_br($gm);
    return 'manual';
}

