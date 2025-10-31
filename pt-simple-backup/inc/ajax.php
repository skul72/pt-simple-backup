<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_ptsb_status', function () {
    // no-cache também no AJAX
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ptsb_nonce')) wp_send_json_error('bad nonce', 403);

    $cfg  = ptsb_cfg();
    $tail = ptsb_tail_log_raw($cfg['log'], 50);

    $percent = 0; $stage = 'idle';
    if ($tail) {
        $lines = explode("\n", $tail);
        $start_ix = 0;
        for ($i=count($lines)-1; $i>=0; $i--) {
            if (strpos($lines[$i], '=== Start WP backup') !== false) { $start_ix = $i; break; }
        }
        $section = implode("\n", array_slice($lines, $start_ix));
        $map = [
            'Dumping DB'                          => 15, // compat novo
            'Dumping database'                    => 15, // compat antigo
            'Archiving selected parts'            => 35,
            'Creating final bundle'               => 55,
            'Uploading to'                        => 75,
            'Uploaded and removing local bundle'  => 85,
            'Applying retention'                  => 95,
            'Backup finished successfully.'       => 100,
            'Backup finalizado com sucesso.'      => 100,
        ];
        foreach ($map as $k=>$p) {
            if (strpos($section, $k) !== false) { $percent = max($percent, $p); $stage = $k; }
        }
    }
    $running = ptsb_lock_is_locked() && $percent < 100;
    if (!$running || $percent >= 100) {
        ptsb_lock_release_when_idle();
    }

    wp_send_json_success([
        'running' => (bool)$running,
        'percent' => (int)$percent,
        'stage'   => (string)$stage,
        'log'     => (string)$tail,
    ]);
});

/* -------------------------------------------------------
 * AJAX: detalhes por lote (Rotina, letras e retenção)
 * -----------------------------------------------------*/
add_action('wp_ajax_ptsb_details_batch', function () {
    // segurança + no-cache
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ptsb_nonce')) {
        wp_send_json_error('bad nonce', 403);
    }

    $files = isset($_POST['files']) ? (array)$_POST['files'] : [];
    $out   = [];

    foreach ($files as $f) {
        $f = sanitize_text_field((string)$f);
        // aceita apenas nomes do tipo foo.tar.gz (sem barras)
        if (!preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', $f)) {
            continue;
        }

        $man = ptsb_manifest_read($f); // sidecar JSON (cacheado)

        // Letras: preferir 'letters' do manifest; senão derivar de 'parts'; fallback = todas
        $letters = [];
        if (!empty($man['letters']) && is_array($man['letters'])) {
            $letters = array_values(array_intersect(
                array_map('strtoupper', $man['letters']),
                ['D','P','T','W','S','M','O']
            ));
        } elseif (!empty($man['parts'])) {
            $letters = ptsb_parts_to_letters($man['parts']);
        }
        if (!$letters) { $letters = ['D','P','T','W','S','M','O']; }

        // Retenção (0 = sempre; se ausente, usar padrão do painel)
        $keepDays = ptsb_manifest_keep_days(is_array($man) ? $man : [], (int)ptsb_settings()['keep_days']);

        // Rótulo da rotina (manual / diário / semanal / a cada N / intervalo)
        $routine_label = ptsb_run_kind_label(is_array($man) ? $man : [], $f);

        $out[$f] = [
            'routine_label' => $routine_label,
            'parts_letters' => $letters,
            'keep_days'     => $keepDays,
        ];
    }

    wp_send_json_success($out);
});


add_action('wp_ajax_ptsb_next_list', function () {
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ptsb_nonce')) {
        wp_send_json_error('bad nonce', 403);
    }

    $per  = isset($_POST['per']) ? (int) $_POST['per'] : 20;
    $per  = max(1, min($per, 20));
    $page = max(1, (int) ($_POST['page'] ?? 1));

    $date_raw = isset($_POST['date']) ? preg_replace('/[^0-9\-]/', '', (string) $_POST['date']) : '';
    $date_str = '';
    $dayObj   = null;
    if ($date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_raw)) {
        try {
            $candidate = new DateTimeImmutable($date_raw . ' 00:00:00', ptsb_tz());
            $today0    = ptsb_now_brt()->setTime(0, 0);
            if ($candidate < $today0) {
                $candidate = $today0;
            }
            $dayObj   = $candidate;
            $date_str = $candidate->format('Y-m-d');
        } catch (Throwable $e) {
            $dayObj = null;
        }
    }

    $cycles  = ptsb_cycles_get();
    $skipmap = ptsb_skipmap_get();

    $rows_out    = [];
    $has_next    = false;
    $total_items = 0;
    $total_pages = 1;

    if ($cycles) {
        if ($dayObj) {
            $all = ptsb_cycles_occurrences_for_date($cycles, $dayObj);
            $total_items = count($all);
            $total_pages = max(1, (int) ceil($total_items / $per));
            if ($page > $total_pages) {
                $page = $total_pages;
            }
            $offset = ($page - 1) * $per;
            $slice  = array_slice($all, $offset, $per);
            $has_next = ($page < $total_pages);
        } else {
            $need  = $per * $page + $per;
            $all   = ptsb_cycles_next_occurrences($cycles, $need);
            $offset = ($page - 1) * $per;
            $slice  = array_slice($all, $offset, $per);
            $has_next = (count($all) > ($offset + count($slice)));
        }

        foreach ($slice as $item) {
            if (empty($item['dt']) || !($item['dt'] instanceof DateTimeImmutable)) {
                continue;
            }
            $dt   = $item['dt'];
            $key  = ptsb_skip_key($dt);
            $rows_out[] = [
                'iso'     => $dt->format(DateTimeInterface::ATOM),
                'display' => ptsb_fmt_local_dt($dt->format(DateTimeInterface::ATOM)),
                'names'   => array_values(array_map('strval', (array) ($item['names'] ?? []))),
                'letters' => array_values(array_map('strtoupper', (array) ($item['letters'] ?? []))),
                'key'     => $key,
                'ignored' => !empty($skipmap[$key]),
            ];
        }
    }

    wp_send_json_success([
        'rows'        => $rows_out,
        'page'        => $page,
        'per'         => $per,
        'date'        => $date_str,
        'has_next'    => (bool) $has_next,
        'total_items' => $dayObj ? $total_items : null,
        'total_pages' => $dayObj ? $total_pages : null,
    ]);
});


add_action('wp_ajax_ptsb_last_list', function () {
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ptsb_nonce')) {
        wp_send_json_error('bad nonce', 403);
    }

    $per  = isset($_POST['per']) ? (int) $_POST['per'] : 20;
    $per  = max(1, min($per, 20));
    $page = max(1, (int) ($_POST['page'] ?? 1));

    $last_exp = !empty($_POST['last_exp']) ? 1 : 0;
    $last_ok  = !empty($_POST['last_ok'])  ? 1 : 0;

    $rows     = ptsb_list_remote_files();
    $keepers  = ptsb_keep_map();
    $settings = ptsb_settings();

    $filtered = [];
    foreach ($rows as $r) {
        $file = (string) ($r['file'] ?? '');
        if ($file === '') {
            continue;
        }

        $time     = (string) ($r['time'] ?? '');
        $size     = (int) ($r['size'] ?? 0);
        $is_kept  = !empty($keepers[$file]);
        $is_expired = false;

        if (!($last_exp && $last_ok)) {
            if (!$last_exp && !$last_ok) {
                continue; // nenhum filtro marcado => vazio
            }

            if (!$is_kept) {
                $manifest = ptsb_manifest_read($file);
                $keepDays = ptsb_manifest_keep_days($manifest, (int) ($settings['keep_days'] ?? 0));
                if (is_int($keepDays) && $keepDays > 0) {
                    $ri = ptsb_retention_calc($time, $keepDays);
                    $is_expired = ($ri['x'] >= $ri['y']);
                }
            }

            if ($is_expired && !$last_exp) {
                continue;
            }
            if (!$is_expired && !$last_ok) {
                continue;
            }
        }

        $filtered[] = [
            'time'     => $time,
            'file'     => $file,
            'size'     => $size,
            'kept'     => $is_kept,
        ];
    }

    $total       = count($filtered);
    $total_pages = max(1, (int) ceil($total / $per));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset   = ($page - 1) * $per;
    $rows_page = array_slice($filtered, $offset, $per);

    $out_rows = [];
    foreach ($rows_page as $row) {
        $iso = (string) $row['time'];
        $out_rows[] = [
            'file'    => $row['file'],
            'iso'     => $iso,
            'display' => ptsb_fmt_local_dt($iso),
            'size_h'  => ptsb_hsize((int) $row['size']),
            'kept'    => !empty($row['kept']),
        ];
    }

    wp_send_json_success([
        'rows'      => $out_rows,
        'page'      => $page,
        'per'       => $per,
        'total'     => $total,
        'total_pages' => $total_pages,
        'last_exp'  => $last_exp,
        'last_ok'   => $last_ok,
    ]);
});


