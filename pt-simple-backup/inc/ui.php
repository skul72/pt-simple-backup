<?php
if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------
 * Menu + assets (dashicons)
 * -----------------------------------------------------*/
add_action('admin_menu', function () {
    add_management_page('Backup', 'Backup', 'manage_options', 'pt-simple-backup', 'ptsb_render_backup_page'); // ptsb_render_backup_page é definida na PARTE 2
});
add_action('admin_enqueue_scripts', function($hook){
    if ($hook === 'tools_page_pt-simple-backup') {
        wp_enqueue_style('dashicons');
        if (defined('PTSB_PLUGIN_URL')) {
            $base = trailingslashit(PTSB_PLUGIN_URL) . 'assets/';
            wp_enqueue_style('ptsb-admin', $base . 'admin.css', ['dashicons'], PTSB_PLUGIN_VERSION ?? null);
            wp_enqueue_script('ptsb-admin', $base . 'admin.js', ['jquery'], PTSB_PLUGIN_VERSION ?? null, true);
        }
    }
});

// força no-cache nessa página do admin
add_action('load-tools_page_pt-simple-backup', function () {
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCDN'))      define('DONOTCDN', true);
    if (!defined('DONOTCACHEDB'))  define('DONOTCACHEDB', true);

    // PULA o cache de manifest APENAS quando houver ?force=1
    $force = isset($_GET['force']) && (int)$_GET['force'] === 1;
    if ($force && !defined('PTSB_SKIP_MANIFEST_CACHE')) {
        define('PTSB_SKIP_MANIFEST_CACHE', true);
    }

    if (defined('LSCWP_VERSION')) {
        do_action('litespeed_control_set_nocache');
    }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
});


function ptsb_cycle_params_label_ui(array $cycle): string {
    $mode = (string)($cycle['mode'] ?? 'daily');
    $cfg  = is_array($cycle['cfg'] ?? null) ? $cycle['cfg'] : [];

    // Junta SEMPRE: times[] + time (legado) e normaliza
    $times = [];
    if (!empty($cfg['times']) && is_array($cfg['times'])) $times = array_map('strval', $cfg['times']);
    if (!empty($cfg['time'])) $times[] = (string)$cfg['time'];
    $times = array_values(array_filter($times, fn($s)=>trim($s) !== ''));

    if (function_exists('ptsb_times_sort_unique')) {
        $times = ptsb_times_sort_unique($times);
    }

    if ($mode === 'daily') {
        return $times ? implode(', ', $times) : '—';
    }

    if ($mode === 'weekly') {
        $labels = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        $days   = array_map('intval', $cfg['days'] ?? []);
        $days   = array_values(array_filter($days, fn($d)=>$d>=0 && $d<=6));
        $dias   = $days ? implode(', ', array_map(fn($d)=>$labels[$d], $days)) : '—';
        $horas  = $times ? implode(', ', $times) : '—';
        return "{$dias} · {$horas}";
    }

    if ($mode === 'every_n') {
        // >>> NOVO: só horários (sem "N=" e sem "início=")
        $horas = $times ? implode(', ', $times) : '—';
        return $horas;
    }

  if ($mode === 'interval') {
    $u    = strtolower($cfg['every']['unit'] ?? 'minute');
    $u_br = ($u === 'day' ? 'dia(s)' : ($u === 'hour' ? 'hora(s)' : 'minuto(s)'));
    $val  = (int)($cfg['every']['value'] ?? 1);

    $win_on = empty($cfg['win']['disabled']);
    $winTxt = $win_on ? (($cfg['win']['start'] ?? '00:00').'–'.($cfg['win']['end'] ?? '23:59')) : '';

    $base = "cada {$val} {$u_br}";
    return $base . ($win_on ? ' · ' . $winTxt : '');
}


    return '—';
}

function ptsb_render_backup_page() {
    if (!current_user_can('manage_options')) return;

    $cfg       = ptsb_cfg();
    $set       = ptsb_settings();
    $forceList = isset($_GET['force']) && (int)$_GET['force'] === 1;
    $rows    = [];
    $keepers = [];

    // === Abas (roteamento) ===
$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backup';
if (!in_array($tab, ['backup','cycles','next','last','settings'], true)) $tab = 'backup';


    $h1 = [
    'backup'    => 'Backups (Google Drive)',
    'cycles'    => 'Rotinas de Backup',
    'next'      => 'Próximas Execuções',
    'last'      => 'Últimas Execuções',
    'settings'  => 'Configurações',
][$tab];

    // Diagnóstico
    $diag = [];
    $diag[] = 'shell_exec '.(ptsb_can_shell() ? 'OK' : 'DESABILITADO');
    $diag[] = 'log '.(ptsb_is_readable($cfg['log']) ? 'legivel' : 'sem leitura');
    $diag[] = 'backup.sh '.(@is_executable($cfg['script_backup']) ? 'executavel' : 'sem permissao');
    $diag[] = 'restore.sh '.(@is_executable($cfg['script_restore']) ? 'executavel' : 'sem permissao');

    $nonce   = wp_create_nonce('ptsb_nonce');
    $referer = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

    // Drive (resumo)
  $drive = ptsb_drive_info();

// se vier ?force=1, zera o cache dos totais
if (isset($_GET['force']) && (int)$_GET['force'] === 1) {
    delete_transient('ptsb_totals_v1');
}
$tot    = ptsb_backups_totals_cached();
$bk_count       = (int)$tot['count'];
$backups_total  = (int)$tot['bytes'];

$usedStr  = ($drive['used']  !== null) ? ptsb_hsize_compact($drive['used'])  : '?';
$totalStr = ($drive['total'] !== null) ? ptsb_hsize_compact($drive['total']) : '?';
$bkStr    = number_format_i18n($bk_count) . ' ' . ($bk_count === 1 ? 'item' : 'itens') . ' / ' . ptsb_hsize_compact($backups_total);


    // Navegação das abas
    $base = admin_url('tools.php?page=pt-simple-backup');
   $tabs = [
    'backup'    => 'Backup',
    'cycles'    => 'Rotinas de Backup',
    'next'      => 'Próximas Execuções',
    'last'      => 'Últimas Execuções',
    'settings'  => 'Configurações',
];

    // CSS leve compartilhado (chips + “pílulas”)
    ?>
    <div class="wrap">
      <h1><?php echo esc_html($h1); ?></h1>
      <p style="opacity:.7;margin:.3em 0 1em">
        Armazenamento: <strong><?php echo esc_html($usedStr.' / '.$totalStr); ?></strong> |
        Backups no Drive: <strong><?php echo esc_html($bkStr); ?></strong>
      </p>

      <h2 class="nav-tab-wrapper" style="margin-top:8px">
        <?php foreach ($tabs as $slug => $label):
          $url = esc_url( add_query_arg('tab', $slug, $base) );
          $cls = 'nav-tab' . ($tab === $slug ? ' nav-tab-active' : '');
        ?>
          <a class="<?php echo $cls; ?>" href="<?php echo $url; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
      </h2>

      

      <?php if ($tab === 'backup'): ?>

        <?php
        $rows    = ptsb_list_remote_files($forceList);
        $keepers = ptsb_keep_map();
        ?>

        <!-- ===== ABA: BACKUP ===== -->

        <h2 style="margin-top:24px !important">Fazer Backup</h2>

 <p class="description">
           Escolha quais partes do site incluir no backup. Para um backup completo, mantenha todos selecionados.
          </p>

        <!-- Disparar manual -->
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" id="ptsb-now-form" style="margin:12px 0;">
          <?php wp_nonce_field('ptsb_nonce'); ?>
          <input type="hidden" name="action" value="ptsb_do"/>
          <input type="hidden" name="ptsb_action" value="backup_now"/>
          <input type="hidden" name="parts_sel[]" value="" id="ptsb-parts-hidden-sentinel" />

       <div class="ptsb-chips" id="ptsb-chips">

  <label class="ptsb-chip" data-letter="D">
    <input type="checkbox" checked data-letter="D">
    <span class="dashicons dashicons-database"></span> Banco de Dados
  </label>
  <label class="ptsb-chip" data-letter="P">
    <input type="checkbox" checked data-letter="P">
    <span class="dashicons dashicons-admin-plugins"></span> Plugins
  </label>
  <label class="ptsb-chip" data-letter="T">
    <input type="checkbox" checked data-letter="T">
    <span class="dashicons dashicons-admin-appearance"></span> Temas
  </label>
  <label class="ptsb-chip" data-letter="W">
    <input type="checkbox" checked data-letter="W">
    <span class="dashicons dashicons-wordpress-alt"></span> Core
  </label>
  <label class="ptsb-chip" data-letter="S">
    <input type="checkbox" checked data-letter="S">
    <span class="dashicons dashicons-editor-code"></span> Scripts
  </label>
  <label class="ptsb-chip" data-letter="M">
    <input type="checkbox" checked data-letter="M">
    <span class="dashicons dashicons-admin-media"></span> Mídia
  </label>
  <label class="ptsb-chip" data-letter="O">
    <input type="checkbox" checked data-letter="O">
    <span class="dashicons dashicons-image-filter"></span> Outros
  </label>
</div>


       

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 2px">
            <label>Nome do backup:
              <input type="text" name="manual_name" placeholder="Opcional" style="min-width:280px">
            </label>
            <label>Armazenar por quantos dias?
              <input type="number" name="manual_keep_days" min="1" max="3650"
                     placeholder="Máx: 3650" required style="width:120px">
            </label>
            <div class="ptsb-keep-toggle" style="align-self:flex-end;margin-top:4px">
  <label class="ptsb-switch" title="Sempre manter">
    <input type="checkbox" id="ptsb-man-keep-forever" name="manual_keep_forever" value="1">
    <span class="ptsb-slider" aria-hidden="true"></span>
  </label>
  <span class="ptsb-keep-txt">Sempre manter</span>
</div>

          </div>

         <div class="ptsb-btns" style="margin-top: 18px;">
  <button class="button button-primary">Fazer backup agora</button>
  <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($cfg['drive_url']); ?>">Ver no Drive</a>
</div>

        </form>

        <!-- Barra de progresso -->
        <div id="ptsb-progress" style="display:none;margin:16px 0;border:1px solid #444;background:#1b1b1b;height:22px;position:relative;border-radius:4px;overflow:hidden;">
          <div id="ptsb-progress-bar" style="height:100%;width:5%;background:#2271b1;transition:width .4s ease"></div>
          <div id="ptsb-progress-text" style="position:absolute;left:8px;top:0;height:100%;line-height:22px;color:#fff;opacity:.9;font-size:12px;">Iniciando…</div>
        </div>

        <?php
          $dumpRemoteDir = ptsb_db_dump_remote_dir($cfg);
          $dumpTarget = $cfg['remote'];
          if ($dumpRemoteDir !== '') {
              $dumpPrefix = $cfg['remote'];
              $lastChar = substr($dumpPrefix, -1);
              if ($lastChar !== ':' && $lastChar !== '/') {
                  $dumpPrefix .= '/';
              }
              $dumpTarget = $dumpPrefix . $dumpRemoteDir;
          }
        ?>
        <h3 style="margin-top:28px;">Dump do Banco (SQL)</h3>
        <p class="description">
          Gera um arquivo <code>.sql.gz</code> com <code>mysqldump --single-transaction --quick</code> em segundo plano e envia para <strong><?php echo esc_html($dumpTarget); ?></strong>.
        </p>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin:12px 0;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
          <?php wp_nonce_field('ptsb_nonce'); ?>
          <input type="hidden" name="action" value="ptsb_do"/>
          <input type="hidden" name="ptsb_action" value="db_dump"/>
          <label>Apelido (opcional)
            <input type="text" name="db_nick" placeholder="Ex.: relatorio" style="min-width:220px">
          </label>
          <button class="button" <?php disabled(!ptsb_can_shell()); ?>>Gerar dump SQL</button>
          <?php if (!ptsb_can_shell()): ?>
            <span class="description">Requer <code>shell_exec</code> habilitado.</span>
          <?php endif; ?>
        </form>

        <!-- Arquivos no Drive -->
       <!-- Arquivos no Drive -->
<h2 style="margin-top:24px !important">Arquivos no Google Drive  <a class="button button-small" style="margin-left:8px"
     href="<?php echo esc_url( add_query_arg(['force'=>1], $base) ); ?>">Forçar atualizar</a></h2>
<?php
  // ====== PAGINAÇÃO (lista do Drive) ======
  $total = count($rows);

  // valor padrão salvo (opcional) + query string
$per_default = (int) get_option('ptsb_list_per_page', 25);
$per = isset($_GET['per']) ? (int) $_GET['per'] : ($per_default > 0 ? $per_default : 25);

  $per = max(1, min($per, 500));              // limite de sanidade
  if (isset($_GET['per'])) update_option('ptsb_list_per_page', $per, false); // lembra preferência

  $paged = max(1, (int)($_GET['paged'] ?? 1));
  $total_pages = max(1, (int) ceil($total / $per));
  if ($paged > $total_pages) $paged = $total_pages;

  $offset    = ($paged - 1) * $per;
  $rows_page = array_slice($rows, $offset, $per);

  // URL base para os links de paginação
  $base_admin = admin_url('tools.php');
  $make_url = function($p) use ($base_admin, $per) {
      return esc_url( add_query_arg([
          'page'  => 'pt-simple-backup',
          'tab'   => 'backup',
          'per'   => $per,
          'paged' => (int) $p
      ], $base_admin) );
  };
?>

<!-- Filtro "Exibindo N de M" -->
 <!-- “Exibindo N de M” -->
 <!-- “Exibindo N de M” -->
<form method="get" id="ptsb-last-per-form" class="ptsb-list-controls" style="margin:0">
  <input type="hidden" name="page" value="pt-simple-backup">
  <input type="hidden" name="tab"  value="last">
  <input type="hidden" name="page_last" value="1">
  <span>Exibindo</span>
  <input type="number" name="per_last" min="1" max="500" value="<?php echo (int)$per_last; ?>" style="width:auto">
  <span>de <?php echo (int)$total_last; ?> execuções — página <?php echo (int)$page_last; ?> de <?php echo (int)$total_pages_l; ?></span>
</form>

</div>

<script>
(function(){
  var f=document.getElementById('ptsb-per-form'); if(!f) return;
  var i=f.querySelector('input[name="per"]');
  i.addEventListener('change', function(){ f.submit(); });
})();
</script>

<table class="widefat striped">

          <thead>
            <tr>
              <th>Data/Hora</th>
              <th>Arquivo</th>
              <th>Rotina</th>
              <th>Backup</th>
              <th>Tamanho</th>
              <th>Retenção</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
       <?php if ($total === 0): ?>
  <tr><td colspan="7"><em>Nenhum backup encontrado.</em></td></tr>

<?php else:
  foreach ($rows_page as $r):

    $time = $r['time']; $file = $r['file']; $size = (int)($r['size'] ?? 0);
    $is_kept = !empty($keepers[$file]);
    $manifest = ptsb_manifest_read($file);
    $letters = [];
    if (!empty($manifest['parts'])) $letters = ptsb_parts_to_letters($manifest['parts']);
    if (!$letters) $letters = ['D','P','T','W','S','M','O'];
    $keepDays  = ptsb_manifest_keep_days($manifest, (int)$set['keep_days']);
    $rotina_label = ptsb_run_kind_label($manifest, $file);

    // NOVO: calcular se está vencido (não-keep e X/Y >= Y/Y)
    $ri = null; $is_expired = false;
    if (!$is_kept && is_int($keepDays) && $keepDays > 0) {
        $ri = ptsb_retention_calc($time, $keepDays);
        $is_expired = ($ri['x'] >= $ri['y']);
    }
    
    $tr_class = $is_expired ? ' class="ptsb-expired"' : '';
?>
  
<?php
  $time = $r['time']; 
  $file = $r['file']; 
  $size = (int)($r['size'] ?? 0);
  $is_kept = !empty($keepers[$file]); // mantém só o mapa .keep (1 listagem rclone)
?>
<tr data-file="<?php echo esc_attr($file); ?>" 
    data-time="<?php echo esc_attr($time); ?>" 
    data-kept="<?php echo $is_kept ? 1 : 0; ?>">

  <td><?php echo esc_html( ptsb_fmt_local_dt($time) ); ?></td>

  <td>
    <span class="ptsb-filename"><?php echo esc_html($file); ?></span>
    <!-- o badge “vencido” será inserido via JS, se for o caso -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>"
          class="ptsb-rename-form" style="display:inline">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="rename"/>
      <input type="hidden" name="old_file" value="<?php echo esc_attr($file); ?>"/>
      <input type="hidden" name="new_file" value=""/>
      <button type="button" class="ptsb-rename-btn" title="Renomear" data-old="<?php echo esc_attr($file); ?>">
        <span class="dashicons dashicons-edit" aria-hidden="true"></span>
        <span class="screen-reader-text">Renomear</span>
      </button>
    </form>
  </td>

  <!-- ROTINA (placeholder) -->
  <td class="ptsb-col-rotina"><span class="description">carregando…</span></td>

  <!-- BACKUP (letras; placeholder) -->
  <td class="ptsb-col-letters" aria-label="Partes incluídas">
    <span class="description">carregando…</span>
  </td>

  <td><?php echo esc_html( ptsb_hsize($size) ); ?></td>

  <!-- RETENÇÃO (placeholder; “sempre”, X/Y ou “—”) -->
  <td class="ptsb-col-ret"><span class="description">carregando…</span></td>

  <!-- AÇÕES (inalterado) -->
  <td class="ptsb-actions">
    <!-- Restaurar -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:0;"
          onsubmit="return confirm('Restaurar <?php echo esc_js($file); ?>? Isso vai sobrescrever arquivos e banco.');">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <button class="button button-secondary" name="ptsb_action" value="restore" <?php disabled(!ptsb_can_shell()); ?>>Restaurar</button>
    </form>

    <!-- Apagar -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:6px;"
          onsubmit="return confirm('Apagar DEFINITIVAMENTE do Drive: <?php echo esc_js($file); ?>?');">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <button class="button" name="ptsb_action" value="delete"
              <?php disabled(!ptsb_can_shell() || $is_kept); ?>
              <?php echo $is_kept ? 'title="Desative &quot;Sempre manter&quot; antes de apagar"' : ''; ?>>
        Apagar
      </button>
    </form>

    <!-- Toggle “Sempre manter” (inalterado) -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="ptsb-keep-form">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="keep_toggle"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <div class="ptsb-keep-toggle">
        <label class="ptsb-switch" title="<?php echo $is_kept ? 'Desativar' : 'Ativar'; ?>">
          <input type="checkbox" name="keep" value="1" <?php checked($is_kept); ?> onchange="this.form.submit()">
          <span class="ptsb-slider" aria-hidden="true"></span>
        </label>
        <span class="ptsb-keep-txt">Sempre manter</span>
      </div>
    </form>
  </td>
</tr>

  
          <?php endforeach; endif; ?>
          </tbody>
        </table>

        <script>
(function(){
  const ajaxUrl = window.ajaxurl || "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const nonce   = "<?php echo esc_js($nonce); ?>";

  // coleta arquivos visíveis da página atual
  function collectFiles(){
    return Array.from(document.querySelectorAll('table.widefat tbody tr[data-file]'))
      .map(tr => tr.getAttribute('data-file'));
  }

  // util: desenha os ícones de letras
  function letterIcon(L){
    const map = {
      'D': 'dashicons-database',
      'P': 'dashicons-admin-plugins',
      'T': 'dashicons-admin-appearance',
      'W': 'dashicons-wordpress-alt',
      'S': 'dashicons-editor-code',
      'M': 'dashicons-admin-media',
      'O': 'dashicons-image-filter'
    };
    const cls = map[L] || 'dashicons-marker';
    return '<span class="ptsb-mini" title="'+L+'"><span class="dashicons '+cls+'"></span></span>';
  }

  // util: calcula badge de retenção (“sempre” | X/Y | —) e vencido
  function renderRetentionCell(tr, keepDays){
    const kept = tr.getAttribute('data-kept') === '1';
    const td   = tr.querySelector('.ptsb-col-ret'); if (!td) return;

    if (kept) {
      td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
      return;
    }
    if (keepDays === null) {
      td.textContent = '—';
      return;
    }
    if (keepDays === 0) {
      td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
      return;
    }
    const iso = tr.getAttribute('data-time');
    const created = new Date(iso);
    const now = new Date();
    const elapsedDays = Math.max(0, Math.floor((now - created) / 86400000));
    const x = Math.min(keepDays, elapsedDays + 1);
    const expired = (x >= keepDays);

    td.innerHTML = '<span class="ptsb-ret" title="Dia '+x+' de '+keepDays+'">'+x+'/'+keepDays+'</span>';

    // aplica classe “vencido” na linha + badge no nome do arquivo (se quiser)
    if (expired && !kept) {
      tr.classList.add('ptsb-expired');
      const nameCell = tr.querySelector('.ptsb-filename');
      if (nameCell && !nameCell.nextElementSibling?.classList?.contains('ptsb-tag')) {
        const tag = document.createElement('span');
        tag.className = 'ptsb-tag vencido';
        tag.textContent = 'vencido';
        nameCell.insertAdjacentElement('afterend', tag);
      }
    }
  }

  function hydrate(){
    const files = collectFiles();
    if (!files.length) return;

    const body = new URLSearchParams();
    body.set('action', 'ptsb_details_batch');
    body.set('nonce', nonce);
    files.forEach(f => body.append('files[]', f));

    fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r => r.json()).then(res => {
        if (!res || !res.success || !res.data) return;
        const data = res.data;

        // preenche cada linha
        files.forEach(file => {
          const tr = document.querySelector('tr[data-file="'+CSS.escape(file)+'"]');
          if (!tr) return;
          const d  = data[file] || {};

          // Rotina
          const cR = tr.querySelector('.ptsb-col-rotina');
          if (cR) cR.textContent = d.routine_label || '—';

          // Letras
          const cL = tr.querySelector('.ptsb-col-letters');
          if (cL) {
            const letters = (d.parts_letters && d.parts_letters.length) ? d.parts_letters : ['D','P','T','W','S','M','O'];
            cL.innerHTML = letters.map(letterIcon).join('');
          }

          // Retenção (e marca "vencido")
          renderRetentionCell(tr, (d.keep_days === null ? null : parseInt(d.keep_days,10)));
        });
      })
      .catch(()=>{ /* silencioso */ });
  }

  // roda após a tabela existir
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
  } else {
    hydrate();
  }
})();
</script>

        
        <?php if ($total_pages > 1): ?>
  <nav class="ptsb-pager" aria-label="Paginação dos backups">
    <a class="btn <?php echo $paged<=1?'is-disabled':''; ?>"
       href="<?php echo $paged>1 ? $make_url(1) : '#'; ?>" aria-disabled="<?php echo $paged<=1?'true':'false'; ?>"
       title="Primeira página">
      <span class="dashicons dashicons-controls-skipback"></span>
    </a>

    <a class="btn <?php echo $paged<=1?'is-disabled':''; ?>"
       href="<?php echo $paged>1 ? $make_url($paged-1) : '#'; ?>" aria-disabled="<?php echo $paged<=1?'true':'false'; ?>"
       title="Página anterior">
      <span class="dashicons dashicons-arrow-left-alt2"></span>
    </a>

    <span class="status">
      <input id="ptsb-pager-input" class="current" type="number"
             min="1" max="<?php echo (int)$total_pages; ?>" value="<?php echo (int)$paged; ?>">
      <span class="sep">de</span>
      <span class="total"><?php echo (int)$total_pages; ?></span>
    </span>

    <a class="btn <?php echo $paged>=$total_pages?'is-disabled':''; ?>"
       href="<?php echo $paged<$total_pages ? $make_url($paged+1) : '#'; ?>" aria-disabled="<?php echo $paged>=$total_pages?'true':'false'; ?>"
       title="Próxima página">
      <span class="dashicons dashicons-arrow-right-alt2"></span>
    </a>

    <a class="btn <?php echo $paged>=$total_pages?'is-disabled':''; ?>"
       href="<?php echo $paged<$total_pages ? $make_url($total_pages) : '#'; ?>" aria-disabled="<?php echo $paged>=$total_pages?'true':'false'; ?>"
       title="Última página">
      <span class="dashicons dashicons-controls-skipforward"></span>
    </a>
  </nav>

<script>
  (function(){
    var i=document.getElementById('ptsb-pager-input');
    if(!i) return;
    function go(){
      var min=parseInt(i.min,10)||1, max=parseInt(i.max,10)||1;
      var v = Math.max(min, Math.min(max, parseInt(i.value,10)||min));
      // >>> TROCA: mantém na aba "backup" e usa $per/$paged
      location.href = '<?php echo esc_js( add_query_arg([
        'page'  => 'pt-simple-backup',
        'tab'   => 'backup',
        'per'   => $per,
        'paged' => '__P__',
      ], admin_url('tools.php')) ); ?>'.replace('__P__', v);
    }
    i.addEventListener('change', go);
    i.addEventListener('keyup', function(e){ if(e.key==='Enter'){ go(); }});
  })();
</script>

<?php endif; ?>


        <script>
        (function(){
          // Chips -> envia letters em parts_sel[]
          const chipsBox = document.getElementById('ptsb-chips');
          const formNow  = document.getElementById('ptsb-now-form');
          function getActiveLetters(){
            const arr=[]; chipsBox.querySelectorAll('.ptsb-chip').forEach(c=>{
              if(c.classList.contains('active')) arr.push(String(c.dataset.letter||'').toUpperCase());
            }); return arr;
          }
         function getActiveLetters(){
  const sel = chipsBox.querySelectorAll('input[type="checkbox"][data-letter]:checked');
  return Array.from(sel).map(i => String(i.dataset.letter||'').toUpperCase());
}

          formNow.addEventListener('submit', function(){
            const sentinel = document.getElementById('ptsb-parts-hidden-sentinel');
            if(sentinel) sentinel.parentNode.removeChild(sentinel);
            formNow.querySelectorAll('input[name="parts_sel[]"]').forEach(i=>i.remove());
            const L = getActiveLetters();
            (L.length ? L : ['D','P','T','W','S','M','O']).forEach(letter=>{
              const i=document.createElement('input'); i.type='hidden'; i.name='parts_sel[]'; i.value=letter; formNow.appendChild(i);
            });
          });
        })();
        (function(){
          const cb   = document.getElementById('ptsb-man-keep-forever');
          const days = document.querySelector('#ptsb-now-form input[name="manual_keep_days"]');
          if (!cb || !days) return;
          function sync(){ days.disabled = cb.checked; days.style.opacity = cb.checked ? .5 : 1; }
          cb.addEventListener('change', sync); sync();
        })();

       (function(){
  const ajaxUrl = window.ajaxurl || "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const nonce   = "<?php echo esc_js($nonce); ?>";

  const barBox  = document.getElementById('ptsb-progress');
  const bar     = document.getElementById('ptsb-progress-bar');
  const btxt    = document.getElementById('ptsb-progress-text');

  let wasRunning=false, didReload=false;

  function poll(){
    const body = new URLSearchParams({action:'ptsb_status', nonce:nonce}).toString();
    fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body})
      .then(r=>r.json()).then(res=>{
        if(!res || !res.success) return;
        const s = res.data || {};
        if(s.running){
          wasRunning = true; barBox.style.display='block';
          const pct = Math.max(5, Math.min(100, s.percent|0));
          bar.style.width = pct + '%';
          btxt.textContent = (pct<100 ? (pct+'% - '+(s.stage||'executando…')) : '100%');
        } else {
          if(wasRunning && (s.percent|0) >= 100 && !didReload){
            didReload = true; bar.style.width='100%'; btxt.textContent='100% - concluído';
            setTimeout(function(){ location.reload(); }, 1200);
          } else {
            barBox.style.display='none';
          }
          wasRunning = false;
        }
      }).catch(()=>{});
  }
  poll(); setInterval(poll, 2000);
})();

        (function(){
          // Renomear por apelido
          document.addEventListener('click', function(ev){
            const btn = ev.target.closest('.ptsb-rename-btn'); if(!btn) return;
            const form = btn.closest('form.ptsb-rename-form'); if(!form) return;
            const oldFull = btn.getAttribute('data-old')||'';
            const prefix  = "<?php echo esc_js( ptsb_cfg()['prefix'] ); ?>";
            let currentNick = oldFull.replace(new RegExp('^'+prefix), '').replace(/\.tar\.gz$/i,'');
            let nick = window.prompt('Novo apelido (apenas a parte entre "'+prefix+'" e ".tar.gz"):', currentNick);
            if(nick === null) return;
            nick = (nick||'').trim().replace(/\.tar\.gz$/i,'').replace(new RegExp('^'+prefix),'').replace(/[^A-Za-z0-9._-]+/g,'-');
            if(!nick){ alert('Apelido inválido.'); return; }
            const newFull = prefix + nick + '.tar.gz';
            if(newFull === oldFull){ alert('O nome não foi alterado.'); return; }
            if(!/^[A-Za-z0-9._-]+\.tar\.gz$/.test(newFull)){ alert('Use apenas letras, números, ponto, hífen e sublinhado. A extensão deve ser .tar.gz.'); return; }
            form.querySelector('input[name="new_file"]').value = newFull;
            form.submit();
          });
        })();
        </script>

     <?php elseif ($tab === 'cycles'): ?>

  <!-- ===== ABA: ROTINAS ===== -->
  <h2 style="margin-top:22px">Rotinas de backup</h2>

  <!-- NOVO: botão/abridor "Adicionar rotina" (sem ícone) logo abaixo do título -->
  <div style="margin:10px 0 14px;">
  <details>
    <summary class="button button-primary">Adicionar rotina</summary>
    <div style="padding:10px 0">
      <form id="ptsb-add-cycle-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <?php wp_nonce_field('ptsb_nonce'); ?>
        <input type="hidden" name="action" value="ptsb_cycles"/>
        <input type="hidden" name="do" value="save_one"/>

    
<p class="description" style="margin-top:0">
  Selecione as partes do site a incluir no backup. Para um backup completo, mantenha todas selecionadas.
</p>


<div class="ptsb-chips" id="ptsb-add-letters" style="margin-bottom:16px">
  <label class="ptsb-chip" title="Banco de Dados">
    <input type="checkbox" checked data-letter="D">
    <span class="dashicons dashicons-database"></span> Banco de Dados
  </label>
  <label class="ptsb-chip" title="Plugins">
    <input type="checkbox" checked data-letter="P">
    <span class="dashicons dashicons-admin-plugins"></span> Plugins
  </label>
  <label class="ptsb-chip" title="Temas">
    <input type="checkbox" checked data-letter="T">
    <span class="dashicons dashicons-admin-appearance"></span> Temas
  </label>
  <label class="ptsb-chip" title="Core">
    <input type="checkbox" checked data-letter="W">
    <span class="dashicons dashicons-wordpress-alt"></span> Core
  </label>
  <label class="ptsb-chip" title="Scripts">
    <input type="checkbox" checked data-letter="S">
    <span class="dashicons dashicons-editor-code"></span> Scripts
  </label>
  <label class="ptsb-chip" title="Mídia">
    <input type="checkbox" checked data-letter="M">
    <span class="dashicons dashicons-admin-media"></span> Mídia
  </label>
  <label class="ptsb-chip" title="Outros">
    <input type="checkbox" checked data-letter="O">
    <span class="dashicons dashicons-image-filter"></span> Outros
  </label>
</div>

<script>
(function(){
  // mantém os chips dentro do form e gera letters[] no submit
  const form = document.getElementById('ptsb-add-cycle-form');
  if (!form) return;
  const wrap = form.querySelector('#ptsb-add-letters');
  if (!wrap) return;

  form.addEventListener('submit', function(){
    // limpa restos
    form.querySelectorAll('input[name="letters[]"]').forEach(i => i.remove());
    // cria letters[] pelos chips marcados
    wrap.querySelectorAll('input[type="checkbox"][data-letter]:checked').forEach(cb => {
      const h = document.createElement('input');
      h.type = 'hidden';
      h.name = 'letters[]';
      h.value = String(cb.dataset.letter || '').toUpperCase();
      form.appendChild(h);
    });
  });
})();
</script>

         <label>Nome da Rotina:
  <input type="text" name="name" value="" required aria-required="true"
         style="min-width:260px" placeholder="Ex.: Diário completo">
</label>


<label>Armazenar por quantos dias?
  <input type="number" name="keep_days" min="1" max="3650"
         placeholder="Máx: 3650" required style="width:120px">
</label>


<!-- Toggle: Sempre manter -->
<div class="ptsb-keep-toggle" style="margin-left:8px">
  <label class="ptsb-switch" title="Sempre manter">
    <input type="checkbox" name="keep_forever" value="1">
    <span class="ptsb-slider" aria-hidden="true"></span>
  </label>
  <span class="ptsb-keep-txt">Sempre manter</span>
</div>

<script>
(function(form){
  if(!form) return;
  var cb   = form.querySelector('input[name="keep_forever"]');
  var days = form.querySelector('input[name="keep_days"]');
  if(!cb || !days) return;
  function sync(){ days.disabled = cb.checked; days.style.opacity = cb.checked ? .5 : 1; }
  cb.addEventListener('change', sync); sync();
})(document.currentScript.closest('form'));
</script>


          <br>

         <label class="ptsb-section-gap">Tipo:
  <select name="mode" onchange="this.closest('form').querySelectorAll('[data-new]').forEach(el=>el.style.display='none'); this.closest('form').querySelector('[data-new='+this.value+']').style.display='';">
    <option value="daily" selected>Diário</option>
    <option value="weekly">Semanal</option>
    <option value="every_n">Recorrente</option>
    <option value="interval">Intervalo</option>
  </select>
  <script>
(function(sel){
  if(!sel) return;
  const form = sel.closest('form');
  function toggleSections(){
    const val = sel.value;
    form.querySelectorAll('[data-new],[data-sec]').forEach(box=>{
      const active = (box.getAttribute('data-new')===val) || (box.getAttribute('data-sec')===val);
      box.style.display = active ? '' : 'none';
      // desabilita/habilita TODOS inputs/ selects/ textareas da seção
      box.querySelectorAll('input, select, textarea').forEach(el=>{
        el.disabled = !active;
      });
    });
  }
  sel.addEventListener('change', toggleSections);
  toggleSections(); // inicial
})(document.currentScript.previousElementSibling);
</script>

  <script>
    (function(sel){ if(!sel) return; sel.dispatchEvent(new Event('change')); })
    (document.currentScript.previousElementSibling);
  </script>
</label>


          <div data-new="daily">
  <div class="ptsb-inline-field" style="margin-top:6px">Quantos horários por dia?</div>
  <input type="number" name="qty" min="1" max="12" value="3" style="width:80px" id="new-daily-qty">
  <div id="new-daily-times" class="ptsb-times-grid"></div>

  <script>
 (function(qId, boxId){
  var q = document.getElementById(qId), box = document.getElementById(boxId);
  if(!q || !box) return;
  function rebuild(){
    var n = Math.max(1, Math.min(12, parseInt(q.value,10)||1));
    var old = Array.from(box.querySelectorAll('input[type="time"]')).map(i=>i.value);
    box.innerHTML = '';
    for(var i=0;i<n;i++){
      var inp = document.createElement('input');
      inp.type='time'; inp.step=60; inp.name='times[]'; inp.style.width='100%';
      if(old[i]) inp.value = old[i];
      box.appendChild(inp);
    }
    // NOVO: re-aplica a habilitação da seção ativa (desabilita o resto)
    var sel = box.closest('form')?.querySelector('select[name="mode"]');
    if (sel) sel.dispatchEvent(new Event('change'));
  }
  q.addEventListener('input', rebuild);
  rebuild();
})('new-daily-qty','new-daily-times');

  </script>
</div>

          <div data-new="weekly" style="display:none">
<div class="ptsb-inline-field" style="margin-top:8px">Quantos horários por dia?</div>
  <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-weekly-qty">
  <div id="new-weekly-times" class="ptsb-times-grid"></div>
  <div>
  <p>Defina em quais dias da semana o backup será feito:</p>
</div>
<div class="ptsb-chips" id="wk_new">

  <span class="ptsb-chip" data-day="0" title="Domingo"        aria-label="Domingo">D</span>
  <span class="ptsb-chip" data-day="1" title="Segunda-feira"   aria-label="Segunda-feira">S</span>
  <span class="ptsb-chip" data-day="2" title="Terça-feira"     aria-label="Terça-feira">T</span>
  <span class="ptsb-chip" data-day="3" title="Quarta-feira"    aria-label="Quarta-feira">Q</span>
  <span class="ptsb-chip" data-day="4" title="Quinta-feira"    aria-label="Quinta-feira">Q</span>
  <span class="ptsb-chip" data-day="5" title="Sexta-feira"     aria-label="Sexta-feira">S</span>
  <span class="ptsb-chip" data-day="6" title="Sábado"          aria-label="Sábado">S</span>
</div>
<input type="text" name="wk_days_guard" id="wk_new_guard"
       style="position:absolute;left:-9999px;width:1px;height:1px" tabindex="-1"
         aria-hidden="true" disabled>




  <script>(function(wrap){
    if(!wrap) return;
    function sync(){
      const f = wrap.closest('form');
      f.querySelectorAll('input[name="wk_days[]"]').forEach(n=>n.remove());
      wrap.querySelectorAll('.ptsb-chip.active').forEach(ch=>{
        const i=document.createElement('input');
        i.type='hidden'; i.name='wk_days[]'; i.value=String(ch.dataset.day||''); f.appendChild(i);
      });
    }
    wrap.addEventListener('click', e=>{ const ch=e.target.closest('.ptsb-chip'); if(!ch) return; ch.classList.toggle('active'); sync(); });
    sync();
  })(document.getElementById('wk_new'));</script>

  


  <script>
 (function(qId, boxId){
  var q = document.getElementById(qId), box = document.getElementById(boxId);
  if(!q || !box) return;
  function rebuild(){
    var n = Math.max(1, Math.min(12, parseInt(q.value,10)||1));
    var old = Array.from(box.querySelectorAll('input[type="time"]')).map(i=>i.value);
    box.innerHTML = '';
    for(var i=0;i<n;i++){
      var inp = document.createElement('input');
      inp.type='time'; inp.step=60; inp.name='times[]'; inp.style.width='100%';
      if(old[i]) inp.value = old[i];
      box.appendChild(inp);
    }
    var sel = box.closest('form')?.querySelector('select[name="mode"]');
    if (sel) sel.dispatchEvent(new Event('change'));
  }
  q.addEventListener('input', rebuild);
  rebuild();
})('new-weekly-qty','new-weekly-times');

  </script>
</div>



         <div data-new="every_n" style="display:none">




<div class="ptsb-inline-field" style="margin-left:6px">Quantos horários por dia?</div>

  <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-everyn-qty">
<div>
<label style="margin-top:10px;display:inline-block">Repetir a cada quantos dias? <input type="number" min="1" max="30" name="n" value="3" style="width:80px"></label>
</div>
<div id="new-everyn-times" class="ptsb-times-grid"></div>


  <script>
  (function(qId, boxId){
  var q = document.getElementById(qId), box = document.getElementById(boxId);
  if(!q || !box) return;
  function rebuild(){
    var n = Math.max(1, Math.min(12, parseInt(q.value,10)||1));
    var old = Array.from(box.querySelectorAll('input[type="time"]')).map(i=>i.value);
    box.innerHTML = '';
    for(var i=0;i<n;i++){
      var inp = document.createElement('input');
      inp.type='time'; inp.step=60; inp.name='times[]'; inp.style.width='100%';
      if(old[i]) inp.value = old[i];
      box.appendChild(inp);
    }
    var sel = box.closest('form')?.querySelector('select[name="mode"]');
    if (sel) sel.dispatchEvent(new Event('change'));
  }
  q.addEventListener('input', rebuild);
  rebuild();
})('new-everyn-qty','new-everyn-times');

  </script>

  

</div>


          <div data-new="interval" style="display:none">
  <label>Repetir a cada
    <input type="number" name="every_val" value="2" min="1" style="width:48px">
    <select name="every_unit">
      <option value="minute">minuto(s)</option>
      <option value="hour"  selected>hora(s)</option>
      <option value="day">dia(s)</option> <!-- NOVO -->
    </select>
  </label>

  <!-- NOVO: toggle para desativar a janela (ligado por padrão) -->
  <label class="ptsb-keep-toggle" style="margin-left:10px" title="Ignorar início/fim; usar o dia inteiro">
    <label class="ptsb-switch" style="margin-right:6px">
      <input type="checkbox" name="win_disable" value="1" checked>
      <span class="ptsb-slider" aria-hidden="true"></span>
    </label>
    <span class="ptsb-keep-txt">Desativar janela de tempo</span>
  </label>

  <label style="margin-left:10px">Janela:
    <input type="time" name="win_start" value="08:00" style="width:120px"> –
    <input type="time" name="win_end"   value="20:00" style="width:120px">
  </label>
</div>

<script>
// NOVO: desabilita/oculta a janela quando o toggle está ligado
(function(wrap){
  if(!wrap) return;
  var dis = wrap.querySelector('input[name="win_disable"]');
  var s   = wrap.querySelector('input[name="win_start"]');
  var e   = wrap.querySelector('input[name="win_end"]');
  function sync(){
    var on = dis && dis.checked;
    [s,e].forEach(function(i){
      if(!i) return;
      i.disabled = on;
      i.style.opacity = on ? .5 : 1;
    });
  }
  dis && dis.addEventListener('change', sync);
  sync(); // padrão: ligado
})(document.currentScript.previousElementSibling);
</script>



                   <input type="hidden" name="policy_one" value="queue">
          <div style="margin-top:10px"><button class="button button-primary">Salvar rotina</button>
 <!-- Toggle "Ativar ao salvar" agora ao lado do botão -->
  <div class="ptsb-keep-toggle" title="Ativar ao salvar">
    <label class="ptsb-switch">
      <input type="checkbox" name="enabled" value="1" checked>
      <span class="ptsb-slider" aria-hidden="true"></span>
    </label>
    <span class="ptsb-keep-txt">Ativar ao salvar</span>
  </div>
</div>

          </div>
        </form>



      </div>
    </details>
  </div>

  <?php
  $cycles = ptsb_cycles_get();
  ?>
  <table class="widefat striped">

          <thead>
            <tr>
              <th>Ativo</th>
              <th>Nome</th>
              <th>Frequência</th>
              <th>Dias e Horários</th>
              <th>Backup</th>
              <th>Retenção</th>
              <th>Próx. execução.</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$cycles): ?>
              <tr><td colspan="8"><em>Nenhuma rotina ainda. Use “Adicionar rotina”.</em></td></tr>
            <?php else:
              foreach ($cycles as $c):
                $cid = esc_attr($c['id']);
                $parts_letters = array_values(array_intersect(array_map('strtoupper', (array)($c['letters']??[])), ['D','P','T','W','S','M','O']));
               $mode = strtolower($c['mode'] ?? 'daily');
if ($mode === 'daily') {
    $freq = 'Diário';
} elseif ($mode === 'weekly') {
    $freq = 'Semanal';
} elseif ($mode === 'every_n') {
    $n = max(1, (int)($c['cfg']['n'] ?? 1));
    // Ex.: "Cada 2 dias / Recorrente"
     $freq = 'Recorrente · A cada ' . $n . ' dias';
} elseif ($mode === 'interval') {
    $freq = 'Intervalo';
} else {
    $freq = ucfirst($mode);
}



$p = ptsb_cycle_params_label_ui($c);  // << usar o helper
$next1 = ptsb_cycles_next_occurrences([$c], 1);
$nx    = $next1 ? esc_html($next1[0]['dt']->format('d/m/Y H:i')) : '(—)';



                $defDays = (int)($set['keep_days'] ?? 0);
            ?>
              <tr>
                <td>
                  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field('ptsb_nonce'); ?>
                    <input type="hidden" name="action" value="ptsb_cycles"/>
                    <input type="hidden" name="do" value="toggle"/>
                    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
                    <label class="ptsb-switch">
                      <input type="checkbox" name="enabled" value="1" <?php checked(!empty($c['enabled'])); ?> onchange="this.form.submit()">
                      <span class="ptsb-slider"></span>
                    </label>
                  </form>
                </td>
                <td><strong><?php echo esc_html($c['name'] ?? ''); ?></strong></td>
                <td><?php echo esc_html($freq); ?></td>
                <td style="white-space:nowrap"><?php echo esc_html($p); ?></td>
                <td>
                  <?php foreach ($parts_letters as $L): $meta = ptsb_letter_meta($L); ?>
                    <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
                      <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td>
                  <?php
                  if (isset($c['keep_days']) && (int)$c['keep_days'] === 0) {
                    echo '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
                  } elseif (isset($c['keep_days']) && (int)$c['keep_days'] > 0) {
                    $d = (int)$c['keep_days'];
                    echo '<span class="ptsb-ret" title="'.esc_attr(sprintf('Reter por %d dias', $d)).'">'.esc_html($d).' d</span>';
                  } else {
                    echo '<span class="ptsb-ret" title="'.esc_attr(sprintf('Padrão do painel: %d dias', $defDays)).'">'.esc_html($defDays).' d</span>';
                  }
                  ?>
                </td>
              <td><?php echo $nx; ?></td>

            <td class="ptsb-actions">
  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:0"
        onsubmit="return confirm('Duplicar esta rotina?');">
    <?php wp_nonce_field('ptsb_nonce'); ?>
    <input type="hidden" name="action" value="ptsb_cycles"/>
    <input type="hidden" name="do" value="dup"/>
    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
    <button class="button">Duplicar</button>
  </form>

  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:6px"
        onsubmit="return confirm('Remover esta rotina?');">
    <?php wp_nonce_field('ptsb_nonce'); ?>
    <input type="hidden" name="action" value="ptsb_cycles"/>
    <input type="hidden" name="do" value="delete"/>
    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
    <button class="button">Remover</button>
  </form>
</td>

            
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>

        <script>
(function(){
  document.addEventListener('submit', function(ev){
    const f = ev.target;
    // só nos forms de rotinas (adicionar/editar)
    if (!f.matches('form') || !f.querySelector('input[name="action"][value="ptsb_cycles"]')) return;

    const modeSel = f.querySelector('select[name="mode"]');
    if (!modeSel) return;
    const mode = modeSel.value;

    // pega a seção ativa (nova ou editar)
    const sec = f.querySelector('[data-new="'+mode+'"],[data-sec="'+mode+'"]') || f;

    // valida horários (todos required)
    const times = sec.querySelectorAll('input[type="time"]:not([disabled])');
    for (const inp of times) {
      inp.required = true;
      if (!inp.value) { ev.preventDefault(); inp.reportValidity(); return; }
    }

    // Semanal: exige pelo menos 1 dia
    if (mode === 'weekly') {
      const guard = f.querySelector('input[name="wk_days_guard"]');
      const hasDay = !!sec.querySelector('.ptsb-chips [data-day].active');
      if (guard) {
        if (!hasDay) {
          guard.value=''; guard.setCustomValidity('Selecione pelo menos 1 dia da semana.');
          ev.preventDefault(); guard.reportValidity(); return;
        } else {
          guard.value='ok'; guard.setCustomValidity('');
        }
      }
    }
  }, true);
})();
</script>
    

          <?php elseif ($tab === 'next'): ?>

        <!-- ===== ABA: PRÓXIMAS EXECUÇÕES (lazy load via AJAX) ===== -->
        <?php
        $cycles  = ptsb_cycles_get();
        $per_default = (int) get_option('ptsb_next_per_page', 20);
        $per_next = isset($_GET['per_next']) ? (int) $_GET['per_next'] : ($per_default > 0 ? $per_default : 20);
        $per_next = max(1, min($per_next, 20));
        if (isset($_GET['per_next'])) {
            update_option('ptsb_next_per_page', $per_next, false);
        }

        $page_next = max(1, (int)($_GET['page_next'] ?? 1));

        $next_date_raw = isset($_GET['next_date']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['next_date']) : '';
        $next_date     = '';
        if ($next_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_date_raw)) {
            try {
                $dayObj    = new DateTimeImmutable($next_date_raw.' 00:00:00', ptsb_tz());
                $today0    = ptsb_now_brt()->setTime(0, 0);
                if ($dayObj < $today0) {
                    $dayObj = $today0;
                }
                $next_date = $dayObj->format('Y-m-d');
            } catch (Throwable $e) {
                $next_date = '';
            }
        }
        ?>

        <div id="ptsb-next-root"
             data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>"
             data-page="<?php echo (int) $page_next; ?>"
             data-per="<?php echo (int) $per_next; ?>"
             data-date="<?php echo esc_attr($next_date); ?>"
             data-referer="<?php echo esc_attr($referer); ?>"
             data-post="<?php echo esc_url(admin_url('admin-post.php')); ?>">

          <h2 style="margin-top:8px">Próximas Execuções</h2>

          <?php if (!$cycles): ?>
            <p><em>Sem rotinas ativas.</em></p>
          <?php else: ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 10px">
              <form method="get" id="ptsb-next-date-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:8px;margin:0">
                <input type="hidden" name="page" value="pt-simple-backup">
                <input type="hidden" name="tab"  value="next">
                <input type="hidden" name="per_next"  value="<?php echo (int) $per_next; ?>">
                <input type="hidden" name="page_next" value="1">
                <span>Ver execuções do dia:</span>
                <input type="date"
                       name="next_date"
                       value="<?php echo esc_attr($next_date); ?>"
                       min="<?php echo esc_attr( ptsb_now_brt()->format('Y-m-d') ); ?>"
                       style="width:auto">
                <?php if ($next_date): ?>
                  <button type="button" class="button button-small" data-reset-date>Limpar</button>
                <?php endif; ?>
              </form>

              <form method="get" id="ptsb-next-per-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:6px;margin:0;margin-left:auto">
                <input type="hidden" name="page" value="pt-simple-backup">
                <input type="hidden" name="tab"  value="next">
                <span>Exibindo</span>
                <input type="number" name="per_next" min="1" max="20" value="<?php echo (int) $per_next; ?>" style="width:auto">
                <span>por página</span>
              </form>
            </div>

            <table class="widefat striped" data-role="ptsb-next-table">
              <thead>
                <tr>
                  <th>Data/Hora</th>
                  <th>Rotinas</th>
                  <th>Backup</th>
                  <th>Ignorar</th>
                </tr>
              </thead>
              <tbody>
                <tr class="ptsb-loading"><td colspan="4"><em>Carregando…</em></td></tr>
              </tbody>
            </table>

            <nav class="ptsb-pager" id="ptsb-next-pager" aria-label="Paginação das próximas execuções" data-has-next="0">
              <a class="btn is-disabled" data-action="first" href="#" aria-disabled="true" title="Primeira página">
                <span class="dashicons dashicons-controls-skipback"></span>
              </a>
              <a class="btn is-disabled" data-action="prev" href="#" aria-disabled="true" title="Página anterior">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
              </a>
              <span class="status">
                <input class="current" type="number" min="1" value="<?php echo (int) $page_next; ?>">
                <span class="sep">página</span>
              </span>
              <a class="btn is-disabled" data-action="next" href="#" aria-disabled="true" title="Próxima página">
                <span class="dashicons dashicons-arrow-right-alt2"></span>
              </a>
            </nav>
          <?php endif; ?>
        </div>

      <?php elseif ($tab === 'last'): ?>

        <?php
        $per_default_l = (int) get_option('ptsb_last_per_page', 20);
        $per_last = isset($_GET['per_last']) ? (int) $_GET['per_last'] : ($per_default_l > 0 ? $per_default_l : 20);
        $per_last = max(1, min($per_last, 20));
        if (isset($_GET['per_last'])) {
            update_option('ptsb_last_per_page', $per_last, false);
        }

        $page_last = max(1, (int)($_GET['page_last'] ?? 1));
        $last_exp  = isset($_GET['last_exp']) ? (int) !!$_GET['last_exp'] : 1;
        $last_ok   = isset($_GET['last_ok'])  ? (int) !!$_GET['last_ok']  : 1;
        ?>

        <div id="ptsb-last-root"
             data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>"
             data-page="<?php echo (int) $page_last; ?>"
             data-per="<?php echo (int) $per_last; ?>"
             data-exp="<?php echo (int) $last_exp; ?>"
             data-ok="<?php echo (int) $last_ok; ?>"
             data-referer="<?php echo esc_attr($referer); ?>"
             data-post="<?php echo esc_url(admin_url('admin-post.php')); ?>">

          <h2 style="margin-top:8px">Últimas execuções</h2>

          <div class="ptsb-toolbar" style="display:inline-flex;gap:12px;flex-wrap:wrap;align-items:center;margin:8px 0 10px">
            <form method="get" id="ptsb-last-filter-form" class="ptsb-list-controls" style="margin:0">
              <input type="hidden" name="page" value="pt-simple-backup">
              <input type="hidden" name="tab"  value="last">
              <input type="hidden" name="per_last" value="<?php echo (int) $per_last; ?>">
              <input type="hidden" name="page_last" value="1">
              <label style="display:inline-flex;align-items:center;gap:6px">
                <input type="checkbox" name="last_exp" value="1" <?php checked($last_exp); ?>>
                <span>Mostrar vencidos</span>
              </label>
              <label style="display:inline-flex;align-items:center;gap:6px">
                <input type="checkbox" name="last_ok" value="1" <?php checked($last_ok); ?>>
                <span>Mostrar em dia</span>
              </label>
            </form>

            <form method="get" id="ptsb-last-per-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:6px;margin:0;margin-left:auto">
              <input type="hidden" name="page" value="pt-simple-backup">
              <input type="hidden" name="tab"  value="last">
              <input type="hidden" name="page_last" value="1">
              <input type="hidden" name="last_exp" value="<?php echo (int) $last_exp; ?>">
              <input type="hidden" name="last_ok"  value="<?php echo (int) $last_ok; ?>">
              <span>Exibindo</span>
              <input type="number" name="per_last" min="1" max="20" value="<?php echo (int) $per_last; ?>" style="width:auto">
              <span class="ptsb-last-total">carregando…</span>
            </form>
          </div>

          <table class="widefat striped" data-role="ptsb-last-table">
            <thead>
              <tr>
                <th>Data/Hora</th>
                <th>Arquivo</th>
                <th>Rotina</th>
                <th>Backup</th>
                <th>Retenção</th>
                <th>Tamanho</th>
              </tr>
            </thead>
            <tbody>
              <tr class="ptsb-loading"><td colspan="6"><em>Carregando…</em></td></tr>
            </tbody>
          </table>

          <nav class="ptsb-pager" id="ptsb-last-pager" aria-label="Paginação das últimas execuções" data-total-pages="1">
            <a class="btn is-disabled" data-action="first" href="#" aria-disabled="true" title="Primeira página">
              <span class="dashicons dashicons-controls-skipback"></span>
            </a>
            <a class="btn is-disabled" data-action="prev" href="#" aria-disabled="true" title="Página anterior">
              <span class="dashicons dashicons-arrow-left-alt2"></span>
            </a>
            <span class="status">
              <input class="current" type="number" min="1" value="<?php echo (int) $page_last; ?>">
              <span class="sep">de</span>
              <span class="total">1</span>
            </span>
            <a class="btn is-disabled" data-action="next" href="#" aria-disabled="true" title="Próxima página">
              <span class="dashicons dashicons-arrow-right-alt2"></span>
            </a>
            <a class="btn is-disabled" data-action="last" href="#" aria-disabled="true" title="Última página">
              <span class="dashicons dashicons-controls-skipforward"></span>
            </a>
          </nav>
        </div>

<?php elseif ($tab === 'settings'): ?>

  <!-- ===== ABA: CONFIGURAÇÕES ===== -->
  <h2 style="margin-top:8px">Log</h2>



  <?php $init_log = ptsb_tail_log_raw($cfg['log'], 50); ?>

  <p style="opacity:.7;margin:.3em 0 1em">
        Status: <?php echo esc_html(implode(' | ', $diag)); ?>
      </p>

  <div style="display:flex;align-items:center;gap:8px;margin:10px 0 6px">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
          onsubmit="return confirm('Limpar todo o log (incluindo rotações)?');" style="margin-left:auto">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="clear_log"/>
      <button class="button">Limpar log</button>
    </form>
  </div>

  <pre id="ptsb-log" style="max-height:420px;overflow:auto;padding:10px;background:#111;border:1px solid #444;border-radius:4px;"><?php 
      echo esc_html($init_log ?: '(sem linhas)'); 
  ?></pre>
  <p><small>Mostrando as últimas 50 linhas. A rotação cria <code>backup-wp.log.1</code>, <code>.2</code>… até <?php echo (int)$cfg['log_keep']; ?>.</small></p>

  <script>
  (function(){
    const ajaxUrl = window.ajaxurl || "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
    const nonce   = "<?php echo esc_js($nonce); ?>";
    const logEl   = document.getElementById('ptsb-log');
    if(!logEl) return;

    let lastLog = logEl.textContent || '';
    let autoStick = true;
    logEl.addEventListener('scroll', function(){
      const nearBottom = (logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight) < 24;
      autoStick = nearBottom;
    });

    function renderLog(txt){
      if(txt === lastLog) return;
      const shouldStick = autoStick;
      logEl.textContent = txt;
      if(shouldStick){ requestAnimationFrame(()=>{ logEl.scrollTop = logEl.scrollHeight; }); }
      lastLog = txt;
    }

    function poll(){
      const body = new URLSearchParams({action:'ptsb_status', nonce:nonce}).toString();
      fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body})
        .then(r=>r.json()).then(res=>{
          if(!res || !res.success) return;
          const s   = res.data || {};
          const txt = (s.log && String(s.log).trim()) ? s.log : '(sem linhas)';
          renderLog(txt);
        }).catch(()=>{});
    }
    poll(); setInterval(poll, 2000);
  })();
  </script>

      <?php endif; // fim roteamento abas ?>
    </div>
    <?php
    settings_errors('ptsb');
}

