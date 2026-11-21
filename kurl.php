<?php
/**
 * Plugin Name:       kURL – Short URL Manager for YOURLS
 * Description:       Modern YOURLS integration for WordPress with dashboard statistics, bulk link generation, sync and cleanup tools, logging, and editor tools.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Gerald Drißner
 * Author URI:        https://drissner.media
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kurl-short-url-manager-yourls
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

if (!defined('KURL_VERSION')) {
    define('KURL_VERSION', '1.1.0');
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
if (!defined('KURL_HELPER_VERSION')) {
    define('KURL_HELPER_VERSION', '1.1.6');
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
if (!defined('KURL_META_NO_LEGACY')) {
    // Set when a post was unlinked while legacy data that kURL may not delete
    // remained; that data is kept but no longer used as a fallback.
    define('KURL_META_NO_LEGACY', '_kurl_no_legacy');
}
if (!defined('KURL_OLD_META_URL')) {
    define('KURL_OLD_META_URL', '_yourls_url');
}
if (!defined('KURL_OLD_OPTION')) {
    define('KURL_OLD_OPTION', 'better_yourls');
}

require_once KURL_PATH . 'includes/class-kurl-helpers.php';
require_once KURL_PATH . 'includes/class-kurl-logger.php';
require_once KURL_PATH . 'includes/class-kurl-lock.php';
require_once KURL_PATH . 'includes/class-kurl-api.php';
require_once KURL_PATH . 'includes/class-kurl-shortlinks.php';
require_once KURL_PATH . 'includes/class-kurl-frontend.php';

if (!function_exists('kurl_get_shortlink')) {
    /**
     * Template tag: return the kURL short URL of a post.
     *
     * Without an argument it uses the current loop post, or the queried post
     * on singular views when called outside a loop (header, footer, sidebar).
     * On archives outside a loop it returns an empty string.
     *
     * @param int|WP_Post|null $post     Post ID or object, or null for the current post.
     * @param bool             $fallback Return the regular WordPress shortlink when no kURL link exists.
     * @return string Short URL or an empty string. Escape it on output, for example with esc_url().
     */
    function kurl_get_shortlink($post = null, bool $fallback = false): string {
        return Kurl_Frontend::get_shortlink($post, $fallback);
    }
}

if (!function_exists('kurl_the_shortlink')) {
    /**
     * Template tag: print the kURL short URL, optionally as a link.
     *
     * Accepts the same arguments as the [kurl_shortlink] shortcode, for example
     * kurl_the_shortlink(['link' => 1, 'before' => 'Short URL: ']).
     *
     * @param array $args Output arguments.
     */
    function kurl_the_shortlink(array $args = []): void {
        echo wp_kses_post(Kurl_Frontend::render($args));
    }
}

function kurl_load_textdomain(): void {
    load_plugin_textdomain(
        'kurl-short-url-manager-yourls',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('init', 'kurl_load_textdomain', 0);

function kurl_bootstrap(): void {
    Kurl_Shortlinks::init();
    Kurl_Frontend::init();

    if (is_admin()) {
        // Admin-only classes are intentionally loaded only for wp-admin,
        // admin-ajax.php, and admin-post.php requests. Front-end shortlink
        // filtering and publish automation do not depend on these files.
        require_once KURL_PATH . 'includes/class-kurl-admin.php';
        require_once KURL_PATH . 'includes/class-kurl-bulk.php';

        Kurl_Admin::init();
        Kurl_Bulk::init();
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'kurl_yourls_plugin_action_links');
    }
}
add_action('plugins_loaded', 'kurl_bootstrap');

// Daily physical removal of log entries older than the retention window.
add_action('kurl_prune_log', ['Kurl_Logger', 'prune']);

function kurl_schedule_log_pruning(): void {
    if (!wp_next_scheduled('kurl_prune_log')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'kurl_prune_log');
    }
}

function kurl_deactivate(): void {
    wp_clear_scheduled_hook('kurl_prune_log');
}
register_deactivation_hook(__FILE__, 'kurl_deactivate');

/**
 * Add quick links on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function kurl_yourls_plugin_action_links(array $links): array {
    $custom_links = [
        '<a href="' . esc_url(admin_url('admin.php?page=kurl-short-url-manager-yourls')) . '">' . esc_html__('Dashboard', 'kurl-short-url-manager-yourls') . '</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=kurl-settings')) . '">' . esc_html__('Settings', 'kurl-short-url-manager-yourls') . '</a>',
    ];

    return array_merge($custom_links, $links);
}

function kurl_activate(): void {
    $defaults = [
        'api_url'                => '',
        'signature'              => '',
        'enabled_post_types'     => ['post', 'page'],
        'cache_minutes'          => 30,
        'request_timeout'        => 15,
        'auto_create_on_publish' => 0,
        'api_extended'           => 0,
        'helper_version'         => '',
        'helper_capabilities'    => [],
        'helper_checked_at'      => 0,
        'helper_check_error'     => '',
    ];

    $existing = get_option('kurl_settings', []);
    if (!is_array($existing)) {
        $existing = [];
    }

    $settings = wp_parse_args($existing, $defaults);
    // Activation can happen after a manual plugin replacement. Never carry a
    // previously cached helper verdict across activation; the first admin load
    // will ping YOURLS again and compare it with the bundled helper version.
    Kurl_Helpers::update_settings($settings);
    Kurl_Helpers::clear_helper_status();
    update_option('kurl_installed_version', KURL_VERSION, false);

    if (get_option('kurl_delete_data', null) === null) {
        add_option('kurl_delete_data', 0, '', false);
    }

    Kurl_Helpers::ensure_frontend_option();
    kurl_schedule_log_pruning();
}
register_activation_hook(__FILE__, 'kurl_activate');
