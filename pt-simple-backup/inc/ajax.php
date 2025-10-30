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
    $running = file_exists($cfg['lock']) && $percent < 100;

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
