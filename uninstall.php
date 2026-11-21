<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Data is removed only on sites where "Delete all kURL data on uninstall"
 * is enabled. On multisite, every site is checked individually because the
 * setting and the data are stored per site.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

if (!function_exists('wp_get_upload_dir')) {
    require_once ABSPATH . 'wp-includes/functions.php';
}

function kurl_uninstall_delete_dir(string $dir): void {
    $dir = untrailingslashit($dir);
    if ($dir === '') {
        return;
    }
    if (is_link($dir)) {
        wp_delete_file($dir);
        return;
    }
    if (!is_dir($dir)) {
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

function kurl_uninstall_site(): void {
    global $wpdb;

    // Always remove scheduling and leftover locks of crashed requests; they
    // are not user data.
    wp_clear_scheduled_hook('kurl_prune_log');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of plugin-owned lock rows.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('kurl_lock_') . '%'));

    $kurl_delete_data = get_option('kurl_delete_data', 0);
    if (!is_scalar($kurl_delete_data) || (string) $kurl_delete_data !== '1') {
        return;
    }

    delete_option('kurl_settings');
    delete_option('kurl_delete_data');
    delete_option('kurl_log_token');
    delete_option('kurl_installed_version');
    delete_option('kurl_replace_wp_shortlink');
    delete_option('kurl_helper_status');
    delete_transient('kurl_dashboard_overview');

    // Only kURL's own meta. Data of legacy YOURLS plugins is left untouched.
    $kurl_meta_keys = ['_kurl_shorturl', '_kurl_keyword', '_kurl_stats', '_kurl_no_legacy'];
    foreach ($kurl_meta_keys as $kurl_meta_key) {
        delete_post_meta_by_key($kurl_meta_key);
    }

    $kurl_uploads  = wp_get_upload_dir();
    $kurl_base_dir = isset($kurl_uploads['basedir']) && is_string($kurl_uploads['basedir']) ? $kurl_uploads['basedir'] : '';
    if ($kurl_base_dir !== '') {
        $kurl_log_dir = trailingslashit($kurl_base_dir) . 'kurl-short-url-manager-yourls';
        if (is_link($kurl_log_dir)) {
            wp_delete_file($kurl_log_dir);
        } elseif (is_dir($kurl_log_dir)) {
            kurl_uninstall_delete_dir($kurl_log_dir);
        }
    }
}

if (is_multisite()) {
    $kurl_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ((array) $kurl_site_ids as $kurl_site_id) {
        switch_to_blog((int) $kurl_site_id);
        kurl_uninstall_site();
        restore_current_blog();
    }
} else {
    kurl_uninstall_site();
}
