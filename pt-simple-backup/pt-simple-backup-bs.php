<?php
if (!defined('ABSPATH')) {
    exit;
}

define('PTSB_PATH', __DIR__);
define('PTSB_URL', trailingslashit(plugin_dir_url(__FILE__)));
define('PTSB_ASSETS_URL', trailingslashit(PTSB_URL . 'assets'));
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/log.php';
require_once __DIR__ . '/inc/parts.php';
require_once __DIR__ . '/inc/rclone.php';
require_once __DIR__ . '/inc/schedule.php';
require_once __DIR__ . '/inc/ajax.php';
require_once __DIR__ . '/inc/actions.php';
require_once __DIR__ . '/inc/ui.php';
