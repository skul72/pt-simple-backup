<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_management_page(
        'Backup',
        'Backup',
        'manage_options',
        'pt-simple-backup',
        'ptsb_render_backup_page'
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'tools_page_pt-simple-backup') {
        return;
    }

    wp_enqueue_style('dashicons');

    if (defined('PTSB_PLUGIN_URL')) {
        $base = trailingslashit(PTSB_PLUGIN_URL) . 'assets/';
        wp_enqueue_style('ptsb-admin', $base . 'admin.css', ['dashicons'], PTSB_PLUGIN_VERSION ?? null);
        wp_enqueue_script('ptsb-admin', $base . 'admin.js', ['jquery'], PTSB_PLUGIN_VERSION ?? null, true);
    }
});

add_action('load-tools_page_pt-simple-backup', function () {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCDN')) {
        define('DONOTCDN', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }

    $force = isset($_GET['force']) && (int) $_GET['force'] === 1;
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

function ptsb_cycle_params_label_ui(array $cycle): string
{
    $mode = (string) ($cycle['mode'] ?? 'daily');
    $cfg  = is_array($cycle['cfg'] ?? null) ? $cycle['cfg'] : [];

    $times = [];
    if (!empty($cfg['times']) && is_array($cfg['times'])) {
        $times = array_map('strval', $cfg['times']);
    }
    if (!empty($cfg['time'])) {
        $times[] = (string) $cfg['time'];
    }
    $times = array_values(array_filter($times, static function ($time) {
        return trim($time) !== '';
    }));

    if (function_exists('ptsb_times_sort_unique')) {
        $times = ptsb_times_sort_unique($times);
    }

    if ($mode === 'daily') {
        return $times ? implode(', ', $times) : '—';
    }

    if ($mode === 'weekly') {
        $labels = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        $days   = array_map('intval', $cfg['days'] ?? []);
        $days   = array_values(array_filter($days, static function ($day) {
            return $day >= 0 && $day <= 6;
        }));
        $dias  = $days ? implode(', ', array_map(static function ($day) use ($labels) {
            return $labels[$day];
        }, $days)) : '—';
        $horas = $times ? implode(', ', $times) : '—';

        return "{$dias} · {$horas}";
    }

    if ($mode === 'every_n') {
        return $times ? implode(', ', $times) : '—';
    }

    if ($mode === 'interval') {
        $unit  = strtolower((string) ($cfg['every']['unit'] ?? 'minute'));
        $value = (int) ($cfg['every']['value'] ?? 1);
        $unitLabel = $unit === 'day'
            ? 'dia(s)'
            : ($unit === 'hour' ? 'hora(s)' : 'minuto(s)');

        $windowEnabled = empty($cfg['win']['disabled']);
        $windowText    = $windowEnabled
            ? sprintf(
                '%s–%s',
                (string) ($cfg['win']['start'] ?? '00:00'),
                (string) ($cfg['win']['end'] ?? '23:59')
            )
            : '';

        $base = sprintf('cada %d %s', $value, $unitLabel);
        return $windowEnabled ? $base . ' · ' . $windowText : $base;
    }

    return '—';
}

function ptsb_render_backup_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $cfg        = ptsb_cfg();
    $settings   = ptsb_settings();
    $forceList  = isset($_GET['force']) && (int) $_GET['force'] === 1;
    $allowedTab = ['backup', 'cycles', 'next', 'last', 'settings'];
    $tab        = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backup';

    if (!in_array($tab, $allowedTab, true)) {
        $tab = 'backup';
    }

    $titles = [
        'backup'   => 'Backups (Google Drive)',
        'cycles'   => 'Rotinas de Backup',
        'next'     => 'Próximas Execuções',
        'last'     => 'Últimas Execuções',
        'settings' => 'Configurações',
    ];

    $diagnostics = [
        'shell_exec ' . (ptsb_can_shell() ? 'OK' : 'DESABILITADO'),
        'log ' . (ptsb_is_readable($cfg['log']) ? 'legível' : 'sem leitura'),
        'backup.sh ' . (@is_executable($cfg['script_backup']) ? 'executável' : 'sem permissão'),
        'restore.sh ' . (@is_executable($cfg['script_restore']) ? 'executável' : 'sem permissão'),
    ];

    $nonce   = wp_create_nonce('ptsb_nonce');
    $referer = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

    $drive = ptsb_drive_info();

    if ($forceList) {
        delete_transient('ptsb_totals_v1');
    }

    $totals        = ptsb_backups_totals_cached();
    $backupCount   = (int) ($totals['count'] ?? 0);
    $backupBytes   = (int) ($totals['bytes'] ?? 0);
    $usedStr       = $drive['used'] !== null ? ptsb_hsize_compact($drive['used']) : '?';
    $totalStr      = $drive['total'] !== null ? ptsb_hsize_compact($drive['total']) : '?';
    $backupSummary = sprintf(
        '%s %s / %s',
        number_format_i18n($backupCount),
        $backupCount === 1 ? 'item' : 'itens',
        ptsb_hsize_compact($backupBytes)
    );

    $baseUrl = admin_url('tools.php?page=pt-simple-backup');
    $tabs    = [
        'backup'   => 'Backup',
        'cycles'   => 'Rotinas de Backup',
        'next'     => 'Próximas Execuções',
        'last'     => 'Últimas Execuções',
        'settings' => 'Configurações',
    ];

    $partsMeta = [
        'D' => ['dashicons-database', 'Banco de Dados'],
        'P' => ['dashicons-admin-plugins', 'Plugins'],
        'T' => ['dashicons-admin-appearance', 'Temas'],
        'W' => ['dashicons-wordpress-alt', 'Core'],
        'S' => ['dashicons-editor-code', 'Scripts'],
        'M' => ['dashicons-admin-media', 'Mídia'],
        'O' => ['dashicons-image-filter', 'Outros'],
    ];
    ?>
    <div class="wrap">
        <h1><?php echo esc_html($titles[$tab]); ?></h1>
        <p style="opacity:.7;margin:.3em 0 1em">
            Armazenamento: <strong><?php echo esc_html($usedStr . ' / ' . $totalStr); ?></strong> |
            Backups no Drive: <strong><?php echo esc_html($backupSummary); ?></strong>
        </p>

        <h2 class="nav-tab-wrapper" style="margin-top:8px">
            <?php foreach ($tabs as $slug => $label) :
                $url   = esc_url(add_query_arg('tab', $slug, $baseUrl));
                $class = 'nav-tab' . ($tab === $slug ? ' nav-tab-active' : '');
                ?>
                <a class="<?php echo esc_attr($class); ?>" href="<?php echo $url; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </h2>

        <?php if ($tab === 'backup') :
            $rows    = ptsb_list_remote_files($forceList);
            $keepers = ptsb_keep_map();

            $dumpRemoteDir = ptsb_db_dump_remote_dir($cfg);
            $dumpTarget    = $cfg['remote'];
            if ($dumpRemoteDir !== '') {
                $dumpPrefix = $cfg['remote'];
                $lastChar   = substr($dumpPrefix, -1);
                if ($lastChar !== ':' && $lastChar !== '/') {
                    $dumpPrefix .= '/';
                }
                $dumpTarget = $dumpPrefix . $dumpRemoteDir;
            }

            $total      = count($rows);
            $perDefault = (int) get_option('ptsb_list_per_page', 25);
            $per        = isset($_GET['per']) ? (int) $_GET['per'] : ($perDefault > 0 ? $perDefault : 25);
            $per        = max(1, min($per, 500));
            if (isset($_GET['per'])) {
                update_option('ptsb_list_per_page', $per, false);
            }
            $paged      = max(1, (int) ($_GET['paged'] ?? 1));
            $totalPages = max(1, (int) ceil($total / $per));
            if ($paged > $totalPages) {
                $paged = $totalPages;
            }
            $offset   = ($paged - 1) * $per;
            $rowsPage = array_slice($rows, $offset, $per);

            $makeUrl = static function ($page) use ($per) {
                return esc_url(add_query_arg(
                    [
                        'page'  => 'pt-simple-backup',
                        'tab'   => 'backup',
                        'per'   => $per,
                        'paged' => (int) $page,
                    ],
                    admin_url('tools.php')
                ));
            };
            ?>

            <h2 style="margin-top:24px !important">Fazer Backup</h2>
            <p class="description">Escolha quais partes do site incluir no backup. Para um backup completo, mantenha todos selecionados.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ptsb-now-form" style="margin:12px 0;">
                <?php wp_nonce_field('ptsb_nonce'); ?>
                <input type="hidden" name="action" value="ptsb_do">
                <input type="hidden" name="ptsb_action" value="backup_now">
                <input type="hidden" name="parts_sel[]" value="" id="ptsb-parts-hidden-sentinel">

                <div class="ptsb-chips" id="ptsb-chips">
                    <?php foreach ($partsMeta as $letter => $meta) :
                        [$icon, $label] = $meta;
                        ?>
                        <label class="ptsb-chip" data-letter="<?php echo esc_attr($letter); ?>">
                            <input type="checkbox" checked data-letter="<?php echo esc_attr($letter); ?>">
                            <span class="dashicons <?php echo esc_attr($icon); ?>"></span> <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 2px">
                    <label>Nome do backup:
                        <input type="text" name="manual_name" placeholder="Opcional" style="min-width:280px">
                    </label>
                    <label>Armazenar por quantos dias?
                        <input type="number" name="manual_keep_days" min="1" max="3650" placeholder="Máx: 3650" required style="width:120px">
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

            <div id="ptsb-progress" style="display:none;margin:16px 0;border:1px solid #444;background:#1b1b1b;height:22px;position:relative;border-radius:4px;overflow:hidden;">
                <div id="ptsb-progress-bar" style="height:100%;width:5%;background:#2271b1;transition:width .4s ease"></div>
                <div id="ptsb-progress-text" style="position:absolute;left:8px;top:0;height:100%;line-height:22px;color:#fff;opacity:.9;font-size:12px;">Iniciando…</div>
            </div>

            <h3 style="margin-top:28px;">Dump do Banco (SQL)</h3>
            <p class="description">
                Gera um arquivo <code>.sql.gz</code> com <code>mysqldump --single-transaction --quick</code> em segundo plano e envia para <strong><?php echo esc_html($dumpTarget); ?></strong>.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field('ptsb_nonce'); ?>
                <input type="hidden" name="action" value="ptsb_do">
                <input type="hidden" name="ptsb_action" value="db_dump">
                <label>Apelido (opcional)
                    <input type="text" name="db_nick" placeholder="Ex.: relatorio" style="min-width:220px">
                </label>
                <button class="button" <?php disabled(!ptsb_can_shell()); ?>>Gerar dump SQL</button>
                <?php if (!ptsb_can_shell()) : ?>
                    <span class="description">Requer <code>shell_exec</code> habilitado.</span>
                <?php endif; ?>
            </form>

            <h2 style="margin-top:24px !important">
                Arquivos no Google Drive
                <a class="button button-small" style="margin-left:8px" href="<?php echo esc_url(add_query_arg(['force' => 1], $baseUrl)); ?>">Forçar atualizar</a>
            </h2>

            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0 12px">
                <form method="get" id="ptsb-per-form" class="ptsb-list-controls" style="margin:0">
                    <input type="hidden" name="page" value="pt-simple-backup">
                    <input type="hidden" name="tab" value="backup">
                    <input type="hidden" name="paged" value="1">
                    <span>Exibindo</span>
                    <input type="number" name="per" min="1" max="500" value="<?php echo (int) $per; ?>" style="width:auto">
                    <span>por página</span>
                </form>
                <span style="opacity:.7">Total: <?php echo esc_html(number_format_i18n($total)); ?> backups</span>
            </div>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Arquivo</th>
                        <th>Rotina</th>
                        <th>Backup</th>
                        <th>Retenção</th>
                        <th>Tamanho</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($total === 0) : ?>
                        <tr><td colspan="7"><em>Nenhum backup encontrado.</em></td></tr>
                    <?php else :
                        foreach ($rowsPage as $row) :
                            $file    = (string) ($row['file'] ?? '');
                            $timeIso = (string) ($row['time'] ?? '');
                            $size    = (int) ($row['size'] ?? 0);
                            if ($file === '') {
                                continue;
                            }
                            $isKept = !empty($keepers[$file]);
                            ?>
                            <tr data-file="<?php echo esc_attr($file); ?>" data-time="<?php echo esc_attr($timeIso); ?>" data-kept="<?php echo $isKept ? 1 : 0; ?>">
                                <td><?php echo esc_html(ptsb_fmt_local_dt($timeIso)); ?></td>
                                <td>
                                    <span class="ptsb-filename"><?php echo esc_html($file); ?></span>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ptsb-rename-form" style="display:inline">
                                        <?php wp_nonce_field('ptsb_nonce'); ?>
                                        <input type="hidden" name="action" value="ptsb_do">
                                        <input type="hidden" name="ptsb_action" value="rename">
                                        <input type="hidden" name="old_file" value="<?php echo esc_attr($file); ?>">
                                        <input type="hidden" name="new_file" value="">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr($referer); ?>">
                                        <button type="button" class="ptsb-rename-btn" title="Renomear" data-old="<?php echo esc_attr($file); ?>">
                                            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                            <span class="screen-reader-text">Renomear</span>
                                        </button>
                                    </form>
                                </td>
                                <td class="ptsb-col-rotina"><span class="description">carregando…</span></td>
                                <td class="ptsb-col-letters" aria-label="Partes incluídas"><span class="description">carregando…</span></td>
                                <td class="ptsb-col-ret"><span class="description">carregando…</span></td>
                                <td><?php echo esc_html(ptsb_hsize($size)); ?></td>
                                <td class="ptsb-actions">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:0;" onsubmit="return confirm('Restaurar <?php echo esc_js($file); ?>? Isso vai sobrescrever arquivos e banco.');">
                                        <?php wp_nonce_field('ptsb_nonce'); ?>
                                        <input type="hidden" name="action" value="ptsb_do">
                                        <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr($referer); ?>">
                                        <button class="button button-secondary" name="ptsb_action" value="restore" <?php disabled(!ptsb_can_shell()); ?>>Restaurar</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:6px;" onsubmit="return confirm('Apagar DEFINITIVAMENTE do Drive: <?php echo esc_js($file); ?>?');">
                                        <?php wp_nonce_field('ptsb_nonce'); ?>
                                        <input type="hidden" name="action" value="ptsb_do">
                                        <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr($referer); ?>">
                                        <button class="button" name="ptsb_action" value="delete" <?php disabled(!ptsb_can_shell() || $isKept); ?> <?php echo $isKept ? 'title="Desative &quot;Sempre manter&quot; antes de apagar"' : ''; ?>>Apagar</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ptsb-keep-form">
                                        <?php wp_nonce_field('ptsb_nonce'); ?>
                                        <input type="hidden" name="action" value="ptsb_do">
                                        <input type="hidden" name="ptsb_action" value="keep_toggle">
                                        <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr($referer); ?>">
                                        <div class="ptsb-keep-toggle">
                                            <label class="ptsb-switch" title="<?php echo $isKept ? 'Desativar' : 'Ativar'; ?>">
                                                <input type="checkbox" name="keep" value="1" <?php checked($isKept); ?> onchange="this.form.submit()">
                                                <span class="ptsb-slider" aria-hidden="true"></span>
                                            </label>
                                            <span class="ptsb-keep-txt">Sempre manter</span>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1) : ?>
                <nav class="ptsb-pager" style="margin-top:12px" aria-label="Paginação da lista de backups">
                    <a class="btn <?php echo $paged <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo $paged > 1 ? $makeUrl(1) : '#'; ?>" aria-disabled="<?php echo $paged <= 1 ? 'true' : 'false'; ?>" title="Primeira página">
                        <span class="dashicons dashicons-controls-skipback"></span>
                    </a>
                    <a class="btn <?php echo $paged <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo $paged > 1 ? $makeUrl($paged - 1) : '#'; ?>" aria-disabled="<?php echo $paged <= 1 ? 'true' : 'false'; ?>" title="Página anterior">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </a>
                    <span class="status">
                        <input id="ptsb-pager-input" class="current" type="number" min="1" max="<?php echo (int) $totalPages; ?>" value="<?php echo (int) $paged; ?>">
                        <span class="sep">de</span>
                        <span class="total"><?php echo (int) $totalPages; ?></span>
                    </span>
                    <a class="btn <?php echo $paged >= $totalPages ? 'is-disabled' : ''; ?>" href="<?php echo $paged < $totalPages ? $makeUrl($paged + 1) : '#'; ?>" aria-disabled="<?php echo $paged >= $totalPages ? 'true' : 'false'; ?>" title="Próxima página">
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </a>
                    <a class="btn <?php echo $paged >= $totalPages ? 'is-disabled' : ''; ?>" href="<?php echo $paged < $totalPages ? $makeUrl($totalPages) : '#'; ?>" aria-disabled="<?php echo $paged >= $totalPages ? 'true' : 'false'; ?>" title="Última página">
                        <span class="dashicons dashicons-controls-skipforward"></span>
                    </a>
                </nav>
            <?php endif; ?>
            <script>
                (function(){
                    var f = document.getElementById('ptsb-per-form');
                    if (!f) {
                        return;
                    }
                    var input = f.querySelector('input[name="per"]');
                    if (!input) {
                        return;
                    }
                    input.addEventListener('change', function(){
                        f.submit();
                    });
                })();

                (function(){
                    var pager = document.getElementById('ptsb-pager-input');
                    if (!pager) {
                        return;
                    }
                    function go(){
                        var min = parseInt(pager.min, 10) || 1;
                        var max = parseInt(pager.max, 10) || 1;
                        var val = Math.max(min, Math.min(max, parseInt(pager.value, 10) || min));
                        var url = '<?php echo esc_js($makeUrl('__P__')); ?>';
                        location.href = url.replace('__P__', val);
                    }
                    pager.addEventListener('change', go);
                    pager.addEventListener('keyup', function(ev){
                        if (ev.key === 'Enter') {
                            go();
                        }
                    });
                })();

                (function(){
                    var chipsBox = document.getElementById('ptsb-chips');
                    var formNow  = document.getElementById('ptsb-now-form');
                    if (!chipsBox || !formNow) {
                        return;
                    }
                    function getActiveLetters(){
                        var selected = chipsBox.querySelectorAll('input[type="checkbox"][data-letter]:checked');
                        return Array.from(selected).map(function(el){
                            return String(el.dataset.letter || '').toUpperCase();
                        });
                    }
                    formNow.addEventListener('submit', function(){
                        var sentinel = document.getElementById('ptsb-parts-hidden-sentinel');
                        if (sentinel && sentinel.parentNode) {
                            sentinel.parentNode.removeChild(sentinel);
                        }
                        formNow.querySelectorAll('input[name="parts_sel[]"]').forEach(function(el){ el.remove(); });
                        var letters = getActiveLetters();
                        if (!letters.length) {
                            letters = ['D','P','T','W','S','M','O'];
                        }
                        letters.forEach(function(letter){
                            var hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'parts_sel[]';
                            hidden.value = letter;
                            formNow.appendChild(hidden);
                        });
                    });
                })();

                (function(){
                    var cb = document.getElementById('ptsb-man-keep-forever');
                    var days = document.querySelector('#ptsb-now-form input[name="manual_keep_days"]');
                    if (!cb || !days) {
                        return;
                    }
                    function sync(){
                        days.disabled = cb.checked;
                        days.style.opacity = cb.checked ? 0.5 : 1;
                    }
                    cb.addEventListener('change', sync);
                    sync();
                })();

                (function(){
                    var ajaxUrl = window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                    var nonce   = '<?php echo esc_js($nonce); ?>';
                    var barBox  = document.getElementById('ptsb-progress');
                    var bar     = document.getElementById('ptsb-progress-bar');
                    var text    = document.getElementById('ptsb-progress-text');
                    if (!barBox || !bar || !text) {
                        return;
                    }
                    var wasRunning = false;
                    var didReload  = false;
                    function poll(){
                        var body = new URLSearchParams({action:'ptsb_status', nonce:nonce}).toString();
                        fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
                            .then(function(res){ return res.json(); })
                            .then(function(res){
                                if (!res || !res.success) {
                                    return;
                                }
                                var data = res.data || {};
                                if (data.running) {
                                    wasRunning = true;
                                    barBox.style.display = 'block';
                                    var pct = Math.max(5, Math.min(100, data.percent|0));
                                    bar.style.width = pct + '%';
                                    text.textContent = pct < 100 ? (pct + '% - ' + (data.stage || 'executando…')) : '100%';
                                } else {
                                    if (wasRunning && (data.percent|0) >= 100 && !didReload) {
                                        didReload = true;
                                        bar.style.width = '100%';
                                        text.textContent = '100% - concluído';
                                        setTimeout(function(){ location.reload(); }, 1200);
                                    } else {
                                        barBox.style.display = 'none';
                                    }
                                    wasRunning = false;
                                }
                            })
                            .catch(function(){});
                    }
                    poll();
                    setInterval(poll, 2000);
                })();

                (function(){
                    document.addEventListener('click', function(ev){
                        var btn = ev.target.closest('.ptsb-rename-btn');
                        if (!btn) {
                            return;
                        }
                        var form = btn.closest('form.ptsb-rename-form');
                        if (!form) {
                            return;
                        }
                        var oldFull = btn.getAttribute('data-old') || '';
                        var prefix  = '<?php echo esc_js(ptsb_cfg()['prefix']); ?>';
                        var currentNick = oldFull.replace(new RegExp('^'+prefix), '').replace(/\.tar\.gz$/i, '');
                        var nick = window.prompt('Novo apelido (apenas a parte entre "'+prefix+'" e ".tar.gz"): ', currentNick);
                        if (nick === null) {
                            return;
                        }
                        nick = (nick || '').trim().replace(/\.tar\.gz$/i, '').replace(new RegExp('^'+prefix), '').replace(/[^A-Za-z0-9._-]+/g, '-');
                        if (!nick) {
                            alert('Apelido inválido.');
                            return;
                        }
                        var newFull = prefix + nick + '.tar.gz';
                        if (newFull === oldFull) {
                            alert('O nome não foi alterado.');
                            return;
                        }
                        if (!/^[A-Za-z0-9._-]+\.tar\.gz$/.test(newFull)) {
                            alert('Use apenas letras, números, ponto, hífen e sublinhado. A extensão deve ser .tar.gz.');
                            return;
                        }
                        form.querySelector('input[name="new_file"]').value = newFull;
                        form.submit();
                    });
                })();
            </script>
        <?php elseif ($tab === 'cycles') :
            $cycles = ptsb_cycles_get();
            ?>

            <h2 style="margin-top:22px">Rotinas de backup</h2>
            <div style="margin:10px 0 14px;">
                <details>
                    <summary class="button button-primary">Adicionar rotina</summary>
                    <div style="padding:10px 0">
                        <form id="ptsb-add-cycle-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('ptsb_nonce'); ?>
                            <input type="hidden" name="action" value="ptsb_cycles">
                            <input type="hidden" name="do" value="save_one">

                            <p class="description" style="margin-top:0">Selecione as partes do site a incluir no backup. Para um backup completo, mantenha todas selecionadas.</p>

                            <div class="ptsb-chips" id="ptsb-add-letters" style="margin-bottom:16px">
                                <?php foreach ($partsMeta as $letter => $meta) :
                                    [$icon, $label] = $meta;
                                    ?>
                                    <label class="ptsb-chip" title="<?php echo esc_attr($label); ?>">
                                        <input type="checkbox" checked data-letter="<?php echo esc_attr($letter); ?>">
                                        <span class="dashicons <?php echo esc_attr($icon); ?>"></span> <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <label>Nome da rotina:
                                <input type="text" name="name" required style="min-width:280px">
                            </label>

                            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">
                                <label>Guardar por quantos dias?
                                    <input type="number" name="keep_days" min="1" max="3650" placeholder="Máx: 3650" required style="width:120px">
                                </label>
                                <div class="ptsb-keep-toggle" style="margin-left:8px">
                                    <label class="ptsb-switch" title="Sempre manter">
                                        <input type="checkbox" name="keep_forever" value="1">
                                        <span class="ptsb-slider" aria-hidden="true"></span>
                                    </label>
                                    <span class="ptsb-keep-txt">Sempre manter</span>
                                </div>
                                <script>
                                    (function(form){
                                        if (!form) {
                                            return;
                                        }
                                        var cb   = form.querySelector('input[name="keep_forever"]');
                                        var days = form.querySelector('input[name="keep_days"]');
                                        if (!cb || !days) {
                                            return;
                                        }
                                        function sync(){
                                            days.disabled = cb.checked;
                                            days.style.opacity = cb.checked ? 0.5 : 1;
                                        }
                                        cb.addEventListener('change', sync);
                                        sync();
                                    })(document.currentScript.closest('form'));
                                </script>
                            </div>

                            <label class="ptsb-section-gap">Tipo:
                                <select name="mode">
                                    <option value="daily" selected>Diário</option>
                                    <option value="weekly">Semanal</option>
                                    <option value="every_n">Recorrente</option>
                                    <option value="interval">Intervalo</option>
                                </select>
                            </label>

                            <div data-new="daily">
                                <div class="ptsb-inline-field" style="margin-top:6px">Quantos horários por dia?</div>
                                <input type="number" name="qty" min="1" max="12" value="3" style="width:80px" id="new-daily-qty">
                                <div id="new-daily-times" class="ptsb-times-grid"></div>
                            </div>

                            <div data-new="weekly" style="display:none">
                                <div class="ptsb-inline-field" style="margin-top:8px">Quantos horários por dia?</div>
                                <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-weekly-qty">
                                <div id="new-weekly-times" class="ptsb-times-grid"></div>
                                <p>Defina em quais dias da semana o backup será feito:</p>
                                <div class="ptsb-chips" id="wk_new">
                                    <span class="ptsb-chip" data-day="0" title="Domingo" aria-label="Domingo">D</span>
                                    <span class="ptsb-chip" data-day="1" title="Segunda-feira" aria-label="Segunda-feira">S</span>
                                    <span class="ptsb-chip" data-day="2" title="Terça-feira" aria-label="Terça-feira">T</span>
                                    <span class="ptsb-chip" data-day="3" title="Quarta-feira" aria-label="Quarta-feira">Q</span>
                                    <span class="ptsb-chip" data-day="4" title="Quinta-feira" aria-label="Quinta-feira">Q</span>
                                    <span class="ptsb-chip" data-day="5" title="Sexta-feira" aria-label="Sexta-feira">S</span>
                                    <span class="ptsb-chip" data-day="6" title="Sábado" aria-label="Sábado">S</span>
                                </div>
                                <input type="text" name="wk_days_guard" id="wk_new_guard" style="position:absolute;left:-9999px;width:1px;height:1px" tabindex="-1" aria-hidden="true" disabled>
                            </div>

                            <div data-new="every_n" style="display:none">
                                <div class="ptsb-inline-field" style="margin-top:8px">Executar a cada quantos dias?</div>
                                <input type="number" name="n" min="1" max="365" value="1" style="width:100px">
                                <div class="ptsb-inline-field" style="margin-top:8px">Horários por dia</div>
                                <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-every-qty">
                                <div id="new-every-times" class="ptsb-times-grid"></div>
                            </div>

                            <div data-new="interval" style="display:none">
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <label>Cada
                                        <input type="number" name="every_value" min="1" max="1440" value="60" style="width:100px">
                                    </label>
                                    <label>Unidade
                                        <select name="every_unit">
                                            <option value="minute">minuto(s)</option>
                                            <option value="hour">hora(s)</option>
                                            <option value="day">dia(s)</option>
                                        </select>
                                    </label>
                                </div>
                                <details style="margin-top:10px" open>
                                    <summary>Janela de execução</summary>
                                    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px" data-sec="interval">
                                        <label>Início
                                            <input type="time" name="win_start" value="00:00">
                                        </label>
                                        <label>Fim
                                            <input type="time" name="win_end" value="23:59">
                                        </label>
                                        <label class="ptsb-inline-field" style="display:flex;align-items:center;gap:6px">
                                            <input type="checkbox" name="win_disable" value="1">
                                            <span>Desativar janela</span>
                                        </label>
                                    </div>
                                </details>
                            </div>

                            <div style="margin-top:14px">
                                <button class="button button-primary">Salvar rotina</button>
                            </div>
                        </form>
                    </div>
                </details>
            </div>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Ativo</th>
                        <th>Nome</th>
                        <th>Frequência</th>
                        <th>Dias e Horários</th>
                        <th>Backup</th>
                        <th>Retenção</th>
                        <th>Próx. execução</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$cycles) : ?>
                        <tr><td colspan="8"><em>Nenhuma rotina ainda. Use “Adicionar rotina”.</em></td></tr>
                    <?php else :
                        foreach ($cycles as $cycle) :
                            $cid           = esc_attr($cycle['id']);
                            $letters       = array_values(array_intersect(array_map('strtoupper', (array) ($cycle['letters'] ?? [])), array_keys($partsMeta)));
                            $mode          = strtolower((string) ($cycle['mode'] ?? 'daily'));
                            $keepOverride  = isset($cycle['keep_days']) ? (int) $cycle['keep_days'] : null;
                            $defaultKeep   = (int) ($settings['keep_days'] ?? 0);
                            $freqLabel     = match ($mode) {
                                'daily'    => 'Diário',
                                'weekly'   => 'Semanal',
                                'every_n'  => 'Recorrente · A cada ' . max(1, (int) ($cycle['cfg']['n'] ?? 1)) . ' dias',
                                'interval' => 'Intervalo',
                                default    => ucfirst($mode),
                            };
                            $paramsLabel = ptsb_cycle_params_label_ui($cycle);
                            $nextOcc     = ptsb_cycles_next_occurrences([$cycle], 1);
                            $nextLabel   = $nextOcc ? esc_html($nextOcc[0]['dt']->format('d/m/Y H:i')) : '(—)';
                            ?>
                            <tr>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('ptsb_nonce'); ?>
                                        <input type="hidden" name="action" value="ptsb_cycles">
                                        <input type="hidden" name="do" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $cid; ?>">
                                        <label class="ptsb-switch">
                                            <input type="checkbox" name="enabled" value="1" <?php checked(!empty($cycle['enabled'])); ?> onchange="this.form.submit()">
                                            <span class="ptsb-slider"></span>
                                        </label>
                                    </form>
                                </td>
                                <td><strong><?php echo esc_html($cycle['name'] ?? ''); ?></strong></td>
                                <td><?php echo esc_html($freqLabel); ?></td>
                                <td style="white-space:nowrap"><?php echo esc_html($paramsLabel); ?></td>
                                <td>
                                    <?php foreach ($letters ?: array_keys($partsMeta) as $letter) :
                                        $meta = ptsb_letter_meta($letter);
                                        ?>
                                        <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
                                            <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php
                                    if ($keepOverride === 0) {
                                        echo '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
                                    } elseif ($keepOverride !== null && $keepOverride > 0) {
                                        printf('<span class="ptsb-ret" title="%1$s">%2$sd</span>', esc_attr(sprintf('Reter por %d dias', $keepOverride)), esc_html($keepOverride));
                                    } else {
                                        printf('<span class="ptsb-ret" title="%1$s">%2$sd</span>', esc_attr(sprintf('Padrão do painel: %d dias', $defaultKeep)), esc_html($defaultKeep));
                                    }
                                    ?>
                                </td>
                                <td><?php echo $nextLabel; ?></td>
                                <td class="ptsb-actions">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:0" onsubmit="return confirm('Duplicar esta rotina?');">
                                        <?php wp_nonce_field('ptsb_nonce'); ?>
                                        <input type="hidden" name="action" value="ptsb_cycles">
                                        <input type="hidden" name="do" value="dup">
                                        <input type="hidden" name="id" value="<?php echo $cid; ?>">
                                        <button class="button">Duplicar</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:6px" onsubmit="return confirm('Remover esta rotina?');">
                                        <?php wp_nonce_field('ptsb_nonce'); ?>
                                        <input type="hidden" name="action" value="ptsb_cycles">
                                        <input type="hidden" name="do" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $cid; ?>">
                                        <button class="button">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
            <script>
                (function(){
                    const form = document.getElementById('ptsb-add-cycle-form');
                    if (!form) {
                        return;
                    }
                    const lettersWrap = form.querySelector('#ptsb-add-letters');
                    if (lettersWrap) {
                        form.addEventListener('submit', function(){
                            form.querySelectorAll('input[name="letters[]"]').forEach(function(el){ el.remove(); });
                            lettersWrap.querySelectorAll('input[type="checkbox"][data-letter]:checked').forEach(function(cb){
                                const hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = 'letters[]';
                                hidden.value = String(cb.dataset.letter || '').toUpperCase();
                                form.appendChild(hidden);
                            });
                        });
                    }

                    function buildTimes(countInputId, containerId){
                        var qty = document.getElementById(countInputId);
                        var box = document.getElementById(containerId);
                        if (!qty || !box) {
                            return;
                        }
                        function rebuild(){
                            var n = Math.max(1, Math.min(12, parseInt(qty.value, 10) || 1));
                            var oldValues = Array.from(box.querySelectorAll('input[type="time"]')).map(function(inp){ return inp.value; });
                            box.innerHTML = '';
                            for (var i = 0; i < n; i++) {
                                var inp = document.createElement('input');
                                inp.type = 'time';
                                inp.step = 60;
                                inp.name = 'times[]';
                                inp.style.width = '100%';
                                if (oldValues[i]) {
                                    inp.value = oldValues[i];
                                }
                                box.appendChild(inp);
                            }
                            var modeSel = form.querySelector('select[name="mode"]');
                            if (modeSel) {
                                modeSel.dispatchEvent(new Event('change'));
                            }
                        }
                        qty.addEventListener('input', rebuild);
                        rebuild();
                    }
                    buildTimes('new-daily-qty', 'new-daily-times');
                    buildTimes('new-weekly-qty', 'new-weekly-times');
                    buildTimes('new-every-qty', 'new-every-times');

                    var modeSel = form.querySelector('select[name="mode"]');
                    if (modeSel) {
                        function toggleSections(){
                            var val = modeSel.value;
                            form.querySelectorAll('[data-new],[data-sec]').forEach(function(box){
                                var active = (box.getAttribute('data-new') === val) || (box.getAttribute('data-sec') === val);
                                box.style.display = active ? '' : 'none';
                                box.querySelectorAll('input, select, textarea').forEach(function(el){
                                    el.disabled = !active;
                                });
                            });
                        }
                        modeSel.addEventListener('change', toggleSections);
                        toggleSections();
                    }

                    var weeklyWrap = form.querySelector('#wk_new');
                    if (weeklyWrap) {
                        function syncWeekly(){
                            form.querySelectorAll('input[name="wk_days[]"]').forEach(function(el){ el.remove(); });
                            weeklyWrap.querySelectorAll('.ptsb-chip.active').forEach(function(chip){
                                var input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = 'wk_days[]';
                                input.value = String(chip.dataset.day || '');
                                form.appendChild(input);
                            });
                            var guard = document.getElementById('wk_new_guard');
                            if (guard) {
                                guard.disabled = false;
                                guard.value = weeklyWrap.querySelector('.ptsb-chip.active') ? 'ok' : '';
                                guard.disabled = true;
                            }
                        }
                        weeklyWrap.addEventListener('click', function(ev){
                            var chip = ev.target.closest('.ptsb-chip');
                            if (!chip) {
                                return;
                            }
                            chip.classList.toggle('active');
                            syncWeekly();
                        });
                        syncWeekly();
                    }

                    document.addEventListener('submit', function(ev){
                        var target = ev.target;
                        if (!target.matches('form') || !target.querySelector('input[name="action"][value="ptsb_cycles"]')) {
                            return;
                        }
                        var modeSel = target.querySelector('select[name="mode"]');
                        if (!modeSel) {
                            return;
                        }
                        var mode = modeSel.value;
                        var section = target.querySelector('[data-new="'+mode+'"],[data-sec="'+mode+'"]') || target;
                        section.querySelectorAll('input[type="time"]:not([disabled])').forEach(function(inp){
                            inp.required = true;
                            if (!inp.value) {
                                ev.preventDefault();
                                inp.reportValidity();
                            }
                        });
                        if (mode === 'weekly') {
                            var hasDay = !!section.querySelector('.ptsb-chip.active');
                            var guard = target.querySelector('#wk_new_guard');
                            if (guard) {
                                if (!hasDay) {
                                    guard.value = '';
                                    guard.setCustomValidity('Selecione pelo menos 1 dia da semana.');
                                    ev.preventDefault();
                                    guard.reportValidity();
                                } else {
                                    guard.value = 'ok';
                                    guard.setCustomValidity('');
                                }
                            }
                        }
                    }, true);
                })();
            </script>
        <?php elseif ($tab === 'next') :
            $cycles     = ptsb_cycles_get();
            $perDefault = (int) get_option('ptsb_next_per_page', 20);
            $perNext    = isset($_GET['per_next']) ? (int) $_GET['per_next'] : ($perDefault > 0 ? $perDefault : 20);
            $perNext    = max(1, min($perNext, 20));
            if (isset($_GET['per_next'])) {
                update_option('ptsb_next_per_page', $perNext, false);
            }
            $pageNext = max(1, (int) ($_GET['page_next'] ?? 1));

            $nextDateRaw = isset($_GET['next_date']) ? preg_replace('/[^0-9\-]/', '', (string) $_GET['next_date']) : '';
            $nextDate    = '';
            if ($nextDateRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextDateRaw)) {
                try {
                    $candidate = new DateTimeImmutable($nextDateRaw . ' 00:00:00', ptsb_tz());
                    $today0    = ptsb_now_brt()->setTime(0, 0);
                    if ($candidate < $today0) {
                        $candidate = $today0;
                    }
                    $nextDate = $candidate->format('Y-m-d');
                } catch (Throwable $e) {
                    $nextDate = '';
                }
            }
            ?>

            <div id="ptsb-next-root"
                 data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                 data-nonce="<?php echo esc_attr($nonce); ?>"
                 data-page="<?php echo (int) $pageNext; ?>"
                 data-per="<?php echo (int) $perNext; ?>"
                 data-date="<?php echo esc_attr($nextDate); ?>"
                 data-referer="<?php echo esc_attr($referer); ?>"
                 data-post="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                <h2 style="margin-top:8px">Próximas Execuções</h2>

                <?php if (!$cycles) : ?>
                    <p><em>Sem rotinas ativas.</em></p>
                <?php else : ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 10px">
                        <form method="get" id="ptsb-next-date-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:8px;margin:0">
                            <input type="hidden" name="page" value="pt-simple-backup">
                            <input type="hidden" name="tab" value="next">
                            <input type="hidden" name="per_next" value="<?php echo (int) $perNext; ?>">
                            <input type="hidden" name="page_next" value="1">
                            <span>Ver execuções do dia:</span>
                            <input type="date" name="next_date" value="<?php echo esc_attr($nextDate); ?>" min="<?php echo esc_attr(ptsb_now_brt()->format('Y-m-d')); ?>" style="width:auto">
                            <?php if ($nextDate) : ?>
                                <button type="button" class="button button-small" data-reset-date>Limpar</button>
                            <?php endif; ?>
                        </form>

                        <form method="get" id="ptsb-next-per-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:6px;margin:0;margin-left:auto">
                            <input type="hidden" name="page" value="pt-simple-backup">
                            <input type="hidden" name="tab" value="next">
                            <span>Exibindo</span>
                            <input type="number" name="per_next" min="1" max="20" value="<?php echo (int) $perNext; ?>" style="width:auto">
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
                            <input class="current" type="number" min="1" value="<?php echo (int) $pageNext; ?>">
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
                <?php endif; ?>
            </div>
        <?php elseif ($tab === 'last') :
            $perDefaultLast = (int) get_option('ptsb_last_per_page', 20);
            $perLast        = isset($_GET['per_last']) ? (int) $_GET['per_last'] : ($perDefaultLast > 0 ? $perDefaultLast : 20);
            $perLast        = max(1, min($perLast, 20));
            if (isset($_GET['per_last'])) {
                update_option('ptsb_last_per_page', $perLast, false);
            }
            $pageLast = max(1, (int) ($_GET['page_last'] ?? 1));
            $lastExp  = isset($_GET['last_exp']) ? (int) $_GET['last_exp'] : 1;
            $lastOk   = isset($_GET['last_ok']) ? (int) $_GET['last_ok'] : 1;
            $lastExp  = $lastExp ? 1 : 0;
            $lastOk   = $lastOk ? 1 : 0;
            ?>

            <div id="ptsb-last-root"
                 data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                 data-nonce="<?php echo esc_attr($nonce); ?>"
                 data-page="<?php echo (int) $pageLast; ?>"
                 data-per="<?php echo (int) $perLast; ?>"
                 data-exp="<?php echo (int) $lastExp; ?>"
                 data-ok="<?php echo (int) $lastOk; ?>"
                 data-post="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                 data-referer="<?php echo esc_attr($referer); ?>">

                <h2 style="margin-top:8px">Últimas Execuções</h2>

                <div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 10px">
                    <form method="get" id="ptsb-last-filter-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:12px;margin:0">
                        <input type="hidden" name="page" value="pt-simple-backup">
                        <input type="hidden" name="tab" value="last">
                        <input type="hidden" name="per_last" value="<?php echo (int) $perLast; ?>">
                        <input type="hidden" name="page_last" value="1">
                        <label style="display:flex;align-items:center;gap:6px">
                            <input type="checkbox" name="last_exp" value="1" <?php checked($lastExp); ?>>
                            <span>Exibir vencidos</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:6px">
                            <input type="checkbox" name="last_ok" value="1" <?php checked($lastOk); ?>>
                            <span>Exibir dentro da retenção</span>
                        </label>
                    </form>

                    <form method="get" id="ptsb-last-per-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:6px;margin:0;margin-left:auto">
                        <input type="hidden" name="page" value="pt-simple-backup">
                        <input type="hidden" name="tab" value="last">
                        <span>Exibindo</span>
                        <input type="number" name="per_last" min="1" max="20" value="<?php echo (int) $perLast; ?>" style="width:auto">
                        <span>por página</span>
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

                <div class="ptsb-last-total" style="margin-top:8px;opacity:.8">Carregando…</div>

                <nav class="ptsb-pager" id="ptsb-last-pager" aria-label="Paginação das últimas execuções" data-total-pages="1">
                    <a class="btn is-disabled" data-action="first" href="#" aria-disabled="true" title="Primeira página">
                        <span class="dashicons dashicons-controls-skipback"></span>
                    </a>
                    <a class="btn is-disabled" data-action="prev" href="#" aria-disabled="true" title="Página anterior">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </a>
                    <span class="status">
                        <input class="current" type="number" min="1" value="<?php echo (int) $pageLast; ?>">
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
        <?php elseif ($tab === 'settings') :
            $initLog = ptsb_tail_log_raw($cfg['log'], 50);
            ?>

            <h2 style="margin-top:8px">Log</h2>
            <p style="opacity:.7;margin:.3em 0 1em">
                Status: <?php echo esc_html(implode(' | ', $diagnostics)); ?>
            </p>

            <h2 style="margin-top:24px">Cron do sistema</h2>
            <?php
            $wpCronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $cronStatus = $wpCronDisabled
                ? 'WP-Cron por visitas está DESATIVADO.'
                : 'WP-Cron por visitas está ATIVADO (por visitas).';
            $cronAdvice = 'Recomendamos manter um cron do sistema executando `wp cron event run --due-now` para rodar os agendamentos sem atrasos.';
            $basePath = function_exists('wp_normalize_path') ? wp_normalize_path(ABSPATH) : ABSPATH;
            $basePath = untrailingslashit($basePath);
            $cronCmd  = '* * * * * cd ' . escapeshellarg($basePath) . ' && wp cron event run --due-now --quiet';
            ?>
            <p><?php echo esc_html($cronStatus . ' ' . $cronAdvice); ?></p>
            <pre style="margin:10px 0"><code><?php echo esc_html($cronCmd); ?></code></pre>
            <p class="description">Ajuste o intervalo conforme necessário (ex.: <code>*/5</code> para a cada 5 minutos) e garanta que o WP-CLI esteja disponível no servidor.</p>

            <div style="display:flex;align-items:center;gap:8px;margin:10px 0 6px">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Limpar todo o log (incluindo rotações)?');" style="margin-left:auto">
                    <?php wp_nonce_field('ptsb_nonce'); ?>
                    <input type="hidden" name="action" value="ptsb_do">
                    <input type="hidden" name="ptsb_action" value="clear_log">
                    <button class="button">Limpar log</button>
                </form>
            </div>

            <pre id="ptsb-log" style="max-height:420px;overflow:auto;padding:10px;background:#111;border:1px solid #444;border-radius:4px;"><?php echo esc_html($initLog ?: '(sem linhas)'); ?></pre>
            <p><small>Mostrando as últimas 50 linhas. A rotação cria <code>backup-wp.log.1</code>, <code>.2</code>… até <?php echo (int) $cfg['log_keep']; ?>.</small></p>

            <script>
                (function(){
                    var ajaxUrl = window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                    var nonce   = '<?php echo esc_js($nonce); ?>';
                    var logEl   = document.getElementById('ptsb-log');
                    if (!logEl) {
                        return;
                    }
                    var lastLog   = logEl.textContent || '';
                    var autoStick = true;
                    logEl.addEventListener('scroll', function(){
                        var nearBottom = (logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight) < 24;
                        autoStick = nearBottom;
                    });
                    function renderLog(txt){
                        if (txt === lastLog) {
                            return;
                        }
                        var shouldStick = autoStick;
                        logEl.textContent = txt;
                        if (shouldStick) {
                            requestAnimationFrame(function(){
                                logEl.scrollTop = logEl.scrollHeight;
                            });
                        }
                        lastLog = txt;
                    }
                    function poll(){
                        var body = new URLSearchParams({action:'ptsb_status', nonce:nonce}).toString();
                        fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
                            .then(function(res){ return res.json(); })
                            .then(function(res){
                                if (!res || !res.success) {
                                    return;
                                }
                                var data = res.data || {};
                                var txt  = (data.log && String(data.log).trim()) ? data.log : '(sem linhas)';
                                renderLog(txt);
                            })
                            .catch(function(){});
                    }
                    poll();
                    setInterval(poll, 2000);
                })();
            </script>
        <?php endif; ?>
    </div>
    <?php
    settings_errors('ptsb');
}
