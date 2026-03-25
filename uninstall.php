<?php
/**
 * Fired when the plugin is uninstalled.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$kurl_delete_data = get_option('kurl_delete_data', 0);
if (empty($kurl_delete_data)) {
    return;
}

if (!function_exists('wp_get_upload_dir')) {
    require_once ABSPATH . 'wp-includes/functions.php';
}

global $wpdb;

delete_option('kurl_settings');
delete_option('kurl_delete_data');
delete_transient('kurl_dashboard_overview');

$kurl_meta_keys = ['_kurl_shorturl', '_kurl_keyword', '_kurl_stats'];
foreach ($kurl_meta_keys as $kurl_meta_key) {
    delete_post_meta_by_key($kurl_meta_key);
}

$kurl_uploads = wp_get_upload_dir();
$kurl_base_dir = isset($kurl_uploads['basedir']) ? (string) $kurl_uploads['basedir'] : '';
if ($kurl_base_dir !== '') {
    $kurl_log_dir = trailingslashit($kurl_base_dir) . 'kurl-yourls';
    if (is_dir($kurl_log_dir)) {
        kurl_uninstall_delete_dir($kurl_log_dir);
    }
}

function kurl_uninstall_delete_dir(string $dir): void {
    $dir = untrailingslashit($dir);
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            kurl_uninstall_delete_dir($path);
        } else {
            wp_delete_file($path);
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's dedicated empty log directory during uninstall.
    @rmdir($dir);
}
