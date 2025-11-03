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

if (!function_exists('ptsb_job_detect_bin')) {
    function ptsb_job_detect_bin(string $bin, string $envPath): ?string {
        static $cache = [];

        $bin = trim($bin);
        $envPath = trim($envPath);
        if ($bin === '') {
            return null;
        }

        $key = $envPath . '|' . $bin;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if (!function_exists('shell_exec')) {
            $cache[$key] = null;
            return null;
        }

        $prefix = $envPath !== '' ? $envPath . ' ' : '';
        $out = shell_exec($prefix . 'command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        $path = trim((string) $out);
        $cache[$key] = $path !== '' ? $path : null;

        return $cache[$key];
    }
}

if (!function_exists('ptsb_job_find_binary')) {
    function ptsb_job_find_binary($candidate, string $fallback, string $envPath): ?string {
        $candidate = is_string($candidate) ? trim($candidate) : '';
        if ($candidate !== '') {
            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false) {
                if (@is_executable($candidate)) {
                    return $candidate;
                }
            } else {
                $found = ptsb_job_detect_bin($candidate, $envPath);
                if ($found) {
                    return $found;
                }
            }
        }

        return ptsb_job_detect_bin($fallback, $envPath);
    }
}

if (!function_exists('ptsb_job_normalize_ionice_class')) {
    function ptsb_job_normalize_ionice_class($value): ?int {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        switch ($value) {
            case 'idle':
                return 3;
            case 'besteffort':
            case 'best-effort':
            case 'be':
                return 2;
            case 'realtime':
            case 'rt':
                return 1;
        }

        return null;
    }
}

if (!function_exists('ptsb_job_resource_constraints')) {
    function ptsb_job_resource_constraints(array $cfg, string $context, string $envPath): array {
        $limits = [];
        if (isset($cfg['job_limits']) && is_array($cfg['job_limits'])) {
            $limits = $cfg['job_limits'];
        }

        if (function_exists('apply_filters')) {
            $limits = apply_filters('ptsb_job_limits', $limits, $context, $cfg);
        }

        $wrappers = [];
        $envVars  = [];
        $applied  = [];

        if ($context !== '') {
            $envVars['PTS_JOB_CONTEXT'] = $context;
        }

        if (!function_exists('shell_exec') || !ptsb_can_shell()) {
            return ['wrappers' => [], 'env' => $envVars];
        }

        $envPath = trim($envPath);
        if ($envPath === '') {
            $envPath = 'PATH=/usr/local/bin:/usr/bin:/bin';
        }

        // CPU limit (cpulimit)
        $cpuLimit = null;
        foreach (['cpu_percent', 'cpu_limit', 'cpu_quota'] as $cpuKey) {
            if (isset($limits[$cpuKey])) {
                $cpuLimit = $limits[$cpuKey];
                break;
            }
        }

        if (is_string($cpuLimit) && preg_match('/^(off|none|disable)$/i', $cpuLimit)) {
            $cpuLimit = null;
        }

        if ($cpuLimit !== null && $cpuLimit !== false) {
            if (is_numeric($cpuLimit)) {
                $cpuLimit = (int) $cpuLimit;
            } else {
                $cpuLimit = (int) preg_replace('/[^0-9]+/', '', (string) $cpuLimit);
            }

            if ($cpuLimit > 0) {
                $cpuLimit = max(1, min(100, $cpuLimit));
                $cpuBin  = $limits['cpulimit_bin'] ?? ($limits['cpu_bin'] ?? 'cpulimit');
                $cpuPath = ptsb_job_find_binary($cpuBin, 'cpulimit', $envPath);
                if ($cpuPath) {
                    array_unshift($wrappers, escapeshellarg($cpuPath) . ' -l ' . $cpuLimit . ' --');
                    $envVars['PTS_CPU_LIMIT'] = $cpuLimit;
                    $applied[] = 'cpulimit';
                }
            }
        }

        // ionice (IO priority/limits)
        $ioniceCfg = $limits['ionice'] ?? null;
        if (is_string($ioniceCfg) && preg_match('/^(off|none|disable)$/i', $ioniceCfg)) {
            $ioniceCfg = null;
        }

        if ($ioniceCfg !== null && $ioniceCfg !== false) {
            $ionicePath = ptsb_job_find_binary($limits['ionice_bin'] ?? 'ionice', 'ionice', $envPath);
            if ($ionicePath) {
                $class    = 2;
                $priority = 7;

                if (is_array($ioniceCfg)) {
                    $candidate = null;
                    if (isset($ioniceCfg['class'])) {
                        $candidate = $ioniceCfg['class'];
                    } elseif (isset($ioniceCfg['mode'])) {
                        $candidate = $ioniceCfg['mode'];
                    }

                    $maybeClass = ptsb_job_normalize_ionice_class($candidate);
                    if ($maybeClass !== null) {
                        $class = $maybeClass;
                    }

                    if (isset($ioniceCfg['priority'])) {
                        $priority = (int) $ioniceCfg['priority'];
                    } elseif (isset($ioniceCfg['level'])) {
                        $priority = (int) $ioniceCfg['level'];
                    } elseif (isset($ioniceCfg['prio'])) {
                        $priority = (int) $ioniceCfg['prio'];
                    }
                } elseif (is_string($ioniceCfg)) {
                    if (strpos($ioniceCfg, ':') !== false) {
                        [$classPart, $prioPart] = array_map('trim', explode(':', $ioniceCfg, 2));
                        $maybeClass = ptsb_job_normalize_ionice_class($classPart);
                        if ($maybeClass !== null) {
                            $class = $maybeClass;
                        }
                        if ($prioPart !== '' && is_numeric($prioPart)) {
                            $priority = (int) $prioPart;
                        }
                    } else {
                        $maybeClass = ptsb_job_normalize_ionice_class($ioniceCfg);
                        if ($maybeClass !== null) {
                            $class = $maybeClass;
                        }
                    }
                } elseif (is_int($ioniceCfg) || is_numeric($ioniceCfg)) {
                    $class = (int) $ioniceCfg;
                }

                $class = max(1, min(3, (int) $class));
                $priority = max(0, min(7, (int) $priority));

                if ($class === 3) {
                    $wrappers[] = escapeshellarg($ionicePath) . ' -c 3';
                } else {
                    $wrappers[] = escapeshellarg($ionicePath) . ' -c ' . $class . ' -n ' . $priority;
                    $envVars['PTS_IONICE_PRIORITY'] = $priority;
                }

                $envVars['PTS_IONICE_CLASS'] = $class;
                $applied[] = 'ionice';
            }
        }

        // nice (CPU priority)
        $niceCfg = $limits['nice'] ?? null;
        if (is_string($niceCfg) && preg_match('/^(off|none|disable)$/i', $niceCfg)) {
            $niceCfg = null;
        }

        if ($niceCfg !== null && $niceCfg !== false) {
            if (is_numeric($niceCfg)) {
                $niceLevel = (int) $niceCfg;
            } else {
                $niceLevel = (int) preg_replace('/[^0-9\-]+/', '', (string) $niceCfg);
            }

            $niceLevel = max(-20, min(19, $niceLevel));
            $nicePath  = ptsb_job_find_binary($limits['nice_bin'] ?? 'nice', 'nice', $envPath);
            if ($nicePath) {
                $wrappers[] = escapeshellarg($nicePath) . ' -n ' . $niceLevel;
                $envVars['PTS_NICE_LEVEL'] = $niceLevel;
                $applied[] = 'nice';
            }
        }

        if ($applied) {
            $envVars['PTS_JOB_LIMITS'] = implode(',', $applied);
            $envVars['PTS_JOB_LIMITS_VERSION'] = '1';
        }

        return ['wrappers' => $wrappers, 'env' => $envVars];
    }
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

