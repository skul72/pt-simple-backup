<?php
if (!defined('ABSPATH')) {
    exit;
}

function ptsb_start_backup($partsCsv = null, $overridePrefix = null, $overrideDays = null){
    $cfg = ptsb_cfg();
    $set = ptsb_settings();
    if (!ptsb_can_shell()) return;
    if (file_exists($cfg['lock'])) { return; }

    ptsb_log_rotate_if_needed();

    // 1) tenta última seleção (letras D,P,T,W,S,M,O)
    if ($partsCsv === null) {
        $last = get_option('ptsb_last_parts_ui', implode(',', ptsb_ui_default_codes()));
        $letters = array_values(array_intersect(
            array_map('strtoupper', array_filter(array_map('trim', explode(',', (string)$last)))) ,
            ['D','P','T','W','S','M','O']
        ));
        if (!$letters) { $letters = array_map('strtoupper', ptsb_ui_default_codes()); }
        if (function_exists('ptsb_letters_to_parts_csv')) {
            $partsCsv = ptsb_letters_to_parts_csv($letters);
        } else {
            $partsCsv = implode(',', ptsb_map_ui_codes_to_parts(array_map('strtolower', $letters)));
        }
    }

    // 2) fallback final personalizável
    if (!$partsCsv) {
        $partsCsv = apply_filters('ptsb_default_parts', 'db,plugins,themes,uploads,langs,config,scripts');
    }

    $prefix = ($overridePrefix !== null && $overridePrefix !== '') ? $overridePrefix : $cfg['prefix'];

    // >>> ALTERAÇÃO: permitir 0 (sentinela "sempre manter")
    if ($overrideDays !== null) {
        $keepDays = max(0, (int)$overrideDays);   // 0 = sempre manter; >0 = dias; null = usa padrão
    } else {
        $keepDays = (int)$set['keep_days'];
    }

    $env = 'PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . 'REMOTE='           . escapeshellarg($cfg['remote'])     . ' '
         . 'WP_PATH='          . escapeshellarg(ABSPATH)            . ' '
         . 'PREFIX='           . escapeshellarg($prefix)            . ' '
         . 'KEEP_DAYS='        . escapeshellarg($keepDays)          . ' '
         . 'KEEP='             . escapeshellarg($keepDays)          . ' '
         . 'RETENTION_DAYS='   . escapeshellarg($keepDays)          . ' '
         . 'RETENTION='        . escapeshellarg($keepDays)          . ' '
         . 'KEEP_FOREVER='     . escapeshellarg($keepDays === 0 ? 1 : 0) . ' ' // opcional p/ scripts que queiram esse flag
         . 'PARTS='            . escapeshellarg($partsCsv);

    // guarda as partes usadas neste disparo (fallback para a notificação)
    update_option('ptsb_last_run_parts', (string)$partsCsv, true);

    $cmd = '/usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_backup'])
         . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';

    shell_exec($cmd);
}

function ptsb_back() {
    $tab = 'backup';
    $args = ['page'=>'pt-simple-backup', '_ts'=>time()];
    $ref = wp_get_referer();
    if ($ref) {
        $q = [];
        parse_str(parse_url($ref, PHP_URL_QUERY) ?? '', $q);
        if (!empty($q['tab']) && in_array($q['tab'], ['backup','cycles','next','last','settings'], true)) {
            $tab = $q['tab'];
        }
        if (!empty($q['per']))   { $args['per']   = max(1, (int)$q['per']); }
        if (!empty($q['paged'])) { $args['paged'] = max(1, (int)$q['paged']); }

        // >>> NOVO: mantém filtros/pg da aba "next"
if (!empty($q['per_next']))   { $args['per_next']   = max(1, (int)$q['per_next']); }
if (!empty($q['page_next']))  { $args['page_next']  = max(1, (int)$q['page_next']); }
if (!empty($q['next_date']))  { $args['next_date']  = preg_replace('/[^0-9\-]/','', (string)$q['next_date']); }

// >>> NOVO: mantém filtros/pg da aba "last"
if (!empty($q['per_last']))   { $args['per_last']   = max(1, (int)$q['per_last']); }
if (!empty($q['page_last']))  { $args['page_last']  = max(1, (int)$q['page_last']); }

// >>> NOVO: mantém filtros de vencidos
if (isset($q['last_exp'])) { $args['last_exp'] = (int)!!$q['last_exp']; }
if (isset($q['last_ok']))  { $args['last_ok']  = (int)!!$q['last_ok']; }


    }
    $args['tab'] = $tab;
    wp_safe_redirect( add_query_arg($args, admin_url('tools.php')) );
    exit;
}

add_action('admin_post_ptsb_do', function () {
    if (!current_user_can('manage_options')) { wp_die('forbidden'); }
    check_admin_referer('ptsb_nonce');

    $cfg = ptsb_cfg();
    $set = ptsb_settings();
    $act = isset($_POST['ptsb_action']) ? sanitize_text_field($_POST['ptsb_action']) : '';

        /* ===== Limpar log + rotações ===== */
    if ($act === 'clear_log') {
        ptsb_log_clear_all();
        add_settings_error('ptsb','log_cleared','Log limpo (incluindo rotações).','updated');
        ptsb_back();
    }


   /* ===== Disparar manual (topo) — lendo as letras D,P,T,W,S,M,O ===== */
if ($act === 'backup_now') {
    if (!ptsb_can_shell()) { add_settings_error('ptsb', 'noshell', 'shell_exec desabilitado no PHP.', 'error'); ptsb_back(); }
    if (file_exists($cfg['lock'])) {
        add_settings_error('ptsb', 'bk_running', 'J&aacute; existe um backup em execu&ccedil;&atilde;o. Aguarde concluir antes de iniciar outro.', 'error');
        ptsb_back();
    }

    // 1) Preferir as letras vindas dos chips (parts_sel[]). Se não vier, aceitar parts_ui[] (legado).
    $letters = [];
    if (isset($_POST['parts_sel']) && is_array($_POST['parts_sel'])) {
        $letters = array_values(array_unique(array_map('strtoupper', array_map('strval', $_POST['parts_sel']))));
    } elseif (isset($_POST['parts_ui']) && is_array($_POST['parts_ui'])) {
        $letters = array_values(array_unique(array_map('strtoupper', array_map('strval', $_POST['parts_ui']))));
    } else {
        $letters = array_map('strtoupper', ptsb_ui_default_codes()); // default = tudo ON (inclui D)
    }
    // manter apenas letras válidas
    $letters = array_values(array_intersect($letters, ['D','P','T','W','S','M','O']));
    if (!$letters) { $letters = ['D','P','T','W','S','M','O']; }

    // 2) Lembrar para pré-marcar na UI
    update_option('ptsb_last_parts_ui', implode(',', $letters), true);

    // 3) Montar PARTS= para o script
    if (function_exists('ptsb_letters_to_parts_csv')) {
        $partsCsv = ptsb_letters_to_parts_csv($letters);
    } else {
        // fallback (se PARTE 2 não existir por algum motivo)
        $partsCsv = implode(',', ptsb_map_ui_codes_to_parts(array_map('strtolower', $letters)));
    }

// 4) Nome e retenção (Backup Manual) — obrigatório, salvo se "Sempre manter"
$manual_name  = sanitize_text_field($_POST['manual_name'] ?? '');
$keep_forever = !empty($_POST['manual_keep_forever']);
$manual_days  = null;

if ($keep_forever) {
    // sentinel 0 para "sempre manter" (manifest cuida disso depois)
    $manual_days = 0;
} else {
    $raw = isset($_POST['manual_keep_days']) ? trim((string)$_POST['manual_keep_days']) : '';
    if ($raw === '' || !is_numeric($raw)) {
        add_settings_error('ptsb','mkd_required','Informe a quantidade de dias de retenção (ou marque "Sempre manter").','error');
        ptsb_back();
    }
    $manual_days = max(1, min((int)$raw, 3650));
}

// o usuário digita só o apelido; nós geramos "wpb-<apelido>-"
$nick    = $manual_name !== '' ? ptsb_slug_prefix($manual_name) : '';
$prefix  = $manual_name !== '' ? (ptsb_cfg()['prefix'] . $nick) : null;

$effPrefix = ($prefix !== null && $prefix !== '') ? $prefix : ptsb_cfg()['prefix'];
if (!empty($_POST['manual_keep_forever'])) {
    ptsb_plan_mark_keep_next($effPrefix);
}

update_option('ptsb_last_run_intent', [
    'prefix'       => $effPrefix,
    'keep_days'    => (int)$manual_days,                      // usa exatamente o informado (0 ou >0)
    'keep_forever' => $keep_forever ? 1 : 0,
    'origin'       => 'manual',
    'started_at'   => time(),
], true);

ptsb_start_backup($partsCsv, $prefix, $manual_days);


    // 6) Mensagem
    $human = ptsb_parts_to_labels($partsCsv);
    $txt = 'Backup disparado'.($human ? ' (incluindo: '.esc_html(implode(', ', $human)).')' : '').'. Acompanhe abaixo.';
    add_settings_error('ptsb', 'bk_started', $txt, 'updated');
    ptsb_back();
}


    /* ===== Salvar TUDO (retenção + agenda/mode) ===== */
    if (in_array($act, ['save_all','save_settings','save_auto'], true)) {

        // retenção (apenas por dias)
        $d = isset($_POST['keep_days']) ? (int) $_POST['keep_days'] : $cfg['keep_days_def'];
        $d = max(1, min($d, 3650));
        update_option('ptsb_keep_days', $d, true);
      
        // ======= modo/frequência =======
        $en   = !empty($_POST['auto_enabled']);
        $mode = isset($_POST['auto_mode']) ? sanitize_text_field($_POST['auto_mode']) : 'daily';
        $mode = in_array($mode, ['daily','weekly','every_n'], true) ? $mode : 'daily';

        $qty  = 1; $timesForLegacy = []; $mcfg = [];

        $normTimes = function($arr){
            $out=[];
            foreach ((array)$arr as $s){
                $s = trim((string)$s);
                if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
                    $out[] = sprintf('%02d:%02d', min(23,max(0,(int)$m[1])), min(59,max(0,(int)$m[2])));
                }
            }
            return ptsb_times_sort_unique($out);
        };
        $minGapCheck = function($times) use ($cfg){
            $times = ptsb_times_sort_unique($times);
            if (count($times) <= 1) return;
            $mins = array_map('ptsb_time_to_min', $times);
            sort($mins);
            $mins[] = $mins[0] + 1440;
            $minGap = 1440;
            for($i=0;$i<count($mins)-1;$i++){ $gap = $mins[$i+1]-$mins[$i]; if($gap < $minGap) $minGap=$gap; }
            if ($minGap < (int)$cfg['min_gap_min']) {
                add_settings_error('ptsb','gap_warn','Aviso: intervalos menores que '.$cfg['min_gap_min'].' min entre execu&ccedil;&otilde;es podem sobrecarregar o servidor.','warning');
            }
        };

        if ($mode === 'daily') {
            $qty   = max(1, min((int)($_POST['auto_qty'] ?? 1), $cfg['max_per_day']));
            $times = $normTimes($_POST['auto_times'] ?? []);
            if (count($times) !== $qty) {
                add_settings_error('ptsb','auto_err_qty','Informe exatamente '.$qty.' hor&aacute;rio(s) v&aacute;lido(s) no formato 24h (HH:MM).','error');
                ptsb_back();
            }
            $timesForLegacy = $times;
            $mcfg = ['times'=>$times, 'qty'=>$qty];
            $minGapCheck($times);

        } elseif ($mode === 'weekly') {
            $days  = array_map('intval', (array)($_POST['wk_days'] ?? []));
            $days  = array_values(array_unique(array_filter($days, fn($d)=>$d>=0 && $d<=6)));
            $timeS = trim((string)($_POST['wk_time'] ?? ''));
            $times = $normTimes([$timeS]);
            if (!$days) { add_settings_error('ptsb','auto_err_wk_days','Selecione pelo menos 1 dia da semana.','error'); ptsb_back(); }
            if (!$times){ add_settings_error('ptsb','auto_err_wk_time','Informe 1 hor&aacute;rio (HH:MM).','error'); ptsb_back(); }
            $mcfg = ['days'=>$days, 'times'=>$times];
            $qty  = 1;

        } else { // every_n
            $n      = max(1, min(30, (int)($_POST['ndays_n'] ?? 1)));
            $start  = sanitize_text_field($_POST['ndays_start'] ?? ptsb_now_brt()->format('Y-m-d'));
            $timeS  = trim((string)($_POST['ndays_time'] ?? ''));
            $times  = $normTimes([$timeS]);
            if (!$times) { add_settings_error('ptsb','auto_err_nd_t','Informe 1 hor&aacute;rio (HH:MM).','error'); ptsb_back(); }
            $mcfg   = ['n'=>$n, 'start'=>$start, 'times'=>$times];
            $qty    = 1;
        }

        $state = get_option('ptsb_auto_state', []);
        if (!is_array($state)) $state=[];
        $state += ['last_by_slot'=>[], 'queued_slot'=>'', 'queued_at'=>0];

        ptsb_auto_save($en, $qty, $timesForLegacy, $state, $mode, $mcfg);

        if ($en) {
            if (!wp_next_scheduled($cfg['cron_hook'])) {
                wp_schedule_event(time()+30, $cfg['cron_sched'], $cfg['cron_hook']);
            }
            $autoSaved = ptsb_auto_get();
            $next = ptsb_next_occurrences_adv($autoSaved, 1);
            if ($next) {
                add_settings_error('ptsb','all_saved','Agenda atualizada. Pr&oacute;ximo disparo: '.$next[0]->format('d/m/Y H:i').' (BRT).','updated');
            } else {
                add_settings_error('ptsb','all_saved2','Agenda atualizada.','updated');
            }
        } else {
            wp_clear_scheduled_hook($cfg['cron_hook']);
            add_settings_error('ptsb','auto_off','Backups autom&aacute;ticos desativados.','updated');
        }

        add_settings_error('ptsb', 'ret_saved', 'Configura&ccedil;&otilde;es de reten&ccedil;&atilde;o salvas.', 'updated');
        ptsb_back();
    }

    /* ===== Renomear arquivo no Drive ===== */
    if ($act === 'rename') {
        if (!ptsb_can_shell()) { add_settings_error('ptsb','noshell','shell_exec desabilitado no PHP.', 'error'); ptsb_back(); }

        $old = isset($_POST['old_file']) ? sanitize_text_field($_POST['old_file']) : '';
        $in  = isset($_POST['new_file']) ? sanitize_text_field($_POST['new_file']) : '';

        if ($old === '' || $in === '') {
            add_settings_error('ptsb','rn_empty','Nome antigo/novo inválido.', 'error'); ptsb_back();
        }
        if (strpos($old,'/')!==false || strpos($old,'\\')!==false) {
            add_settings_error('ptsb','rn_slash','Nome antigo inválido.', 'error'); ptsb_back();
        }

        $prefix = ptsb_cfg()['prefix']; // "wpb-"

        // Aceita apelido OU nome completo -> normaliza para wpb-<nick>.tar.gz
        $nick = trim($in);
        $nick = preg_replace('/\.tar\.gz$/i','', $nick);
        $nick = preg_replace('/^'.preg_quote($prefix,'/').'/','', $nick);
        $nick = preg_replace('/[^A-Za-z0-9._-]+/', '-', $nick);
        $nick = trim($nick, '.-_');

        if ($nick === '') {
            add_settings_error('ptsb','rn_badnick','Apelido inválido.', 'error'); ptsb_back();
        }

        $new = $prefix . $nick . '.tar.gz';

        if (!preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', $new)) {
            add_settings_error('ptsb','rn_pat','Use apenas letras, números, ponto, hífen e sublinhado, terminando em .tar.gz.', 'error'); ptsb_back();
        }

        if ($old === $new) {
            add_settings_error('ptsb','rn_same','O nome não foi alterado.', 'updated'); ptsb_back();
        }

        // já existe arquivo com o novo nome?
        $exists = shell_exec(
            '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
            ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
            ' --include '.escapeshellarg($new).' --fast-list'
        );
        if (trim((string)$exists) !== '') {
            add_settings_error('ptsb','rn_exists','Já existe um arquivo com esse nome no Drive.', 'error'); ptsb_back();
        }

        // renomeia o arquivo principal
        $mv = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
            . ' rclone moveto ' . escapeshellarg($cfg['remote'].$old) . ' ' . escapeshellarg($cfg['remote'].$new) . ' --fast-list 2>&1';
        $out = shell_exec($mv);

        // checa sucesso
        $chkOld = shell_exec(
            '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
            ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
            ' --include '.escapeshellarg($old).' --fast-list'
        );

// renomeia .json (se existir): .tar.gz -> .json
$oldJson = ptsb_tar_to_json($old);
$newJson = ptsb_tar_to_json($new);

$hasJson = shell_exec(
    '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
  . ' rclone lsf ' . escapeshellarg($cfg['remote'])
  . ' --files-only --format "p" --include ' . escapeshellarg($oldJson) . ' --fast-list'
);

if (trim((string)$hasJson) !== '') {
    $mvj = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' rclone moveto ' . escapeshellarg($cfg['remote'].$oldJson) . ' ' . escapeshellarg($cfg['remote'].$newJson) . ' --fast-list';
    shell_exec($mvj);
}


        
        $chkNew = shell_exec(
            '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
            ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
            ' --include '.escapeshellarg($new).' --fast-list'
        );

        if (trim((string)$chkOld) === '' && trim((string)$chkNew) !== '') {
            // renomeia .keep (se existir)
            $oldKeep = $old.'.keep';
            $newKeep = $new.'.keep';
            $hasKeep = shell_exec(
                '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
                ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
                ' --include '.escapeshellarg($oldKeep).' --fast-list'
            );
            if (trim((string)$hasKeep) !== '') {
                $mvk = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                     . ' rclone moveto ' . escapeshellarg($cfg['remote'].$oldKeep).' '.escapeshellarg($cfg['remote'].$newKeep).' --fast-list';
                shell_exec($mvk);
            }

            ptsb_log('Arquivo renomeado via painel: '.$old.' ? '.$new);
            add_settings_error('ptsb','rn_ok','Arquivo renomeado para: '.$new, 'updated');
        } else {
            add_settings_error('ptsb','rn_fail','Falha ao renomear o arquivo. '.(is_string($out)?htmlspecialchars($out):''), 'error');
        }

        ptsb_back();
    }

    /* ===== Toggle Sempre manter ===== */
    if ($act === 'keep_toggle' && !empty($_POST['file'])) {
        $file = sanitize_text_field($_POST['file']);
        $keep = isset($_POST['keep']) && $_POST['keep'] === '1';

        if ($keep) {
            $touch = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                   . ' rclone touch ' . escapeshellarg($cfg['remote'].$file.'.keep') . ' --no-create-dirs';
            $rcat  = 'printf "" | /usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                   . ' rclone rcat ' . escapeshellarg($cfg['remote'].$file.'.keep');
            shell_exec($touch . ' || ' . $rcat);
            add_settings_error('ptsb', 'keep_on', 'Marcado como "Sempre manter".', 'updated');
        } else {
            $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                 . ' rclone deletefile ' . escapeshellarg($cfg['remote'].$file.'.keep') . ' --fast-list';
            shell_exec($cmd);
            add_settings_error('ptsb', 'keep_off', 'Marca "Sempre manter" removida.', 'updated');
        }
        ptsb_back();
    }

    /* ===== Restaurar / Apagar ===== */
    if (($act === 'restore' || $act === 'delete') && !empty($_POST['file'])) {
        $file = sanitize_text_field($_POST['file']);

        if ($act === 'restore') {
            $env = 'PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                 . 'REMOTE=' . escapeshellarg($cfg['remote']) . ' '
                 . 'FILE='   . escapeshellarg($file)        . ' '
                 . 'WP_PATH='. escapeshellarg(ABSPATH);
            $cmd = '/usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_restore'])
                 . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';
            shell_exec($cmd);
            add_settings_error('ptsb', 'rs_started', 'Restaura&ccedil;&atilde;o iniciada para: '.$file.'.', 'updated');

     } else {
    $chk = shell_exec(
        '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
      . ' rclone lsf ' . escapeshellarg($cfg['remote'])
      . ' --files-only --format "p" --include ' . escapeshellarg($file.'.keep') . ' --fast-list'
    );
    if (trim((string)$chk) !== '') {
        add_settings_error('ptsb', 'del_block', 'Este arquivo está marcado como "Sempre manter". Remova a marca antes de apagar.', 'error');
        ptsb_back();
    }

// apaga o .tar.gz
$cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
     . ' rclone deletefile ' . escapeshellarg($cfg['remote'].$file) . ' --fast-list';
shell_exec($cmd);

// apaga o sidecar JSON correto: foo.tar.gz -> foo.json
$jsonPath = ptsb_tar_to_json($file);
$cmd_json = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
          . ' rclone deletefile ' . escapeshellarg($cfg['remote'].$jsonPath) . ' --fast-list';
shell_exec($cmd_json);

delete_transient('ptsb_m_' . md5($file));

  

add_settings_error('ptsb', 'del_done', 'Arquivo (e JSON) removidos do Drive: '.$file, 'updated');

}

        ptsb_back();
    }

    ptsb_back();
});

add_action('admin_post_ptsb_cycles', function(){
    if (!current_user_can('manage_options')) wp_die('forbidden');
    check_admin_referer('ptsb_nonce');

    $act = isset($_POST['do']) ? sanitize_text_field($_POST['do']) : '';
    $cycles = ptsb_cycles_get();

      // Anotamos o timestamp da próxima execução de cada rotina (ou "infinito" se não houver)
  $cycles_sorted = [];
  foreach ($cycles as $c) {
      $next = ptsb_cycles_next_occurrences([$c], 1);
      // ts = timestamp da próxima execução; se não houver, manda pro final
      $ts = $next ? $next[0]['dt']->getTimestamp() : PHP_INT_MAX;

      // Se quiser empurrar rotinas desativadas ainda mais para o fim:
      // if (empty($c['enabled'])) { $ts = PHP_INT_MAX - 1; }

      $cycles_sorted[] = ['ts' => $ts, 'cycle' => $c];
  }

  // Ordena crescente pelo timestamp
  usort($cycles_sorted, function($a, $b){
      if ($a['ts'] === $b['ts']) {
          // critério secundário opcional: nome
          return strcasecmp($a['cycle']['name'] ?? '', $b['cycle']['name'] ?? '');
      }
      return $a['ts'] <=> $b['ts'];
  });

  // Substitui $cycles pela versão ordenada
  $cycles = array_map(fn($it) => $it['cycle'], $cycles_sorted);

    if ($act === 'save_global') {
        $valid = [12,24,36];
        $n = (int)($_POST['preview_count'] ?? 12);
        if (!in_array($n, $valid, true)) { $n = 12; }
        update_option('ptsb_preview_count', $n, true);

        // mantém globais fixos por código (merge=false, policy=queue, min-gap=10)
        add_settings_error('ptsb','cg_ok','Preferências salvas.', 'updated');
        ptsb_back();
    }
    
    if ($act === 'skip_toggle') {
    // recebe time como 'YYYY-mm-dd HH:ii' (no seu fuso configurado)
    $time = sanitize_text_field($_POST['time'] ?? '');
    $on   = !empty($_POST['skip']);

    // normaliza a chave
    try {
        $dt  = new DateTimeImmutable($time.':00', ptsb_tz());
        $key = ptsb_skip_key($dt);
    } catch (Throwable $e) {
        $key = preg_replace('/[^0-9:\-\s]/', '', $time);
    }

    $map = ptsb_skipmap_get();
    if ($on)  { $map[$key] = true; $msg = 'Execução marcada para ser ignorada: '.$key; }
    else      { unset($map[$key]);  $msg = 'Execução recolocada: '.$key; }
    ptsb_skipmap_save($map);

    add_settings_error('ptsb','skip_ok', $msg, 'updated');
    ptsb_back();
}


    if ($act === 'toggle') {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($cycles as &$c) {
            if ($c['id'] === $id) {
                $c['enabled'] = !empty($_POST['enabled']);
                $c['updated_at'] = gmdate('c');
                break;
            }
        }
        ptsb_cycles_save($cycles);
        add_settings_error('ptsb','cx_onoff','Rotina atualizada.','updated');
        ptsb_back();
    }

  if ($act === 'delete') {
    $id = sanitize_text_field($_POST['id'] ?? '');
    $cycles = array_values(array_filter($cycles, fn($c)=>$c['id'] !== $id));
    ptsb_cycles_save($cycles);
    update_option('ptsb_cycles_legacy_migrated', 1, true); // garante que não remigre
    add_settings_error('ptsb','cx_del','Rotina removida.','updated');
    ptsb_back();
}


    if ($act === 'dup') {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($cycles as $c) if ($c['id']===$id) {
            $copy = $c;
            $copy['id'] = ptsb_uuid4();
            $copy['name'] = ($c['name'] ?? 'Rotina').' (cópia)';
            $copy['enabled'] = false;
            $copy['created_at']=gmdate('c'); $copy['updated_at']=gmdate('c');
            $cycles[] = $copy;
            ptsb_cycles_save($cycles);
            add_settings_error('ptsb','cx_dup','Rotina duplicada (desativada).','updated');
            break;
        }
        ptsb_back();
    }

    if ($act === 'save_one') {
        $id   = sanitize_text_field($_POST['id'] ?? '');
        $is_new = ($id === '');
        if ($is_new) $id = ptsb_uuid4();

        $name = sanitize_text_field($_POST['name'] ?? '');
if ($name === '') {
    add_settings_error('ptsb','cx_name_req','Informe um <strong>nome</strong> para a rotina.','error');
    ptsb_back();
}

        $enabled = !empty($_POST['enabled']);
        $mode = sanitize_text_field($_POST['mode'] ?? 'daily');
        if (!in_array($mode, ['daily','weekly','every_n','interval'], true)) $mode = 'daily';

        // letters (chips)
        $letters = array_values(array_unique(array_map('strtoupper', (array)($_POST['letters'] ?? []))));
        $letters = array_values(array_intersect($letters, ['D','P','T','W','S','M','O']));
        if (!$letters) $letters = ['D','P','T','W','S','M','O'];

        // policy: sempre enfileirar
        $policy = 'queue';

      // retenção por rotina: 0 = sempre manter (sentinela)
if (!empty($_POST['keep_forever'])) {
    $keep_days = 0;
} else {
    $keep_days = isset($_POST['keep_days']) ? max(1, min((int)$_POST['keep_days'], 3650)) : null;
}


        // cfg por modo
     $cfg = [];
$fail_and_back = function($code,$msg){
    add_settings_error('ptsb', $code, $msg, 'error');
    ptsb_back();
};

$normalize_times = function($arr){
    // remove vazios, normaliza e ordena
    $arr = array_map('strval', (array)$arr);
    $arr = array_values(array_filter($arr, fn($s)=>trim($s) !== ''));
    return ptsb_times_sort_unique($arr);
};

if ($mode === 'daily') {
    $times = $normalize_times($_POST['times'] ?? []);
    if (!$times) $fail_and_back('cx_time_req','Informe pelo menos <strong>1 horário</strong> (HH:MM).');
    $qty   = max(1, min(12, (int)($_POST['qty'] ?? count($times))));
    if (count($times) !== $qty) {
        $fail_and_back('cx_time_qty','Preencha todos os <strong>'.$qty.'</strong> horários.');
    }
    $cfg   = ['times'=>$times, 'qty'=>$qty];

} elseif ($mode === 'weekly') {
    $days = array_map('intval', (array)($_POST['wk_days'] ?? []));
    $days = array_values(array_unique(array_filter($days, fn($d)=>$d>=0 && $d<=6)));
    if (!$days) $fail_and_back('cx_wk_days','Selecione pelo menos <strong>1 dia da semana</strong>.');

    $times = $normalize_times($_POST['times'] ?? []);
    if (!$times) $fail_and_back('cx_wk_time','Informe pelo menos <strong>1 horário</strong> (HH:MM).');
    $qty   = max(1, min(12, (int)($_POST['qty'] ?? count($times))));
    if (count($times) !== $qty) {
        $fail_and_back('cx_wk_qty','Preencha todos os <strong>'.$qty.'</strong> horários.');
    }
    $cfg   = ['days'=>$days, 'times'=>$times, 'qty'=>$qty];

} elseif ($mode === 'every_n') {
    $n     = max(1, min(30, (int)($_POST['n'] ?? 1)));
    $start = sanitize_text_field($_POST['start'] ?? ptsb_now_brt()->format('Y-m-d'));

    $times = $normalize_times($_POST['times'] ?? []);
    if (!$times) $fail_and_back('cx_en_time','Informe pelo menos <strong>1 horário</strong> (HH:MM).');
    $qty   = max(1, min(12, (int)($_POST['qty'] ?? count($times))));
    if (count($times) !== $qty) {
        $fail_and_back('cx_en_qty','Preencha todos os <strong>'.$qty.'</strong> horários.');
    }
    $cfg   = ['n'=>$n, 'start'=>$start, 'times'=>$times, 'qty'=>$qty];

} else { // interval
    // (sem alteração aqui)
    $val  = max(1, (int)($_POST['every_val'] ?? 60));
    $unit = strtolower(sanitize_text_field($_POST['every_unit'] ?? 'minute'));
    if (!in_array($unit, ['minute','hour','day'], true)) $unit = 'minute';
    $ws   = trim((string)($_POST['win_start'] ?? '00:00'));
    $we   = trim((string)($_POST['win_end']   ?? '23:59'));
    $wdis = !empty($_POST['win_disable']) ? 1 : 0;

    $cfg = ['every'=>['value'=>$val,'unit'=>$unit],'win'=>['start'=>$ws,'end'=>$we,'disabled'=>$wdis]];
}


        // grava
        $found=false;
        foreach ($cycles as &$c) {
            if ($c['id'] === $id) {
                $c['enabled']=$enabled; $c['name']=$name; $c['mode']=$mode; $c['cfg']=$cfg; $c['letters']=$letters; $c['policy']=$policy; $c['keep_days']=$keep_days;
                $c['updated_at']=gmdate('c');
                $found=true; break;
            }
        }
        if (!$found) {
            $cycles[] = [
                'id'=>$id,'enabled'=>$enabled,'name'=>$name,'mode'=>$mode,'cfg'=>$cfg,'letters'=>$letters,'policy'=>$policy,'keep_days'=>$keep_days,
                'priority'=>0,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c')
            ];
        }
        ptsb_cycles_save($cycles);
        add_settings_error('ptsb','cx_saved','Rotina salva.','updated');
        ptsb_back();
    }

    ptsb_back();
});
