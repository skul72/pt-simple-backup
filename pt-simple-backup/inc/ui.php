<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'ptsb_ui_register_page');
function ptsb_ui_register_page(): void
{
    add_management_page(
        'Backup',
        'Backup',
        'manage_options',
        'pt-simple-backup',
        'ptsb_render_backup_page'
    );
}

add_action('admin_enqueue_scripts', 'ptsb_ui_enqueue_assets');
function ptsb_ui_enqueue_assets($hook): void
{
    if ($hook !== 'tools_page_pt-simple-backup') {
        return;
    }

    wp_enqueue_style('dashicons');

    if (defined('PTSB_PLUGIN_URL')) {
        $base = trailingslashit(PTSB_PLUGIN_URL) . 'assets/';
        wp_enqueue_style('ptsb-admin', $base . 'admin.css', ['dashicons'], PTSB_PLUGIN_VERSION ?? null);
        wp_enqueue_script('ptsb-admin', $base . 'admin.js', ['jquery'], PTSB_PLUGIN_VERSION ?? null, true);
    }
}

add_action('load-tools_page_pt-simple-backup', 'ptsb_ui_prepare_page');
function ptsb_ui_prepare_page(): void
{
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCDN')) {
        define('DONOTCDN', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }

    if (isset($_GET['force']) && (int) $_GET['force'] === 1 && !defined('PTSB_SKIP_MANIFEST_CACHE')) {
        define('PTSB_SKIP_MANIFEST_CACHE', true);
    }

    if (defined('LSCWP_VERSION')) {
        do_action('litespeed_control_set_nocache');
    }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function ptsb_cycle_params_label_ui(array $cycle): string
{
    $mode = strtolower((string) ($cycle['mode'] ?? 'daily'));
    $cfg  = is_array($cycle['cfg'] ?? null) ? $cycle['cfg'] : [];

    $times = [];
    if (!empty($cfg['times']) && is_array($cfg['times'])) {
        $times = array_map('strval', $cfg['times']);
    }
    if (!empty($cfg['time'])) {
        $times[] = (string) $cfg['time'];
    }

    $times = array_values(array_filter(array_map('trim', $times), static fn($s) => $s !== ''));

    if (function_exists('ptsb_times_sort_unique')) {
        $times = ptsb_times_sort_unique($times);
    }

    switch ($mode) {
        case 'daily':
            return $times ? implode(', ', $times) : '—';

        case 'weekly':
            $labels = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            $days   = array_values(array_filter(
                array_map('intval', $cfg['days'] ?? []),
                static fn($d) => $d >= 0 && $d <= 6
            ));
            $dias  = $days ? implode(', ', array_map(static fn($d) => $labels[$d], $days)) : '—';
            $horas = $times ? implode(', ', $times) : '—';
            return $dias . ' · ' . $horas;

        case 'every_n':
            return $times ? implode(', ', $times) : '—';

        case 'interval':
            $unit = strtolower($cfg['every']['unit'] ?? 'minute');
            $unitLabel = $unit === 'day'
                ? 'dia(s)'
                : ($unit === 'hour' ? 'hora(s)' : 'minuto(s)');
            $value = max(1, (int) ($cfg['every']['value'] ?? 1));

            $winEnabled = empty($cfg['win']['disabled']);
            $winText    = $winEnabled
                ? (($cfg['win']['start'] ?? '00:00') . '–' . ($cfg['win']['end'] ?? '23:59'))
                : '';

            $base = 'cada ' . $value . ' ' . $unitLabel;
            return $base . ($winEnabled ? ' · ' . $winText : '');
    }

    return '—';
}

function ptsb_render_backup_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $context = ptsb_ui_build_context();

    ?>
    <div class="wrap">
        <?php ptsb_ui_render_header($context); ?>

        <?php
        switch ($context['tab']) {
            case 'backup':
                ptsb_ui_render_tab_backup($context);
                break;
            case 'cycles':
                ptsb_ui_render_tab_cycles($context);
                break;
            case 'next':
                ptsb_ui_render_tab_next($context);
                break;
            case 'last':
                ptsb_ui_render_tab_last($context);
                break;
            case 'settings':
                ptsb_ui_render_tab_settings($context);
                break;
        }
        ?>
    </div>
    <?php

    settings_errors('ptsb');
}

function ptsb_ui_build_context(): array
{
    $cfg      = ptsb_cfg();
    $settings = ptsb_settings();

    $forceList = isset($_GET['force']) && (int) $_GET['force'] === 1;

    $tabs = ptsb_ui_tabs();
    $tab  = ptsb_ui_resolve_tab($tabs);

    if ($forceList) {
        delete_transient('ptsb_totals_v1');
    }

    $drive  = ptsb_drive_info();
    $totals = ptsb_ui_backups_totals($forceList);

    $bkCount = max(0, (int) ($totals['count'] ?? 0));
    $bkBytes = max(0, (int) ($totals['bytes'] ?? 0));

    $usedStr  = $drive['used'] !== null ? ptsb_hsize_compact($drive['used']) : '?';
    $totalStr = $drive['total'] !== null ? ptsb_hsize_compact($drive['total']) : '?';

    $bkSummary = number_format_i18n($bkCount) . ' ' . ($bkCount === 1 ? 'item' : 'itens')
        . ' / ' . ptsb_hsize_compact($bkBytes);

    $lettersMeta    = ptsb_ui_letters_meta();
    $defaultLetters = array_map('strtoupper', ptsb_ui_default_codes());
    $lastLetters    = ptsb_ui_last_letters($lettersMeta, $defaultLetters);

    return [
        'cfg'            => $cfg,
        'settings'       => $settings,
        'force_list'     => $forceList,
        'tabs'           => $tabs,
        'tab'            => $tab,
        'drive'          => $drive,
        'totals'         => ['count' => $bkCount, 'bytes' => $bkBytes],
        'used_str'       => $usedStr,
        'total_str'      => $totalStr,
        'bk_summary'     => $bkSummary,
        'base_url'       => admin_url('tools.php?page=pt-simple-backup'),
        'nonce'          => wp_create_nonce('ptsb_nonce'),
        'referer'        => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
        'letters_meta'   => $lettersMeta,
        'default_letters'=> $defaultLetters,
        'last_letters'   => $lastLetters,
        'diag'           => ptsb_ui_diag($cfg),
    ];
}

function ptsb_ui_tabs(): array
{
    return [
        'backup'   => 'Backups (Google Drive)',
        'cycles'   => 'Rotinas de Backup',
        'next'     => 'Próximas Execuções',
        'last'     => 'Últimas Execuções',
        'settings' => 'Configurações',
    ];
}

function ptsb_ui_resolve_tab(array $tabs): string
{
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backup';
    return isset($tabs[$tab]) ? $tab : 'backup';
}

function ptsb_ui_letters_meta(): array
{
    return [
        'D' => ['label' => 'Banco de Dados', 'icon' => 'dashicons-database'],
        'P' => ['label' => 'Plugins',         'icon' => 'dashicons-admin-plugins'],
        'T' => ['label' => 'Temas',           'icon' => 'dashicons-admin-appearance'],
        'W' => ['label' => 'Core',            'icon' => 'dashicons-wordpress-alt'],
        'S' => ['label' => 'Scripts',         'icon' => 'dashicons-editor-code'],
        'M' => ['label' => 'Mídia',           'icon' => 'dashicons-admin-media'],
        'O' => ['label' => 'Outros',          'icon' => 'dashicons-image-filter'],
    ];
}

function ptsb_ui_last_letters(array $lettersMeta, array $default): array
{
    $lastLettersStr = get_option('ptsb_last_parts_ui', implode(',', ptsb_ui_default_codes()));
    $lastLetters    = array_values(array_filter(array_map('trim', explode(',', (string) $lastLettersStr))));
    $lastLetters    = array_values(array_intersect(array_map('strtoupper', $lastLetters), array_keys($lettersMeta)));

    return $lastLetters ?: $default;
}

function ptsb_ui_diag(array $cfg): array
{
    return [
        'shell_exec ' . (ptsb_can_shell() ? 'OK' : 'DESABILITADO'),
        'log ' . (ptsb_is_readable($cfg['log']) ? 'legível' : 'sem leitura'),
        'backup.sh ' . (@is_executable($cfg['script_backup']) ? 'executável' : 'sem permissão'),
        'restore.sh ' . (@is_executable($cfg['script_restore']) ? 'executável' : 'sem permissão'),
    ];
}

function ptsb_ui_render_header(array $context): void
{
    $tabs = $context['tabs'];
    $tab  = $context['tab'];
    $base = $context['base_url'];
    ?>
    <h1><?php echo esc_html($tabs[$tab]); ?></h1>
    <p style="opacity:.7;margin:.3em 0 1em">
        Armazenamento: <strong><?php echo esc_html($context['used_str'] . ' / ' . $context['total_str']); ?></strong> |
        Backups no Drive: <strong><?php echo esc_html($context['bk_summary']); ?></strong>
    </p>

    <h2 class="nav-tab-wrapper" style="margin-top:8px">
        <?php foreach ($tabs as $slug => $label):
            $url = esc_url(add_query_arg('tab', $slug, $base));
            $cls = 'nav-tab' . ($tab === $slug ? ' nav-tab-active' : '');
            ?>
            <a class="<?php echo esc_attr($cls); ?>" href="<?php echo $url; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </h2>
    <?php
}

function ptsb_ui_render_tab_backup(array $context): void
{
    $cfg          = $context['cfg'];
    $forceList    = $context['force_list'];
    $lettersMeta  = $context['letters_meta'];
    $lastLetters  = $context['last_letters'];
    $nonce        = $context['nonce'];
    $baseUrl      = $context['base_url'];

    $rows    = ptsb_ui_normalize_backup_rows(ptsb_list_remote_files($forceList));
    $keepers = ptsb_keep_map();

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

    $pagerUrl = static function (int $page) use ($per) {
        return add_query_arg([
            'page'  => 'pt-simple-backup',
            'tab'   => 'backup',
            'per'   => $per,
            'paged' => max(1, $page),
        ], admin_url('tools.php'));
    };

    $dumpTarget = $cfg['remote'];
    $dumpDir    = ptsb_db_dump_remote_dir($cfg);
    if ($dumpDir !== '') {
        $prefix     = rtrim($cfg['remote'], '/');
        $dumpTarget = $prefix . '/' . ltrim($dumpDir, '/');
    }
    ?>
    <h2 style="margin-top:24px !important">Fazer Backup</h2>
    <p class="description">
        Escolha quais partes do site incluir no backup. Para um backup completo, mantenha todos selecionados.
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ptsb-now-form" style="margin:12px 0;">
        <?php wp_nonce_field('ptsb_nonce'); ?>
        <input type="hidden" name="action" value="ptsb_do" />
        <input type="hidden" name="ptsb_action" value="backup_now" />

        <div class="ptsb-chips" id="ptsb-chips">
            <?php foreach ($lettersMeta as $code => $meta): ?>
                <label class="ptsb-chip" data-letter="<?php echo esc_attr($code); ?>">
                    <input type="checkbox" name="parts_sel[]" value="<?php echo esc_attr($code); ?>"
                        <?php checked(in_array($code, $lastLetters, true)); ?>
                        data-letter="<?php echo esc_attr($code); ?>">
                    <span class="dashicons <?php echo esc_attr($meta['icon']); ?>"></span>
                    <?php echo esc_html($meta['label']); ?>
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
        <input type="hidden" name="action" value="ptsb_do" />
        <input type="hidden" name="ptsb_action" value="db_dump" />
        <label>Apelido (opcional)
            <input type="text" name="db_nick" placeholder="Ex.: relatorio" style="min-width:220px">
        </label>
        <button class="button" <?php disabled(!ptsb_can_shell()); ?>>Gerar dump SQL</button>
        <?php if (!ptsb_can_shell()): ?>
            <span class="description">Requer <code>shell_exec</code> habilitado.</span>
        <?php endif; ?>
    </form>

    <h2 style="margin-top:24px !important">
        Arquivos no Google Drive
        <a class="button button-small" style="margin-left:8px" href="<?php echo esc_url(add_query_arg('force', 1, $baseUrl)); ?>">Forçar atualizar</a>
    </h2>

    <div class="ptsb-toolbar">
        <form method="get" id="ptsb-per-form" class="ptsb-list-controls" style="margin:0">
            <input type="hidden" name="page" value="pt-simple-backup">
            <input type="hidden" name="tab" value="backup">
            <input type="hidden" name="paged" value="1">
            <span>Exibindo</span>
            <input type="number" name="per" min="1" max="500" value="<?php echo (int) $per; ?>" style="width:auto">
            <span>por página — total <?php echo (int) $total; ?> backups</span>
        </form>
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
            <?php if (!$rowsPage): ?>
                <tr><td colspan="7"><em>Nenhum backup encontrado.</em></td></tr>
            <?php else: ?>
                <?php foreach ($rowsPage as $row):
                    $time   = $row['time'];
                    $file   = $row['file'];
                    $size   = $row['size'];
                    $isKept = !empty($keepers[$file]);
                    ?>
                    <tr data-file="<?php echo esc_attr($file); ?>" data-time="<?php echo esc_attr($time); ?>" data-kept="<?php echo $isKept ? '1' : '0'; ?>">
                        <td><?php echo esc_html(ptsb_fmt_local_dt($time)); ?></td>
                        <td>
                            <span class="ptsb-filename"><?php echo esc_html($file); ?></span>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ptsb-rename-form" style="display:inline">
                                <?php wp_nonce_field('ptsb_nonce'); ?>
                                <input type="hidden" name="action" value="ptsb_do">
                                <input type="hidden" name="ptsb_action" value="rename">
                                <input type="hidden" name="old_file" value="<?php echo esc_attr($file); ?>">
                                <input type="hidden" name="new_file" value="">
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
                                <button class="button button-secondary" name="ptsb_action" value="restore" <?php disabled(!ptsb_can_shell()); ?>>Restaurar</button>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:6px;" onsubmit="return confirm('Apagar DEFINITIVAMENTE do Drive: <?php echo esc_js($file); ?>?');">
                                <?php wp_nonce_field('ptsb_nonce'); ?>
                                <input type="hidden" name="action" value="ptsb_do">
                                <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>">
                                <button class="button" name="ptsb_action" value="delete" <?php disabled(!ptsb_can_shell() || $isKept); ?> <?php echo $isKept ? 'title="Desative &quot;Sempre manter&quot; antes de apagar"' : ''; ?>>Apagar</button>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ptsb-keep-form">
                                <?php wp_nonce_field('ptsb_nonce'); ?>
                                <input type="hidden" name="action" value="ptsb_do">
                                <input type="hidden" name="ptsb_action" value="keep_toggle">
                                <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>">
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
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <nav class="ptsb-pager" aria-label="Paginação de backups">
            <a class="btn <?php echo $paged <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo $paged > 1 ? esc_url($pagerUrl(1)) : '#'; ?>" aria-disabled="<?php echo $paged <= 1 ? 'true' : 'false'; ?>" title="Primeira página">
                <span class="dashicons dashicons-controls-skipback"></span>
            </a>
            <a class="btn <?php echo $paged <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo $paged > 1 ? esc_url($pagerUrl($paged - 1)) : '#'; ?>" aria-disabled="<?php echo $paged <= 1 ? 'true' : 'false'; ?>" title="Página anterior">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </a>
            <span class="status">
                <input id="ptsb-pager-input" class="current" type="number" min="1" max="<?php echo (int) $totalPages; ?>" value="<?php echo (int) $paged; ?>">
                <span class="sep">de</span>
                <span class="total"><?php echo (int) $totalPages; ?></span>
            </span>
            <a class="btn <?php echo $paged >= $totalPages ? 'is-disabled' : ''; ?>" href="<?php echo $paged < $totalPages ? esc_url($pagerUrl($paged + 1)) : '#'; ?>" aria-disabled="<?php echo $paged >= $totalPages ? 'true' : 'false'; ?>" title="Próxima página">
                <span class="dashicons dashicons-arrow-right-alt2"></span>
            </a>
            <a class="btn <?php echo $paged >= $totalPages ? 'is-disabled' : ''; ?>" href="<?php echo $paged < $totalPages ? esc_url($pagerUrl($totalPages)) : '#'; ?>" aria-disabled="<?php echo $paged >= $totalPages ? 'true' : 'false'; ?>" title="Última página">
                <span class="dashicons dashicons-controls-skipforward"></span>
            </a>
        </nav>
    <?php endif; ?>

    <script>
    (function() {
        const perForm = document.getElementById('ptsb-per-form');
        if (perForm) {
            const perInput = perForm.querySelector('input[name="per"]');
            if (perInput) {
                perInput.addEventListener('change', function() {
                    perForm.submit();
                });
            }
        }

        const pagerInput = document.getElementById('ptsb-pager-input');
        if (pagerInput) {
            const min = parseInt(pagerInput.min, 10) || 1;
            const max = parseInt(pagerInput.max, 10) || 1;
            pagerInput.addEventListener('change', function() {
                let value = parseInt(pagerInput.value, 10) || min;
                value = Math.min(Math.max(value, min), max);
                const url = '<?php echo esc_js($pagerUrl('__PAGE__')); ?>'.replace('__PAGE__', value);
                window.location.href = url;
            });
            pagerInput.addEventListener('keyup', function(ev) {
                if (ev.key === 'Enter') {
                    pagerInput.dispatchEvent(new Event('change'));
                }
            });
        }

        const keepForever = document.getElementById('ptsb-man-keep-forever');
        if (keepForever) {
            const daysInput = document.querySelector('#ptsb-now-form input[name="manual_keep_days"]');
            if (daysInput) {
                const sync = function() {
                    const checked = keepForever.checked;
                    daysInput.disabled = checked;
                    daysInput.style.opacity = checked ? '.5' : '1';
                };
                keepForever.addEventListener('change', sync);
                sync();
            }
        }

        (function() {
            const ajaxUrl = window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const nonce = '<?php echo esc_js($nonce); ?>';
            const barBox = document.getElementById('ptsb-progress');
            const bar = document.getElementById('ptsb-progress-bar');
            const text = document.getElementById('ptsb-progress-text');
            if (!ajaxUrl || !nonce || !barBox || !bar || !text) {
                return;
            }
            let wasRunning = false;
            let didReload = false;
            const poll = function() {
                const body = new URLSearchParams({action: 'ptsb_status', nonce: nonce}).toString();
                fetch(ajaxUrl, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
                    .then(resp => resp.json())
                    .then(data => {
                        if (!data || !data.success) {
                            return;
                        }
                        const status = data.data || {};
                        if (status.running) {
                            wasRunning = true;
                            barBox.style.display = 'block';
                            const pct = Math.max(5, Math.min(100, status.percent | 0));
                            bar.style.width = pct + '%';
                            text.textContent = pct < 100 ? (pct + '% - ' + (status.stage || 'executando…')) : '100%';
                        } else {
                            if (wasRunning && (status.percent | 0) >= 100 && !didReload) {
                                didReload = true;
                                bar.style.width = '100%';
                                text.textContent = '100% - concluído';
                                setTimeout(function() { window.location.reload(); }, 1200);
                            } else {
                                barBox.style.display = 'none';
                            }
                            wasRunning = false;
                        }
                    })
                    .catch(() => {});
            };
            poll();
            setInterval(poll, 2000);
        })();

        document.addEventListener('click', function(ev) {
            const btn = ev.target.closest('.ptsb-rename-btn');
            if (!btn) {
                return;
            }
            const form = btn.closest('form.ptsb-rename-form');
            if (!form) {
                return;
            }
            const oldFull = btn.getAttribute('data-old') || '';
            const prefix = '<?php echo esc_js($cfg['prefix']); ?>';
            let current = oldFull.replace(new RegExp('^' + prefix), '').replace(/\.tar\.gz$/i, '');
            let nick = window.prompt('Novo apelido (apenas a parte entre "' + prefix + '" e ".tar.gz"): ', current);
            if (nick === null) {
                return;
            }
            nick = (nick || '').trim().replace(/\.tar\.gz$/i, '').replace(new RegExp('^' + prefix), '').replace(/[^A-Za-z0-9._\-]+/g, '-');
            if (nick === '') {
                alert('Informe um apelido válido.');
                return;
            }
            form.querySelector('input[name="new_file"]').value = prefix + nick + '.tar.gz';
            form.submit();
        });
    })();
    </script>
    <?php
}

function ptsb_ui_render_tab_cycles(array $context): void
{
    $cfg           = $context['cfg'];
    $lettersMeta   = $context['letters_meta'];
    $defaultLetters= $context['default_letters'];
    $nonce         = $context['nonce'];

    $cycles = ptsb_cycles_get();
    $defKeepDays = (int) ($context['settings']['keep_days'] ?? 30);
    ?>
    <h2 style="margin-top:8px">Rotinas de Backup</h2>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0 16px">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="flex:1 1 340px" id="ptsb-add-cycle-form">
            <?php wp_nonce_field('ptsb_nonce'); ?>
            <input type="hidden" name="action" value="ptsb_cycles">
            <input type="hidden" name="do" value="add">
            <div class="ptsb-card" style="padding:16px">
                <h3 style="margin-top:0">Nova rotina</h3>
                <p class="description">Defina nome, frequência e partes incluídas.</p>
                <p style="margin-top:12px">
                    <label>Nome<br>
                        <input type="text" name="name" required style="width:100%">
                    </label>
                </p>
                <p>
                    <label>Modo de agendamento<br>
                        <select name="mode" style="width:100%">
                            <option value="daily">Diário</option>
                            <option value="weekly">Semanal</option>
                            <option value="every_n">A cada N dias</option>
                            <option value="interval">Intervalo fixo</option>
                        </select>
                    </label>
                </p>

                <div data-mode="daily">
                    <p>
                        <label>Quantos horários por dia?<br>
                            <input type="number" name="qty_daily" value="1" min="1" max="12" style="width:80px">
                        </label>
                    </p>
                    <div data-times="daily" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px"></div>
                </div>

                <div data-mode="weekly" style="display:none">
                    <p>
                        <label>Quantos horários por dia?<br>
                            <input type="number" name="qty_weekly" value="1" min="1" max="12" style="width:80px">
                        </label>
                    </p>
                    <div data-times="weekly" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px"></div>
                    <input type="hidden" name="wk_days_guard" value="">
                    <div data-weekdays style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
                        <?php $labels = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']; ?>
                        <?php foreach ($labels as $idx => $label): ?>
                            <span class="ptsb-chip" data-day="<?php echo (int) $idx; ?>" style="cursor:pointer">
                                <span><?php echo esc_html($label); ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div data-mode="every_n" style="display:none">
                    <p>
                        <label>Executar a cada quantos dias?<br>
                            <input type="number" name="n_days" value="2" min="1" max="90" style="width:80px">
                        </label>
                    </p>
                    <p>
                        <label>Quantos horários por execução?<br>
                            <input type="number" name="qty_every_n" value="1" min="1" max="12" style="width:80px">
                        </label>
                    </p>
                    <div data-times="every_n" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px"></div>
                </div>

                <div data-mode="interval" style="display:none">
                    <p>
                        <label>Executar a cada<br>
                            <input type="number" name="interval_value" value="6" min="1" max="1440" style="width:80px">
                        </label>
                        <select name="interval_unit">
                            <option value="minute">minuto(s)</option>
                            <option value="hour">hora(s)</option>
                            <option value="day">dia(s)</option>
                        </select>
                    </p>
                    <p>
                        <label>Janela diária (opcional)<br>
                            <input type="time" name="win_start" value="00:00">
                            até
                            <input type="time" name="win_end" value="23:59">
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="win_disabled" value="1"> Desativar janela (executa a qualquer hora)
                        </label>
                    </p>
                </div>

                <p>
                    <label>Manter por quantos dias?<br>
                        <input type="number" name="keep_days" value="<?php echo esc_attr($defKeepDays); ?>" min="0" max="3650" style="width:100px">
                    </label>
                </p>

                <p>
                    <strong>Partes incluídas</strong>
                </p>
                <div class="ptsb-chips" style="margin-bottom:12px">
                    <?php foreach ($lettersMeta as $code => $meta): ?>
                        <label class="ptsb-chip" data-letter="<?php echo esc_attr($code); ?>">
                            <input type="checkbox" name="letters[]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $defaultLetters, true)); ?>>
                            <span class="dashicons <?php echo esc_attr($meta['icon']); ?>"></span>
                            <?php echo esc_html($meta['label']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p>
                    <label>
                        <input type="checkbox" name="enabled" value="1" checked> Ativar rotina após criar
                    </label>
                </p>

                <p style="margin-bottom:0">
                    <button class="button button-primary">Salvar rotina</button>
                </p>
            </div>
        </form>

        <div style="flex:1 1 360px">
            <div class="ptsb-card" style="padding:16px">
                <h3 style="margin-top:0">Legendas</h3>
                <ul style="margin:0;list-style:disc inside;opacity:.85">
                    <li><strong>sempre</strong> = não expira</li>
                    <li><strong>0 dias</strong> = usa padrão do painel (<?php echo (int) $defKeepDays; ?> dias)</li>
                    <li>Use apelidos diferentes para identificar backups manuais</li>
                </ul>
            </div>
        </div>
    </div>

    <h2 style="margin-top:24px">Rotinas ativas</h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th style="width:80px">Ativo</th>
                <th>Nome</th>
                <th>Frequência</th>
                <th>Horários</th>
                <th>Partes</th>
                <th>Retenção</th>
                <th>Próxima</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$cycles): ?>
                <tr><td colspan="8"><em>Sem rotinas cadastradas.</em></td></tr>
            <?php else: ?>
                <?php foreach ($cycles as $cid => $cycle):
                    $mode = strtolower((string) ($cycle['mode'] ?? 'daily'));
                    $freq = match ($mode) {
                        'daily'    => 'Diário',
                        'weekly'   => 'Semanal',
                        'every_n'  => 'Recorrente · A cada ' . max(1, (int) ($cycle['cfg']['n'] ?? 1)) . ' dias',
                        'interval' => 'Intervalo',
                        default    => ucfirst($mode),
                    };
                    $letters = array_values(array_intersect(array_map('strtoupper', (array) ($cycle['letters'] ?? [])), array_keys($lettersMeta)));
                    if (!$letters) {
                        $letters = $defaultLetters;
                    }
                    $keepDays = isset($cycle['keep_days']) ? (int) $cycle['keep_days'] : null;
                    $nextRun  = ptsb_cycles_next_occurrences([$cycle], 1);
                    $nextText = $nextRun ? esc_html($nextRun[0]['dt']->format('d/m/Y H:i')) : '(—)';
                    $params   = ptsb_cycle_params_label_ui($cycle);
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
                        <td><?php echo esc_html($freq); ?></td>
                        <td style="white-space:nowrap"><?php echo esc_html($params); ?></td>
                        <td>
                            <?php foreach ($letters as $code):
                                $meta = $lettersMeta[$code];
                                ?>
                                <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
                                    <span class="dashicons <?php echo esc_attr($meta['icon']); ?>"></span>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php
                            if ($keepDays === 0) {
                                echo '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
                            } elseif ($keepDays > 0) {
                                echo '<span class="ptsb-ret" title="' . esc_attr(sprintf('Reter por %d dias', $keepDays)) . '">' . esc_html($keepDays) . ' d</span>';
                            } else {
                                echo '<span class="ptsb-ret" title="' . esc_attr(sprintf('Padrão do painel: %d dias', $defKeepDays)) . '">' . esc_html($defKeepDays) . ' d</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo $nextText; ?></td>
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
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <script>
    (function() {
        const form = document.getElementById('ptsb-add-cycle-form');
        if (!form) {
            return;
        }

        const toggleSections = function() {
            const mode = form.querySelector('select[name="mode"]').value;
            form.querySelectorAll('[data-mode]').forEach(function(box) {
                const active = box.getAttribute('data-mode') === mode;
                box.style.display = active ? '' : 'none';
                box.querySelectorAll('input, select, textarea').forEach(function(el) {
                    el.disabled = !active;
                });
            });
        };

        const buildTimes = function(selector, qtySelector) {
            const container = form.querySelector(selector);
            const qtyInput = form.querySelector(qtySelector);
            if (!container || !qtyInput) {
                return;
            }
            const rebuild = function() {
                const qty = Math.max(1, Math.min(12, parseInt(qtyInput.value, 10) || 1));
                const prevValues = Array.from(container.querySelectorAll('input[type="time"]')).map(el => el.value);
                container.innerHTML = '';
                for (let i = 0; i < qty; i += 1) {
                    const input = document.createElement('input');
                    input.type = 'time';
                    input.step = 60;
                    input.name = 'times[]';
                    input.style.width = '100%';
                    if (prevValues[i]) {
                        input.value = prevValues[i];
                    }
                    container.appendChild(input);
                }
                toggleSections();
            };
            qtyInput.addEventListener('input', rebuild);
            rebuild();
        };

        buildTimes('[data-times="daily"]', 'input[name="qty_daily"]');
        buildTimes('[data-times="weekly"]', 'input[name="qty_weekly"]');
        buildTimes('[data-times="every_n"]', 'input[name="qty_every_n"]');

        const weekdaysWrap = form.querySelector('[data-weekdays]');
        if (weekdaysWrap) {
            weekdaysWrap.addEventListener('click', function(ev) {
                const chip = ev.target.closest('[data-day]');
                if (!chip) {
                    return;
                }
                chip.classList.toggle('active');
            });
        }

        form.querySelector('select[name="mode"]').addEventListener('change', toggleSections);
        toggleSections();

        form.addEventListener('submit', function(ev) {
            const mode = form.querySelector('select[name="mode"]').value;
            const section = form.querySelector('[data-mode="' + mode + '"]') || form;
            const times = section.querySelectorAll('input[type="time"]:not([disabled])');
            for (const input of times) {
                input.required = true;
                if (!input.value) {
                    ev.preventDefault();
                    input.reportValidity();
                    return;
                }
            }

            if (mode === 'weekly' && weekdaysWrap) {
                const guard = form.querySelector('input[name="wk_days_guard"]');
                const hasDay = !!weekdaysWrap.querySelector('.ptsb-chip.active');
                if (guard) {
                    if (!hasDay) {
                        guard.value = '';
                        guard.setCustomValidity('Selecione pelo menos 1 dia da semana.');
                        ev.preventDefault();
                        guard.reportValidity();
                        return;
                    }
                    guard.value = 'ok';
                    guard.setCustomValidity('');
                }
                form.querySelectorAll('input[name="wk_days[]"]').forEach(function(item) { item.remove(); });
                weekdaysWrap.querySelectorAll('.ptsb-chip.active').forEach(function(chip) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'wk_days[]';
                    input.value = chip.getAttribute('data-day');
                    form.appendChild(input);
                });
            }
        }, true);
    })();
    </script>
    <?php
}

function ptsb_ui_render_tab_next(array $context): void
{
    $nonce   = $context['nonce'];
    $referer = $context['referer'];

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
            $dayObj = new DateTimeImmutable($nextDateRaw . ' 00:00:00', ptsb_tz());
            $today0 = ptsb_now_brt()->setTime(0, 0);
            if ($dayObj < $today0) {
                $dayObj = $today0;
            }
            $nextDate = $dayObj->format('Y-m-d');
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

        <?php if (!$cycles): ?>
            <p><em>Sem rotinas ativas.</em></p>
        <?php else: ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 10px">
                <form method="get" id="ptsb-next-date-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:8px;margin:0">
                    <input type="hidden" name="page" value="pt-simple-backup">
                    <input type="hidden" name="tab" value="next">
                    <input type="hidden" name="per_next" value="<?php echo (int) $perNext; ?>">
                    <input type="hidden" name="page_next" value="1">
                    <span>Ver execuções do dia:</span>
                    <input type="date" name="next_date" value="<?php echo esc_attr($nextDate); ?>" min="<?php echo esc_attr(ptsb_now_brt()->format('Y-m-d')); ?>" style="width:auto">
                    <?php if ($nextDate): ?>
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

            <table class="widefat striped" id="ptsb-next-table">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Rotina</th>
                        <th>Partes</th>
                        <th>Retenção</th>
                        <th>Backup</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="ptsb-loading"><td colspan="6"><em>Carregando…</em></td></tr>
                </tbody>
            </table>

            <nav class="ptsb-pager" id="ptsb-next-pager" aria-label="Paginação de próximas execuções" data-total-pages="1">
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
    <?php
}

function ptsb_ui_render_tab_last(array $context): void
{
    $nonce   = $context['nonce'];
    $referer = $context['referer'];

    $perDefault = (int) get_option('ptsb_last_per_page', 50);
    $perLast    = isset($_GET['per_last']) ? (int) $_GET['per_last'] : ($perDefault > 0 ? $perDefault : 50);
    $perLast    = max(1, min($perLast, 200));
    if (isset($_GET['per_last'])) {
        update_option('ptsb_last_per_page', $perLast, false);
    }

    $pageLast = max(1, (int) ($_GET['page_last'] ?? 1));
    ?>
    <div id="ptsb-last-root"
         data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-page="<?php echo (int) $pageLast; ?>"
         data-per="<?php echo (int) $perLast; ?>"
         data-referer="<?php echo esc_attr($referer); ?>">

        <h2 style="margin-top:8px">Últimas Execuções</h2>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 10px">
            <form method="get" id="ptsb-last-per-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:6px;margin:0">
                <input type="hidden" name="page" value="pt-simple-backup">
                <input type="hidden" name="tab" value="last">
                <span>Exibindo</span>
                <input type="number" name="per_last" min="1" max="200" value="<?php echo (int) $perLast; ?>" style="width:auto">
                <span>por página</span>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-left:auto">
                <?php wp_nonce_field('ptsb_nonce'); ?>
                <input type="hidden" name="action" value="ptsb_do">
                <input type="hidden" name="ptsb_action" value="clear_history">
                <button class="button" onclick="return confirm('Limpar histórico de execuções?');">Limpar histórico</button>
            </form>
        </div>

        <table class="widefat striped" id="ptsb-last-table">
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
    <?php
}

function ptsb_ui_render_tab_settings(array $context): void
{
    $cfg     = $context['cfg'];
    $diag    = $context['diag'];
    $nonce   = $context['nonce'];

    $initLog = ptsb_tail_log_raw($cfg['log'], 50);

    $wpCronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    $cronStatus = $wpCronDisabled
        ? 'WP-Cron por visitas está DESATIVADO.'
        : 'WP-Cron por visitas está ATIVADO (por visitas).';
    $cronAdvice = 'Recomendamos manter um cron do sistema executando `wp cron event run --due-now` para rodar os agendamentos sem atrasos.';
    $basePath = function_exists('wp_normalize_path') ? wp_normalize_path(ABSPATH) : ABSPATH;
    $basePath = untrailingslashit($basePath);
    $cronCmd  = '* * * * * cd ' . escapeshellarg($basePath) . ' && wp cron event run --due-now --quiet';
    ?>
    <h2 style="margin-top:8px">Log</h2>
    <p style="opacity:.7;margin:.3em 0 1em">
        Status: <?php echo esc_html(implode(' | ', $diag)); ?>
    </p>

    <h2 style="margin-top:24px">Cron do sistema</h2>
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
    (function() {
        const ajaxUrl = window.ajaxurl || '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const nonce = '<?php echo esc_js($nonce); ?>';
        const logEl = document.getElementById('ptsb-log');
        if (!ajaxUrl || !nonce || !logEl) {
            return;
        }
        let lastLog = logEl.textContent || '';
        let autoStick = true;
        logEl.addEventListener('scroll', function() {
            const nearBottom = (logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight) < 24;
            autoStick = nearBottom;
        });
        const renderLog = function(text) {
            if (text === lastLog) {
                return;
            }
            const shouldStick = autoStick;
            logEl.textContent = text;
            if (shouldStick) {
                requestAnimationFrame(function() {
                    logEl.scrollTop = logEl.scrollHeight;
                });
            }
            lastLog = text;
        };
        const poll = function() {
            const body = new URLSearchParams({action: 'ptsb_status', nonce: nonce}).toString();
            fetch(ajaxUrl, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
                .then(resp => resp.json())
                .then(data => {
                    if (!data || !data.success) {
                        return;
                    }
                    const info = data.data || {};
                    const text = (info.log && String(info.log).trim()) ? info.log : '(sem linhas)';
                    renderLog(text);
                })
                .catch(() => {});
        };
        poll();
        setInterval(poll, 2000);
    })();
    </script>
    <?php
}

function ptsb_ui_normalize_backup_rows($rows): array
{
    if (!is_array($rows)) {
        return [];
    }

    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $file = (string) ($row['file'] ?? '');
        if ($file === '') {
            continue;
        }
        $normalized[] = [
            'time' => (string) ($row['time'] ?? ''),
            'file' => $file,
            'size' => (int) ($row['size'] ?? 0),
        ];
    }

    return $normalized;
}

function ptsb_ui_backups_totals(bool $forceList): array
{
    $totals = ptsb_backups_totals_cached();
    $count  = (int) ($totals['count'] ?? 0);
    $bytes  = (int) ($totals['bytes'] ?? 0);

    if ($count > 0 || $bytes > 0) {
        return ['count' => $count, 'bytes' => $bytes];
    }

    $rows = ptsb_remote_manifest_read(false);
    if (!is_array($rows) || !$rows) {
        $rows = ptsb_list_remote_files($forceList);
    }

    $rows    = ptsb_ui_normalize_backup_rows($rows);
    $count   = count($rows);
    $bytes   = 0;
    foreach ($rows as $row) {
        $bytes += (int) $row['size'];
    }

    if ($count > 0 || $bytes > 0) {
        set_transient('ptsb_totals_v1', ['count' => $count, 'bytes' => $bytes], 10 * MINUTE_IN_SECONDS);
    }

    return ['count' => $count, 'bytes' => $bytes];
}
