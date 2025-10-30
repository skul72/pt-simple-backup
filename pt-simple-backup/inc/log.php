<?php
if (!defined('ABSPATH')) {
    exit;
}

function ptsb_log($msg) {
  $cfg = ptsb_cfg();
  ptsb_log_rotate_if_needed(); // NOVO
  $line = '['.ptsb_now_brt()->format('d-m-Y-H:i').'] '.strip_tags($msg)."\n";
  @file_put_contents($cfg['log'], $line, FILE_APPEND);
}

function ptsb_log_rotate_if_needed(): void {
    $cfg   = ptsb_cfg();
    $log   = (string)($cfg['log'] ?? '');
    $keep  = max(1, (int)($cfg['log_keep']    ?? 5));
    $maxMb = (float)  ($cfg['log_max_mb']     ?? 3);
    $limit = max(1, (int)round($maxMb * 1048576)); // MB -> bytes

    if ($log === '' || !@file_exists($log)) return;

    @clearstatcache(true, $log);
    $size = @filesize($log);
    if ($size === false || $size < $limit) return;

    // 1) abre espaço para o novo .1 (shift .1->.2, .2->.3, ..., até .keep)
    for ($i = $keep; $i >= 1; $i--) {
        $from = $log . '.' . $i;
        $to   = $log . '.' . ($i + 1);
        if (@file_exists($to))  @unlink($to);
        if (@file_exists($from)) @rename($from, $to);
    }

    // 2) estratégia conforme lock
    $running = @file_exists((string)$cfg['lock']);
    if ($running) {
        // copytruncate
        @copy($log, $log . '.1');
        if ($fp = @fopen($log, 'c')) { @ftruncate($fp, 0); @fclose($fp); }
    } else {
        // rename + arquivo novo
        @rename($log, $log . '.1');
        if (!@file_exists($log)) { @file_put_contents($log, ""); }
    }

    // 3) se sobrou .(keep+1), remove
    $overflow = $log . '.' . ($keep + 1);
    if (@file_exists($overflow)) @unlink($overflow);
}

function ptsb_log_clear_all(): void {
    $cfg  = ptsb_cfg();
    $log  = (string)($cfg['log'] ?? '');
    $keep = max(1, (int)($cfg['log_keep'] ?? 5));
    if ($log === '') return;

    $running = @file_exists((string)$cfg['lock']);

    // apaga rotações conhecidas (varremos um pouco além por segurança)
    for ($i = 1; $i <= ($keep + 5); $i++) {
        $p = $log . '.' . $i;
        if (@file_exists($p)) @unlink($p);
    }

    if ($running) {
        // não remove o atual: só zera
        if ($fp = @fopen($log, 'c')) { @ftruncate($fp, 0); @fclose($fp); }
    } else {
        // remove e recria vazio (para o tail AJAX continuar bem)
        @unlink($log);
        @file_put_contents($log, "");
    }
}

function ptsb_log_has_success_marker() {
    $cfg  = ptsb_cfg();
    $tail = (string) ptsb_tail_log_raw($cfg['log'], 800);

    if ($tail === '') {
        // evita flood: não loga toda hora
        if (!get_transient('ptsb_notify_rl_tail_empty')) {
            set_transient('ptsb_notify_rl_tail_empty', 1, 60);
            ptsb_log('[notify] tail vazio — permitindo notificação.');
        }
        return true;
    }

    $patterns = [
        '/Backup finished successfully\.?/i',
        '/Backup finalizado com sucesso\.?/i',
        '/Uploaded and removing local bundle/i',
        '/Upload(?:ed)?\s+completed/i',
        '/All done/i',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $tail)) return true;
    }

    // sem marcador: loga no máx 1x/min
    if (!get_transient('ptsb_notify_rl_no_marker')) {
        set_transient('ptsb_notify_rl_no_marker', 1, 60);
        ptsb_log('[notify] sem marcador de sucesso nas últimas linhas — aguardando.');
    }
    return false;
}

function ptsb_maybe_notify_backup_done() {
    $cfg = ptsb_cfg();

    // === THROTTLE: roda no máx 1x a cada 15s (evita flood via admin_init/AJAX) ===
    $th_key = 'ptsb_notify_throttle_15s';
    $now_ts = time();
    $last   = (int) get_transient($th_key);
    if ($last && ($now_ts - $last) < 15) {
        return;
    }
    set_transient($th_key, $now_ts, 15);

    // se ainda está rodando, não notifica (loga no máx 1x/min)
    if (file_exists($cfg['lock'])) {
        if (!get_transient('ptsb_notify_lock_log_rl')) {
            set_transient('ptsb_notify_lock_log_rl', 1, 60);
            ptsb_log('[notify] pulando: lock presente (backup rodando).');
        }
        return;
    }

    // pega o último arquivo do Drive
    $rows = ptsb_list_remote_files();
    if (!$rows) return;
    $latest    = $rows[0];
    $last_sent = (string) get_option('ptsb_last_notified_backup_file', '');

    // evita duplicar notificação
    if ($latest['file'] === $last_sent) return;

    // espera até 10min pelo marcador explícito de sucesso no log
    $ok = ptsb_log_has_success_marker();
    if (!$ok) {
        try { $finished = new DateTimeImmutable($latest['time']); } catch (Throwable $e) { $finished = null; }
        $margem = $finished ? (ptsb_now_brt()->getTimestamp() - $finished->getTimestamp()) : 0;
        if ($finished && $margem < 600) {
            if (!get_transient('ptsb_notify_wait_marker_rl')) {
                set_transient('ptsb_notify_wait_marker_rl', 1, 60);
                ptsb_log('[notify] aguardando marcador (até 10min) para '.$latest['file']);
            }
            return;
        }
        if (!get_transient('ptsb_notify_no_marker_rl2')) {
            set_transient('ptsb_notify_no_marker_rl2', 1, 60);
            ptsb_log('[notify] seguindo sem marcador explícito para '.$latest['file']);
        }
    }

    // === LOCK anti-duplicidade (apenas 1 request envia) ===
    $lock_opt = 'ptsb_notify_lock';
    $got_lock = add_option($lock_opt, (string)$latest['file'], '', 'no'); // true se criou
    if (!$got_lock) {
        // se alguém já está processando este MESMO arquivo, sai silencioso
        $cur = (string) get_option($lock_opt, '');
        if ($cur === (string)$latest['file']) {
            return;
        } else {
            // outro arquivo ainda em processamento – não competir
            return;
        }
    }

    try {
        // intenção do último disparo (manual/rotina + retenção)
        $intent         = get_option('ptsb_last_run_intent', []);
        $intent_kdays   = isset($intent['keep_days']) ? (int)$intent['keep_days'] : (int)ptsb_settings()['keep_days'];
        $intent_forever = !empty($intent['keep_forever']) || $intent_kdays === 0;
        $intent_origin  = (string)($intent['origin'] ?? '');

        // manifest existente (se houver)
        $man = ptsb_manifest_read($latest['file']);

        // PARTES (CSV) -> letras + rótulos humanos
        $partsCsv = (string)($man['parts'] ?? get_option('ptsb_last_run_parts', ''));
        if ($partsCsv === '') {
            $partsCsv = apply_filters('ptsb_default_parts', 'db,plugins,themes,uploads,langs,config,scripts');
        }
        $letters = ptsb_parts_to_letters($partsCsv);
        $parts_h = ptsb_parts_to_labels($partsCsv);

        // RETENÇÃO (dias) — 0 = “sempre”
        $keepDaysMan = ptsb_manifest_keep_days(is_array($man) ? $man : [], null);
        $keepDays    = ($keepDaysMan === null) ? ($intent_forever ? 0 : max(1, (int)$intent_kdays)) : (int)$keepDaysMan;

        // se for "sempre manter", garante o sidecar .keep
        $keepers = ptsb_keep_map();
        if ($keepDays === 0 && empty($keepers[$latest['file']])) {
            ptsb_apply_keep_sidecar($latest['file']);
        }

        // rótulos de retenção
        $ret_label = ($keepDays === 0) ? 'sempre' : sprintf('%d dia%s', $keepDays, $keepDays > 1 ? 's' : '');
        $ret_prog  = null;
        if ($keepDays > 0) {
            $ri       = ptsb_retention_calc((string)$latest['time'], $keepDays);
            $ret_prog = $ri['x'].'/'.$ri['y'];
        }

        // tenta inferir modo da rotina pelo nome do arquivo
        $routine_mode = (string)(ptsb_guess_cycle_mode_from_filename($latest['file']) ?? '');

        // sincroniza manifest com dados úteis
        $manAdd = [
            'keep_days'    => $keepDays,
            'origin'       => ($intent_origin ?: 'manual'),
            'parts'        => $partsCsv,
            'letters'      => $letters,
            'routine_mode' => $routine_mode,
        ];
        ptsb_manifest_write($latest['file'], $manAdd, true);

        // payload da notificação
        $payload = [
            'file'               => (string)$latest['file'],
            'size'               => (int)$latest['size'],
            'size_h'             => ptsb_hsize((int)$latest['size']),
            'finished_at_iso'    => (string)$latest['time'],
            'finished_at_local'  => ptsb_fmt_local_dt((string)$latest['time']),
            'drive_url'          => (string)$cfg['drive_url'],
            'parts_csv'          => $partsCsv,
            'parts_h'            => $parts_h,
            'letters'            => $letters,
            'keep_days'          => $keepDays,
            'retention_label'    => $ret_label,
            'retention_prog'     => $ret_prog,
            'origin'             => ($intent_origin ?: 'manual'),
            'routine_mode'       => $routine_mode,
            'keep_forever'       => ($keepDays === 0 ? 1 : 0),
        ];

        // dispara o evento; outro plugin/integração cuida de enviar e-mails
        do_action('ptsb_backup_done', $payload);

      // === FALLBACK de e-mail (só se NÃO houver OU pt_done OU pt_finished) ===
if (!has_action('ptsb_backup_done') && !has_action('ptsb_backup_finished') && function_exists('wp_mail')) {
    ptsb_notify_send_email_fallback($payload);
}


        // marca como notificado
        update_option('ptsb_last_notified_backup_file', (string)$latest['file'], true);
        update_option('ptsb_last_notified_payload', $payload, true);

        ptsb_log('[notify] evento disparado para '.$latest['file']);
    } finally {
        // libera lock mesmo com erro
        delete_option($lock_opt);
    }
}

function ptsb_notify_send_email_fallback(array $payload) {
    $to = apply_filters('ptsb_notify_email_to', get_option('admin_email'));
    if (!is_email($to)) return;

    $site  = wp_parse_url(home_url(), PHP_URL_HOST);
    $assunto = sprintf('[%s] Backup concluído: %s (%s)',
        $site ?: 'site', (string)$payload['file'], (string)$payload['size_h']
    );

    $linhas = [];
    $linhas[] = 'Backup concluído e enviado ao Drive.';
    $linhas[] = '';
    $linhas[] = 'Arquivo: ' . (string)$payload['file'];
    $linhas[] = 'Tamanho: ' . (string)$payload['size_h'];
    $linhas[] = 'Concluído: ' . (string)$payload['finished_at_local'];
    $linhas[] = 'Backup: ' . implode(', ', (array)$payload['parts_h']);
    $linhas[] = 'Retenção: ' . (string)$payload['retention_label'] . ($payload['retention_prog'] ? ' ('.$payload['retention_prog'].')' : '');
    if (!empty($payload['drive_url'])) {
        $linhas[] = 'Drive: ' . (string)$payload['drive_url'];
    }
    $linhas[] = '';
    $linhas[] = 'Origem: ' . (string)$payload['origin'] . ($payload['routine_mode'] ? ' / modo: '.$payload['routine_mode'] : '');

    $body = implode("\n", $linhas);

    // texto simples
    @wp_mail($to, $assunto, $body);
}

add_action('admin_init', 'ptsb_maybe_notify_backup_done');
add_action('ptsb_cron_tick', 'ptsb_maybe_notify_backup_done');
