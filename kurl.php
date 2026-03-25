<?php
/**
 * Plugin Name:       kURL - YOURLS
 * Description:       Modern YOURLS integration for WordPress with dashboard statistics, bulk link generation, sync and cleanup tools, logging, and editor tools.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Gerald Drißner
 * Author URI:        https://drissner.media
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kurl-yourls
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

if (!defined('KURL_VERSION')) {
    define('KURL_VERSION', '1.0.0');
}
if (!defined('KURL_FILE')) {
    define('KURL_FILE', __FILE__);
}
if (!defined('KURL_PATH')) {
    define('KURL_PATH', plugin_dir_path(__FILE__));
}
if (!defined('KURL_URL')) {
    define('KURL_URL', plugin_dir_url(__FILE__));
}
if (!defined('KURL_META_URL')) {
    define('KURL_META_URL', '_kurl_shorturl');
}
if (!defined('KURL_META_KEYWORD')) {
    define('KURL_META_KEYWORD', '_kurl_keyword');
}
if (!defined('KURL_META_STATS')) {
    define('KURL_META_STATS', '_kurl_stats');
}
if (!defined('KURL_OLD_META_URL')) {
    define('KURL_OLD_META_URL', '_yourls_url');
}
if (!defined('KURL_OLD_OPTION')) {
    define('KURL_OLD_OPTION', 'better_yourls');
}

require_once KURL_PATH . 'includes/class-kurl-helpers.php';
require_once KURL_PATH . 'includes/class-kurl-logger.php';
require_once KURL_PATH . 'includes/class-kurl-api.php';
require_once KURL_PATH . 'includes/class-kurl-shortlinks.php';
require_once KURL_PATH . 'includes/class-kurl-admin.php';
require_once KURL_PATH . 'includes/class-kurl-bulk.php';

function kurl_bootstrap(): void {
    Kurl_Shortlinks::init();

    if (is_admin()) {
        Kurl_Admin::init();
        Kurl_Bulk::init();

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'kurl_yourls_plugin_action_links' );

/**
 * Add quick links on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function kurl_yourls_plugin_action_links( array $links ): array {
    $dashboard_url = admin_url( 'admin.php?page=kurl' );
    $settings_url  = admin_url( 'admin.php?page=kurl-settings' );

    $custom_links = [
        '<a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'Dashboard', 'kurl-yourls' ) . '</a>',
        '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'kurl-yourls' ) . '</a>',
    ];

    return array_merge( $custom_links, $links );
}

    }
}
add_action('plugins_loaded', 'kurl_bootstrap');

function kurl_activate(): void {
    $defaults = [
        'api_url'                => '',
        'signature'              => '',
        'enabled_post_types'     => ['post', 'page'],
        'cache_minutes'          => 30,
        'request_timeout'        => 15,
        'auto_create_on_publish' => 0,
        'api_extended'           => 0,
    ];

    $existing = get_option('kurl_settings', []);
    if (!is_array($existing)) {
        $existing = [];
    }

    update_option('kurl_settings', wp_parse_args($existing, $defaults), false);

    if (get_option('kurl_delete_data', null) === null) {
        add_option('kurl_delete_data', 0, '', false);
    }
}
register_activation_hook(__FILE__, 'kurl_activate');
