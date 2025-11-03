<?php
/*
Plugin Name: PT Simple Backup
Description: Loader para o mu-plugin PT Simple Backup (painel rclone + agendamento).
Version: 1.2.11
Author: PlugInTema
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('PTSB_PLUGIN_VERSION')) {
    define('PTSB_PLUGIN_VERSION', '1.2.11');
}
if (!defined('PTSB_PLUGIN_FILE')) {
    define('PTSB_PLUGIN_FILE', __FILE__);
}
if (!defined('PTSB_PLUGIN_DIR')) {
    define('PTSB_PLUGIN_DIR', __DIR__ . '/pt-simple-backup');
}
if (!defined('PTSB_PLUGIN_URL')) {
    $base_url = plugins_url('/', __FILE__);
    define('PTSB_PLUGIN_URL', rtrim($base_url, '/') . '/pt-simple-backup');
}

require_once PTSB_PLUGIN_DIR . '/pt-simple-backup-bs.php';
