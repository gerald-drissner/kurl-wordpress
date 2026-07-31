<?php

defined('ABSPATH') || exit;

final class Kurl_Admin {

    private const HELPER_STATUS_TTL = 300;


    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'maybe_refresh_helper_after_upgrade'], 1);
        add_action('admin_notices', [__CLASS__, 'helper_update_notice']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_background_helper_refresh'], 1);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);

        add_action('wp_ajax_kurl_generate_post_link', [__CLASS__, 'ajax_generate_post_link']);
        add_action('wp_ajax_kurl_refresh_post_stats', [__CLASS__, 'ajax_refresh_post_stats']);
        add_action('wp_ajax_kurl_delete_post_link', [__CLASS__, 'ajax_delete_post_link']);
        add_action('wp_ajax_kurl_test_api', [__CLASS__, 'ajax_test_api']);
        add_action('wp_ajax_kurl_refresh_helper_status', [__CLASS__, 'ajax_refresh_helper_status']);
        add_action('wp_ajax_kurl_check_sync_post', [__CLASS__, 'ajax_check_sync_post']);
        add_action('wp_ajax_kurl_manual_lookup_url', [__CLASS__, 'ajax_manual_lookup_url']);
        add_action('wp_ajax_kurl_manual_generate_url', [__CLASS__, 'ajax_manual_generate_url']);
        add_action('wp_ajax_kurl_manual_delete_url', [__CLASS__, 'ajax_manual_delete_url']);
        add_action('wp_ajax_kurl_manual_regenerate_url', [__CLASS__, 'ajax_manual_regenerate_url']);
        add_action('wp_ajax_kurl_reconcile_batch', [__CLASS__, 'ajax_reconcile_batch']);

        add_action('admin_post_kurl_save_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_kurl_import_legacy', [__CLASS__, 'import_legacy']);
        add_action('admin_post_kurl_delete_legacy', [__CLASS__, 'delete_legacy']);
        add_action('admin_post_kurl_clear_log', [__CLASS__, 'clear_log']);
        add_action('admin_post_kurl_cleanup_local', [__CLASS__, 'cleanup_local']);
        add_action('admin_post_kurl_reconcile_yourls', [__CLASS__, 'reconcile_yourls']);
        add_action('admin_post_kurl_download_helper', [__CLASS__, 'download_helper']);

        foreach (Kurl_Helpers::enabled_post_types() as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", [__CLASS__, 'add_shorturl_list_column']);
            add_action("manage_{$post_type}_posts_custom_column", [__CLASS__, 'render_shorturl_list_column'], 10, 2);
        }
        add_filter('default_hidden_columns', [__CLASS__, 'default_hidden_columns'], 10, 2);
    }

    /**
     * Invalidate a cached helper verdict after a WordPress-plugin update.
     *
     * No network request is made during admin_init. Relevant admin screens
     * trigger a protected AJAX refresh after the page has rendered, while
     * helper-dependent actions retain their own server-side verification gate.
     */
    public static function maybe_refresh_helper_after_upgrade(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $installed_version = sanitize_text_field(Kurl_Helpers::scalar_string(get_option('kurl_installed_version', '')));
        if ($installed_version !== KURL_VERSION) {
            self::invalidate_helper_status();
            update_option('kurl_installed_version', KURL_VERSION, false);
        }

        if (!Kurl_API::configured()) {
            self::clear_helper_status_if_needed();
        }
    }

    /**
     * Read the helper status from YOURLS and persist only a short-lived result.
     * This is intentionally independent of the old api_extended flag so an
     * outdated helper can never remain trusted after a kURL update.
     */
    public static function refresh_helper_status(bool $force = false): array {
        // This check lives in the status reader itself, not only in an admin
        // notice hook. It therefore also protects AJAX actions run by editors
        // immediately after an automatic WordPress-plugin update.
        $plugin_updated = sanitize_text_field(Kurl_Helpers::scalar_string(get_option('kurl_installed_version', ''))) !== KURL_VERSION;
        if ($plugin_updated) {
            self::invalidate_helper_status();
            update_option('kurl_installed_version', KURL_VERSION, false);
            $force = true;
        }

        $settings = Kurl_Helpers::get_settings();

        if (!Kurl_API::configured()) {
            if (!empty($settings['api_extended']) || !empty($settings['helper_version']) || !empty($settings['helper_capabilities']) || !empty($settings['helper_checked_at']) || !empty($settings['helper_check_error'])) {
                $settings['api_extended'] = 0;
                $settings['helper_version'] = '';
                $settings['helper_capabilities'] = [];
                $settings['helper_checked_at'] = 0;
                $settings['helper_check_error'] = '';
                update_option('kurl_settings', $settings, false);
                Kurl_Helpers::flush_settings_cache();
            }
            return Kurl_Helpers::get_settings();
        }

        $last_check = Kurl_Helpers::scalar_int($settings['helper_checked_at'] ?? 0);
        if (!$force && $last_check > 0 && $last_check >= (time() - self::HELPER_STATUS_TTL)) {
            return $settings;
        }

        $checked_api_url   = Kurl_Helpers::scalar_string($settings['api_url'] ?? '');
        $checked_signature = Kurl_Helpers::scalar_string($settings['signature'] ?? '');
        $helper_info = Kurl_API::extended_api_info(['timeout' => 5]);
        $available   = !empty($helper_info['available']);
        $response    = isset($helper_info['response']) && is_array($helper_info['response'])
            ? $helper_info['response']
            : [];

        // Do not write a result obtained with credentials that were changed
        // while the remote request was running.
        Kurl_Helpers::flush_settings_cache();
        $current_settings = Kurl_Helpers::get_settings();
        if (!hash_equals($checked_api_url, Kurl_Helpers::scalar_string($current_settings['api_url'] ?? ''))
            || !hash_equals($checked_signature, Kurl_Helpers::scalar_string($current_settings['signature'] ?? ''))) {
            return $current_settings;
        }
        $settings = $current_settings;

        $settings['api_extended'] = $available ? 1 : 0;
        $settings['helper_version'] = $available
            ? sanitize_text_field(Kurl_Helpers::scalar_string($helper_info['version'] ?? ''))
            : '';
        $settings['helper_capabilities'] = $available
            ? Kurl_Helpers::sanitize_key_list(is_array($helper_info['capabilities'] ?? null) ? $helper_info['capabilities'] : [])
            : [];
        $settings['helper_checked_at'] = time();
        $settings['helper_check_error'] = $available ? '' : Kurl_Helpers::format_api_error($response);

        update_option('kurl_settings', $settings, false);
        Kurl_Helpers::flush_settings_cache();

        return Kurl_Helpers::get_settings();
    }

    /**
     * Refresh the helper status after the admin page has rendered.
     */
    public static function ajax_refresh_helper_status(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(Kurl_Helpers::request_string($_POST['post_id'])) : 0;
        $allowed = current_user_can('manage_options')
            || current_user_can('edit_posts')
            || current_user_can('edit_pages')
            || ($post_id > 0 && current_user_can('edit_post', $post_id));
        if (!$allowed) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-short-url-manager-yourls')], 403);
        }

        $before = self::helper_status_snapshot(Kurl_Helpers::get_settings());
        $settings = self::refresh_helper_status(false);
        $after = self::helper_status_snapshot($settings);

        wp_send_json_success([
            'changed' => $before !== $after,
            'status'  => self::helper_status_payload($settings),
        ]);
    }

    /**
     * Public capability check used by the bulk processor.
     */
    public static function helper_is_ready(string $capability = ''): bool {
        $settings = self::refresh_helper_status(false);
        return $capability === ''
            ? self::helper_is_current($settings)
            : self::helper_has_capability($capability, $settings);
    }

    public static function helper_update_notice(): void {
        if (!current_user_can('manage_options') || !Kurl_API::configured()) {
            return;
        }

        $settings = Kurl_Helpers::get_settings();
        if (empty($settings['api_extended']) || self::helper_is_current($settings)) {
            return;
        }

        $version = trim(Kurl_Helpers::scalar_string($settings['helper_version'] ?? ''));
        $version = $version !== '' ? $version : __('unknown', 'kurl-short-url-manager-yourls');
        $url = admin_url('admin.php?page=kurl-settings');

        echo '<div id="kurl-helper-async-notice" class="notice notice-error"><p><strong>' . esc_html__('kURL Helper update required.', 'kurl-short-url-manager-yourls') . '</strong> ';
        echo esc_html(sprintf(
            /* translators: 1: Detected helper version. 2: Required bundled helper version. */
            __('YOURLS reports helper version %1$s, but this kURL release requires bundled helper %2$s with all advertised capabilities. Helper-dependent actions are disabled until you replace plugin.php from the kURL Settings page.', 'kurl-short-url-manager-yourls'),
            $version,
            KURL_HELPER_VERSION
        ));
        echo ' <a href="' . esc_url($url) . '">' . esc_html__('Open kURL Settings', 'kurl-short-url-manager-yourls') . '</a></p></div>';
    }

    public static function download_helper(): void {
        self::assert_manage_options();
        check_admin_referer('kurl_download_helper');

        $path = self::helper_plugin_path();
        if (!is_readable($path)) {
            wp_die(esc_html__('The bundled helper file is missing from this kURL installation.', 'kurl-short-url-manager-yourls'));
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="plugin.php"');
        header('X-Content-Type-Options: nosniff');
        $size = filesize($path);
        if (is_int($size) && $size >= 0) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        exit;
    }

    private static function invalidate_helper_status(): void {
        $settings = Kurl_Helpers::get_settings();
        $settings['api_extended'] = 0;
        $settings['helper_version'] = '';
        $settings['helper_capabilities'] = [];
        $settings['helper_checked_at'] = 0;
        $settings['helper_check_error'] = '';
        update_option('kurl_settings', $settings, false);
        Kurl_Helpers::flush_settings_cache();
    }

    public static function menu(): void {
        add_menu_page(__('kURL', 'kurl-short-url-manager-yourls'), __('kURL', 'kurl-short-url-manager-yourls'), 'manage_options', 'kurl-short-url-manager-yourls', [__CLASS__, 'render_dashboard'], 'dashicons-admin-links', 58);
        add_submenu_page('kurl-short-url-manager-yourls', __('Dashboard', 'kurl-short-url-manager-yourls'), __('Dashboard', 'kurl-short-url-manager-yourls'), 'manage_options', 'kurl-short-url-manager-yourls', [__CLASS__, 'render_dashboard']);
        add_submenu_page('kurl-short-url-manager-yourls', __('Bulk Generator', 'kurl-short-url-manager-yourls'), __('Bulk Generator', 'kurl-short-url-manager-yourls'), 'manage_options', 'kurl-bulk', [__CLASS__, 'render_bulk']);
        add_submenu_page('kurl-short-url-manager-yourls', __('Sync & Cleanup', 'kurl-short-url-manager-yourls'), __('Sync & Cleanup', 'kurl-short-url-manager-yourls'), 'manage_options', 'kurl-sync', [__CLASS__, 'render_sync_cleanup']);
        add_submenu_page('kurl-short-url-manager-yourls', __('Logs', 'kurl-short-url-manager-yourls'), __('Logs', 'kurl-short-url-manager-yourls'), 'manage_options', 'kurl-logs', [__CLASS__, 'render_logs']);
        add_submenu_page('kurl-short-url-manager-yourls', __('Settings', 'kurl-short-url-manager-yourls'), __('Settings', 'kurl-short-url-manager-yourls'), 'manage_options', 'kurl-settings', [__CLASS__, 'render_settings']);
    }

    /**
     * Start a non-blocking helper refresh on other wp-admin screens.
     *
     * kURL screens and post editors use the full admin script so their visible
     * controls can be updated immediately. Other admin screens only warm the
     * shared cache in the background.
     */
    public static function enqueue_background_helper_refresh(string $hook): void {
        if (self::should_enqueue_assets($hook) || !current_user_can('manage_options')) {
            return;
        }

        $settings = Kurl_Helpers::get_settings();
        if (!self::helper_status_needs_refresh($settings)) {
            return;
        }

        wp_enqueue_script('jquery');
        $payload = wp_json_encode([
            'url'    => admin_url('admin-ajax.php'),
            'action' => 'kurl_refresh_helper_status',
            'nonce'  => wp_create_nonce('kurl_admin'),
        ]);
        if (!is_string($payload) || $payload === '') {
            return;
        }

        wp_add_inline_script(
            'jquery-core',
            'jQuery(function($){var p=' . $payload . ';$.post(p.url,{action:p.action,nonce:p.nonce});});',
            'after'
        );
    }

    public static function enqueue(string $hook): void {
        if (!self::should_enqueue_assets($hook)) {
            return;
        }
        $settings = Kurl_Helpers::get_settings();
        $is_extended = self::helper_has_capability('delete', $settings);
        $confirm_msg = $is_extended
            ? __('Are you sure you want to permanently delete this shortlink from WordPress and your YOURLS database?', 'kurl-short-url-manager-yourls')
            : __('Are you sure you want to unlink this shortlink from WordPress? You will still need to delete the old link manually in YOURLS before reusing the same custom slug.', 'kurl-short-url-manager-yourls');

        wp_enqueue_style('kurl-admin', KURL_URL . 'assets/kurl-admin.css', [], KURL_VERSION);
        wp_enqueue_script('kurl-admin', KURL_URL . 'assets/kurl-admin.js', ['jquery'], KURL_VERSION, true);
        wp_localize_script('kurl-admin', 'kurlAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('kurl_admin'),
            'helperRefreshNeeded' => self::helper_status_needs_refresh($settings),
            'helperStatus'        => self::helper_status_payload($settings),
            'canManageOptions'    => current_user_can('manage_options'),
            'settingsUrl'         => admin_url('admin.php?page=kurl-settings'),
            'strings'             => [
                'working'              => __('Working…', 'kurl-short-url-manager-yourls'),
                'done'                 => __('Done', 'kurl-short-url-manager-yourls'),
                'error'                => __('Error', 'kurl-short-url-manager-yourls'),
                'confirm_delete'       => $confirm_msg,
                'confirm_manual_delete'=> __('Permanently delete this short URL from YOURLS? kURL will block deletion if a WordPress post still references it.', 'kurl-short-url-manager-yourls'),
                'confirm_regenerate'   => __('Regenerate this short URL safely? YOURLS will keep the existing link unchanged if the replacement is rejected.', 'kurl-short-url-manager-yourls'),
                'clicks_label'         => __('Clicks:', 'kurl-short-url-manager-yourls'),
                'links_label'          => __('Links:', 'kurl-short-url-manager-yourls'),
                'copy_missing'         => __('No code found to copy.', 'kurl-short-url-manager-yourls'),
                'copy_failed'          => __('Failed to copy code. Please copy it manually.', 'kurl-short-url-manager-yourls'),
                'bulk_processed'       => __('Processed:', 'kurl-short-url-manager-yourls'),
                'bulk_created'         => __('Created:', 'kurl-short-url-manager-yourls'),
                'bulk_updated'         => __('Updated:', 'kurl-short-url-manager-yourls'),
                'bulk_imported'        => __('Imported:', 'kurl-short-url-manager-yourls'),
                'bulk_skipped'         => __('Skipped:', 'kurl-short-url-manager-yourls'),
                'bulk_errors'          => __('Errors:', 'kurl-short-url-manager-yourls'),
                'bulk_status'          => __('Status:', 'kurl-short-url-manager-yourls'),
                'bulk_done'            => __('Done.', 'kurl-short-url-manager-yourls'),
                'bulk_done_label'      => __('done', 'kurl-short-url-manager-yourls'),
                'bulk_stopping'        => __('Stopping after the current batch…', 'kurl-short-url-manager-yourls'),
                'bulk_stopped'         => __('Stopped.', 'kurl-short-url-manager-yourls'),
                'bulk_error_prefix'    => __('Error:', 'kurl-short-url-manager-yourls'),
                'bulk_ajax_prefix'     => __('AJAX error:', 'kurl-short-url-manager-yourls'),
                'status_created'       => __('created', 'kurl-short-url-manager-yourls'),
                'status_updated'       => __('updated', 'kurl-short-url-manager-yourls'),
                'status_imported'      => __('imported', 'kurl-short-url-manager-yourls'),
                'status_skipped_exist' => __('skipped existing', 'kurl-short-url-manager-yourls'),
                'status_error'         => __('error', 'kurl-short-url-manager-yourls'),
                'sync_button'          => __('Check / Sync', 'kurl-short-url-manager-yourls'),
                'api_connected'        => __('Connected', 'kurl-short-url-manager-yourls'),
                'api_not_connected'    => __('Not connected', 'kurl-short-url-manager-yourls'),
                'manual_lookup_only'   => __('Safe lookup needs the helper plugin.', 'kurl-short-url-manager-yourls'),
                'manual_regenerate'    => __('Regenerate', 'kurl-short-url-manager-yourls'),
                'manual_delete'        => __('Delete', 'kurl-short-url-manager-yourls'),
                'manual_generate'      => __('Generate / Update', 'kurl-short-url-manager-yourls'),
                'manual_check'         => __('Check YOURLS', 'kurl-short-url-manager-yourls'),
                'reconcile_checked'    => __('Checked:', 'kurl-short-url-manager-yourls'),
                'reconcile_imported'   => __('Imported:', 'kurl-short-url-manager-yourls'),
                'reconcile_replaced'   => __('Replaced:', 'kurl-short-url-manager-yourls'),
                'reconcile_verified'   => __('Verified:', 'kurl-short-url-manager-yourls'),
                'reconcile_mismatches' => __('Mismatches:', 'kurl-short-url-manager-yourls'),
                'reconcile_skipped'    => __('Skipped:', 'kurl-short-url-manager-yourls'),
                'reconcile_errors'     => __('Errors:', 'kurl-short-url-manager-yourls'),
                'reconcile_preview'    => __('Preview only', 'kurl-short-url-manager-yourls'),
                'reconcile_apply'      => __('Apply changes', 'kurl-short-url-manager-yourls'),
                'reconcile_done'       => __('Reconciliation finished.', 'kurl-short-url-manager-yourls'),
                'reconcile_stopping'   => __('Stopping reconciliation after the current batch…', 'kurl-short-url-manager-yourls'),
                'reconcile_stopped'    => __('Reconciliation stopped.', 'kurl-short-url-manager-yourls'),
                'status_would_import'  => __('would import', 'kurl-short-url-manager-yourls'),
                'status_would_replace' => __('would replace', 'kurl-short-url-manager-yourls'),
                'status_verified'      => __('verified', 'kurl-short-url-manager-yourls'),
                'status_mismatch'      => __('mismatch', 'kurl-short-url-manager-yourls'),
                'status_skipped'       => __('skipped', 'kurl-short-url-manager-yourls'),
            ],
        ]);
    }

    public static function add_shorturl_list_column(array $columns): array {
        $new_columns = [];
        $inserted = false;
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'title') {
                $new_columns['kurl_shorturl'] = __('Short URL', 'kurl-short-url-manager-yourls');
                $inserted = true;
            }
        }
        if (!$inserted) {
            $new_columns['kurl_shorturl'] = __('Short URL', 'kurl-short-url-manager-yourls');
        }
        return $new_columns;
    }

    public static function render_shorturl_list_column(string $column_name, int $post_id): void {
        if ($column_name !== 'kurl_shorturl') {
            return;
        }
        $shorturl = Kurl_Shortlinks::get_shorturl($post_id);
        if ($shorturl === '') {
            echo '&#8212;';
            return;
        }
        echo '<code><a href="' . esc_url($shorturl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($shorturl) . '</a></code>';
    }

    public static function default_hidden_columns(array $hidden, WP_Screen $screen): array {
        if ($screen->base !== 'edit') {
            return $hidden;
        }
        $post_type = isset($screen->post_type) ? (string) $screen->post_type : '';
        if ($post_type === '' || !in_array($post_type, Kurl_Helpers::enabled_post_types(), true)) {
            return $hidden;
        }
        if (!in_array('kurl_shorturl', $hidden, true)) {
            $hidden[] = 'kurl_shorturl';
        }
        return $hidden;
    }

    public static function add_meta_box(): void {
        foreach (Kurl_Helpers::enabled_post_types() as $post_type) {
            add_meta_box('kurl-meta', __('kURL Shortlink', 'kurl-short-url-manager-yourls'), [__CLASS__, 'render_meta_box'], $post_type, 'side', 'high');
        }
    }

    public static function render_meta_box(WP_Post $post): void {
        $shorturl = Kurl_Shortlinks::get_shorturl($post->ID);
        $keyword = Kurl_Shortlinks::get_keyword($post->ID);
        $stats = Kurl_Shortlinks::get_stats($post->ID);
        $settings = Kurl_Helpers::get_settings();
        $is_extended = self::helper_has_capability('delete', $settings);
        $delete_text = $is_extended ? esc_html__('Delete & Unlink', 'kurl-short-url-manager-yourls') : esc_html__('Unlink Locally', 'kurl-short-url-manager-yourls');
        $delete_style = $shorturl !== '' ? 'color:#d63638;display:inline-block;' : 'color:#d63638;display:none;';
        $readonly_kw = $shorturl !== '' ? ' readonly="readonly"' : '';
        $delete_disabled = current_user_can('delete_post', $post->ID) ? '' : ' disabled="disabled"';

        echo '<div class="kurl-box">';
        echo '<p style="margin-bottom:6px;"><label><strong>' . esc_html__('Keyword / Slug', 'kurl-short-url-manager-yourls') . '</strong></label></p>';
        echo '<input type="text" class="widefat kurl-keyword" value="' . esc_attr($keyword) . '" placeholder="' . esc_attr__('optional-custom-slug', 'kurl-short-url-manager-yourls') . '"' . $readonly_kw . '>';
        echo '<p style="margin-top:14px;margin-bottom:6px;"><label><strong>' . esc_html__('Short URL', 'kurl-short-url-manager-yourls') . '</strong></label></p>';
        echo '<input type="text" class="widefat kurl-shorturl" value="' . esc_attr($shorturl) . '" readonly="readonly">';
        echo '<p class="kurl-actions" style="margin-top:16px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;">';
        echo '<button type="button" class="button button-primary kurl-generate" data-post="' . (int) $post->ID . '">' . esc_html__('Generate / Update', 'kurl-short-url-manager-yourls') . '</button>';
        echo '<button type="button" class="button kurl-sync" data-post="' . (int) $post->ID . '">' . esc_html__('Check / Sync', 'kurl-short-url-manager-yourls') . '</button>';
        echo '<button type="button" class="button kurl-refresh-stats" data-post="' . (int) $post->ID . '">' . esc_html__('Refresh Stats', 'kurl-short-url-manager-yourls') . '</button>';
        echo '<button type="button" class="button button-link-delete kurl-delete" data-post="' . (int) $post->ID . '" style="' . esc_attr($delete_style) . '"' . $delete_disabled . '>' . esc_html($delete_text) . '</button>';
        echo '</p>';
        echo '<div class="kurl-inline-status" style="margin-top:12px;"></div>';
        if (!empty($stats)) {
            echo '<div class="kurl-meta-stats" style="margin-top:12px;"><strong>' . esc_html__('Clicks:', 'kurl-short-url-manager-yourls') . '</strong> ' . esc_html((string) Kurl_Helpers::scalar_int($stats['clicks'] ?? 0)) . '</div>';
        }
        echo '<p class="description" style="margin-top:12px;">' . esc_html__('Experimental: Check whether YOURLS already has a short URL for this permalink and sync it into WordPress.', 'kurl-short-url-manager-yourls') . '</p>';
        echo '</div>';
    }

    public static function ajax_generate_post_link(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(Kurl_Helpers::request_string($_POST['post_id'])) : 0;
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-short-url-manager-yourls')], 403);
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            wp_send_json_error(['message' => __('Invalid post.', 'kurl-short-url-manager-yourls')], 400);
        }
        if (!Kurl_Helpers::is_supported_post_type($post->post_type)) {
            wp_send_json_error(['message' => __('This post type is not enabled for kURL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $keyword = isset($_POST['keyword'])
            ? Kurl_Helpers::sanitize_keyword(Kurl_Helpers::request_string($_POST['keyword']))
            : '';
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            wp_send_json_error(['message' => __('Could not determine the permalink.', 'kurl-short-url-manager-yourls')], 400);
        }
        $existing_shorturl = Kurl_Shortlinks::get_shorturl($post_id);
        $updating_existing = $existing_shorturl !== '';

        if ($updating_existing) {
            if (Kurl_Shortlinks::count_other_references($existing_shorturl, $post_id) > 0) {
                wp_send_json_error([
                    'message' => __('This short URL is also referenced by another WordPress post. kURL will not edit the shared YOURLS entry from this post.', 'kurl-short-url-manager-yourls'),
                ], 409);
            }
            $settings = self::refresh_helper_status(false);
            if (!self::helper_has_capability('regenerate', $settings)) {
                wp_send_json_error([
                    'message' => sprintf(__('Updating an existing YOURLS link safely requires the current bundled kURL Helper (%s). Update it on the Settings page, test the API, and try again.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION),
                ], 400);
            }

            // Preserve the existing keyword and update the YOURLS row in place.
            // This avoids both orphaning the old short URL and trying to create a
            // second row with an already-taken keyword.
            $existing_keyword = Kurl_Helpers::keyword_from_shorturl($existing_shorturl);
            if ($existing_keyword === '') {
                wp_send_json_error(['message' => __('Could not determine the keyword of the saved short URL.', 'kurl-short-url-manager-yourls')], 400);
            }
            $response = Kurl_API::regenerate_shortlink($permalink, $existing_shorturl, $existing_keyword, get_the_title($post));
        } else {
            $response = Kurl_API::create_shortlink($permalink, $keyword, get_the_title($post));
        }

        if (empty($response['ok'])) {
            $message = Kurl_Helpers::format_api_error($response);
            Kurl_Logger::log('error', $updating_existing ? 'Manual update failed' : 'Manual generation failed', ['post_id' => $post_id, 'message' => $message]);
            wp_send_json_error(['message' => $message], 400);
        }
        $shorturl = Kurl_API::extract_shorturl($response);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $keyword_adjusted = !$updating_existing && $keyword !== '' && !self::shorturl_matches_keyword($shorturl, $keyword);
        Kurl_Shortlinks::save_link($post_id, $shorturl, $updating_existing ? $existing_keyword : $keyword);
        delete_transient('kurl_dashboard_overview');
        wp_send_json_success([
            'shorturl' => $shorturl,
            'keyword'  => Kurl_Helpers::keyword_from_shorturl($shorturl),
            'message'  => $updating_existing
                ? __('The existing YOURLS short URL was updated safely.', 'kurl-short-url-manager-yourls')
                : ($keyword_adjusted
                    ? sprintf(__('YOURLS adjusted the requested keyword. The returned short URL was saved instead: %s', 'kurl-short-url-manager-yourls'), $shorturl)
                    : __('Shortlink saved successfully.', 'kurl-short-url-manager-yourls')),
        ]);
    }

    public static function ajax_check_sync_post(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(Kurl_Helpers::request_string($_POST['post_id'])) : 0;
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-short-url-manager-yourls')], 403);
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            wp_send_json_error(['message' => __('Invalid post.', 'kurl-short-url-manager-yourls')], 400);
        }
        if (!Kurl_Helpers::is_supported_post_type($post->post_type)) {
            wp_send_json_error(['message' => __('This post type is not enabled for kURL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            wp_send_json_error(['message' => __('Could not determine the permalink.', 'kurl-short-url-manager-yourls')], 400);
        }

        $local          = Kurl_Shortlinks::get_shorturl($post_id);
        $local_mismatch = false;
        $expand_error   = [];

        // Verify the exact local short URL first. This prevents a reverse lookup
        // from replacing it with another duplicate that points to the same URL.
        if ($local !== '') {
            $expand = Kurl_API::expand_shortlink($local);
            if (!empty($expand['ok'])) {
                $longurl = Kurl_API::extract_longurl($expand);
                if ($longurl !== '' && self::urls_match($longurl, $permalink)) {
                    wp_send_json_success(['shorturl' => $local, 'keyword' => Kurl_Helpers::keyword_from_shorturl($local), 'message' => __('The saved short URL already matches the current permalink.', 'kurl-short-url-manager-yourls')]);
                }
                if ($longurl !== '') {
                    $local_mismatch = true;
                } else {
                    $expand_error = ['message' => __('YOURLS returned no target URL while verifying the saved short URL.', 'kurl-short-url-manager-yourls')];
                }
            } elseif (Kurl_API::is_not_found_response($expand)) {
                $local_mismatch = true;
            } else {
                $expand_error = $expand;
            }
        }

        $settings = self::refresh_helper_status(false);
        if (self::helper_has_capability('find_by_url', $settings)) {
            $helper = Kurl_API::find_by_longurl($permalink, $local);
            $found  = !empty($helper['ok']) ? Kurl_API::extract_shorturl($helper) : '';
            if ($found !== '') {
                Kurl_Shortlinks::save_link($post_id, $found, Kurl_Shortlinks::get_keyword($post_id));
                delete_transient('kurl_dashboard_overview');
                wp_send_json_success([
                    'shorturl' => $found,
                    'keyword'  => Kurl_Helpers::keyword_from_shorturl($found),
                    'message'  => $local !== ''
                        ? (self::urls_match($local, $found)
                            ? __('The helper confirmed that the saved short URL belongs to the current permalink.', 'kurl-short-url-manager-yourls')
                            : __('Local short URL replaced with the matching YOURLS entry.', 'kurl-short-url-manager-yourls'))
                        : __('Matching YOURLS short URL imported successfully.', 'kurl-short-url-manager-yourls'),
                ]);
            }
            if (empty($helper['ok']) && !Kurl_API::is_not_found_response($helper)) {
                wp_send_json_error(['message' => Kurl_Helpers::format_api_error($helper)], 400);
            }
        }

        // Never create a replacement merely because verification was interrupted
        // by a network, proxy, authentication, or server error.
        if (!empty($expand_error)) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($expand_error)], 400);
        }
        if ($local_mismatch) {
            wp_send_json_error(['message' => __('The saved short URL points to a different long URL in YOURLS. Review this manually before replacing it.', 'kurl-short-url-manager-yourls')], 400);
        }

        $fallback = Kurl_API::create_shortlink($permalink, '', get_the_title($post));
        if (empty($fallback['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($fallback)], 400);
        }
        $shorturl = Kurl_API::extract_shorturl($fallback);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-short-url-manager-yourls')], 400);
        }
        Kurl_Shortlinks::save_link($post_id, $shorturl, Kurl_Shortlinks::get_keyword($post_id));
        delete_transient('kurl_dashboard_overview');
        wp_send_json_success(['shorturl' => $shorturl, 'keyword' => Kurl_Helpers::keyword_from_shorturl($shorturl), 'message' => __('No helper match was found. The standard API returned or created a short URL and synced it into WordPress.', 'kurl-short-url-manager-yourls')]);
    }

    public static function ajax_manual_lookup_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options_ajax(true);
        $url = isset($_POST['url'])
            ? Kurl_Helpers::sanitize_http_url(Kurl_Helpers::request_string($_POST['url']))
            : '';
        if ($url === '') {
            wp_send_json_error(['message' => __('Please enter a valid URL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $settings = self::refresh_helper_status(false);
        if (!self::helper_has_capability('find_by_url', $settings)) {
            wp_send_json_error(['message' => __('Safe lookup needs the optional kURL Helper plugin on your YOURLS server.', 'kurl-short-url-manager-yourls')], 400);
        }
        $response = Kurl_API::find_by_longurl($url);
        if (!empty($response['ok'])) {
            $shorturl = Kurl_API::extract_shorturl($response);
            if ($shorturl !== '') {
                wp_send_json_success([
                    'shorturl' => $shorturl,
                    'keyword'  => Kurl_Helpers::keyword_from_shorturl($shorturl),
                    'message'  => __('An existing YOURLS short URL was found for that URL.', 'kurl-short-url-manager-yourls'),
                ]);
            }
        }
        $message = Kurl_Helpers::format_api_error($response);
        if (Kurl_API::is_not_found_response($response)) {
            wp_send_json_success([
                'shorturl' => '',
                'message'  => __('No existing YOURLS short URL was found for that URL.', 'kurl-short-url-manager-yourls'),
            ]);
        }
        wp_send_json_error(['message' => $message], 400);
    }

    public static function ajax_manual_generate_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options_ajax(true);
        $url = isset($_POST['url'])
            ? Kurl_Helpers::sanitize_http_url(Kurl_Helpers::request_string($_POST['url']))
            : '';
        $keyword = isset($_POST['keyword'])
            ? Kurl_Helpers::sanitize_keyword(Kurl_Helpers::request_string($_POST['keyword']))
            : '';
        $old_shorturl = isset($_POST['shorturl'])
            ? Kurl_Helpers::sanitize_http_url(Kurl_Helpers::request_string($_POST['shorturl']))
            : '';
        if ($url === '') {
            wp_send_json_error(['message' => __('Please enter a valid URL.', 'kurl-short-url-manager-yourls')], 400);
        }

        $updating_existing = $old_shorturl !== '';
        if ($updating_existing) {
            if (Kurl_Shortlinks::count_other_references($old_shorturl, 0) > 0) {
                wp_send_json_error([
                    'message' => __('This short URL is referenced by one or more WordPress posts. Update it from the relevant post editor or unlink those references first.', 'kurl-short-url-manager-yourls'),
                ], 409);
            }
            $settings = self::refresh_helper_status(false);
            if (!self::helper_has_capability('regenerate', $settings)) {
                wp_send_json_error([
                    'message' => sprintf(__('Updating an existing YOURLS link safely requires the current bundled kURL Helper (%s). Update it on the Settings page, test the API, and try again.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION),
                ], 400);
            }
            if ($keyword === '') {
                $keyword = Kurl_Helpers::keyword_from_shorturl($old_shorturl);
            }
            if ($keyword === '') {
                wp_send_json_error(['message' => __('Could not determine the keyword of the existing short URL.', 'kurl-short-url-manager-yourls')], 400);
            }
            $response = Kurl_API::regenerate_shortlink($url, $old_shorturl, $keyword, '');
        } else {
            $response = Kurl_API::create_shortlink($url, $keyword, '');
        }

        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }
        $shorturl = Kurl_API::extract_shorturl($response);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $keyword_adjusted = $keyword !== '' && !self::shorturl_matches_keyword($shorturl, $keyword);
        wp_send_json_success([
            'shorturl' => $shorturl,
            'keyword'  => Kurl_Helpers::keyword_from_shorturl($shorturl),
            'message'  => $updating_existing
                ? ($keyword_adjusted
                    ? sprintf(__('YOURLS adjusted the requested keyword and updated this short URL instead: %s', 'kurl-short-url-manager-yourls'), $shorturl)
                    : __('The existing YOURLS short URL was updated safely.', 'kurl-short-url-manager-yourls'))
                : ($keyword_adjusted
                    ? sprintf(__('YOURLS adjusted the requested keyword and returned this short URL: %s', 'kurl-short-url-manager-yourls'), $shorturl)
                    : __('Short URL generated successfully.', 'kurl-short-url-manager-yourls')),
        ]);
    }

    public static function ajax_manual_delete_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options_ajax(true);
        $settings = self::refresh_helper_status(false);
        if (!self::helper_has_capability('delete', $settings)) {
            wp_send_json_error(['message' => sprintf(__('Remote deletion requires the current bundled kURL Helper (%s). Update it on the Settings page and test the API again.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION)], 400);
        }
        $shorturl = isset($_POST['shorturl'])
            ? Kurl_Helpers::sanitize_http_url(Kurl_Helpers::request_string($_POST['shorturl']))
            : '';
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('Please enter or look up a short URL first.', 'kurl-short-url-manager-yourls')], 400);
        }
        if (Kurl_Shortlinks::count_other_references($shorturl, 0) > 0) {
            wp_send_json_error([
                'message' => __('This short URL is referenced by one or more WordPress posts. Delete or unlink those references from the post editor before deleting the YOURLS entry.', 'kurl-short-url-manager-yourls'),
            ], 409);
        }
        $response = Kurl_API::delete_shortlink($shorturl);
        if (empty($response['ok']) && !Kurl_API::is_not_found_response($response)) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }
        wp_send_json_success([
            'message' => !empty($response['ok'])
                ? __('Short URL deleted in YOURLS.', 'kurl-short-url-manager-yourls')
                : __('The short URL was already absent from YOURLS.', 'kurl-short-url-manager-yourls'),
        ]);
    }
    public static function ajax_manual_regenerate_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options_ajax(true);
        $settings = self::refresh_helper_status(false);
        if (!self::helper_has_capability('regenerate', $settings)) {
            wp_send_json_error(['message' => sprintf(__('Safe regeneration requires the current bundled kURL Helper (%s). Update it on the Settings page and test the API again.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION)], 400);
        }
        $url = isset($_POST['url'])
            ? Kurl_Helpers::sanitize_http_url(Kurl_Helpers::request_string($_POST['url']))
            : '';
        $keyword = isset($_POST['keyword'])
            ? Kurl_Helpers::sanitize_keyword(Kurl_Helpers::request_string($_POST['keyword']))
            : '';
        $shorturl = isset($_POST['shorturl'])
            ? Kurl_Helpers::sanitize_http_url(Kurl_Helpers::request_string($_POST['shorturl']))
            : '';
        if ($url === '') {
            wp_send_json_error(['message' => __('Please enter a valid URL.', 'kurl-short-url-manager-yourls')], 400);
        }
        if ($shorturl === '') {
            $lookup = Kurl_API::find_by_longurl($url);
            if (!empty($lookup['ok'])) {
                $shorturl = Kurl_API::extract_shorturl($lookup);
            } elseif (!Kurl_API::is_not_found_response($lookup)) {
                wp_send_json_error(['message' => Kurl_Helpers::format_api_error($lookup)], 400);
            }
        }
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('No existing YOURLS short URL was found to regenerate.', 'kurl-short-url-manager-yourls')], 404);
        }
        if (Kurl_Shortlinks::count_other_references($shorturl, 0) > 0) {
            wp_send_json_error([
                'message' => __('This short URL is referenced by one or more WordPress posts. Regenerate it from the relevant post editor or unlink those references first.', 'kurl-short-url-manager-yourls'),
            ], 409);
        }

        // The helper uses yourls_edit_link(), so the existing row is only
        // replaced when YOURLS accepts the complete edit. Never delete first.
        $response = Kurl_API::regenerate_shortlink($url, $shorturl, $keyword, '');
        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }
        $new_shorturl = Kurl_API::extract_shorturl($response);
        if ($new_shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $keyword_adjusted = $keyword !== '' && !self::shorturl_matches_keyword($new_shorturl, $keyword);
        wp_send_json_success([
            'shorturl' => $new_shorturl,
            'keyword'  => Kurl_Helpers::keyword_from_shorturl($new_shorturl),
            'message'  => $keyword_adjusted
                ? sprintf(__('YOURLS adjusted the requested keyword and regenerated this short URL instead: %s', 'kurl-short-url-manager-yourls'), $new_shorturl)
                : ($keyword !== ''
                    ? __('Short URL regenerated with the requested keyword.', 'kurl-short-url-manager-yourls')
                    : __('Short URL regenerated with a new random keyword.', 'kurl-short-url-manager-yourls')),
        ]);
    }
    public static function ajax_refresh_post_stats(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(Kurl_Helpers::request_string($_POST['post_id'])) : 0;
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-short-url-manager-yourls')], 403);
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !Kurl_Helpers::is_supported_post_type($post->post_type)) {
            wp_send_json_error(['message' => __('This post is not enabled for kURL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $shorturl = Kurl_Shortlinks::get_shorturl($post_id);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('No short URL saved yet.', 'kurl-short-url-manager-yourls')], 400);
        }
        $response = Kurl_API::url_stats($shorturl);
        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }
        $click_value = $response['link']['clicks'] ?? ($response['clicks'] ?? 0);
        $clicks = max(0, Kurl_Helpers::scalar_int($click_value));
        Kurl_Shortlinks::save_stats($post_id, ['clicks' => $clicks, 'updated' => current_time('mysql')]);
        wp_send_json_success(['stats' => ['clicks' => $clicks]]);
    }
    public static function ajax_delete_post_link(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(Kurl_Helpers::request_string($_POST['post_id'])) : 0;
        if (!current_user_can('delete_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-short-url-manager-yourls')], 403);
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !Kurl_Helpers::is_supported_post_type($post->post_type)) {
            wp_send_json_error(['message' => __('This post is not enabled for kURL.', 'kurl-short-url-manager-yourls')], 400);
        }
        $shorturl = Kurl_Shortlinks::get_shorturl($post_id);
        if ($shorturl === '') {
            wp_send_json_success(['message' => __('No saved shortlink was found.', 'kurl-short-url-manager-yourls')]);
        }
        if (Kurl_Shortlinks::count_other_references($shorturl, $post_id) > 0) {
            self::clear_post_link_meta($post_id);
            delete_transient('kurl_dashboard_overview');
            wp_send_json_success([
                'message' => __('The link was unlinked from this post, but the YOURLS entry was kept because another WordPress post still references it.', 'kurl-short-url-manager-yourls'),
            ]);
        }
        $settings = self::refresh_helper_status(false);
        if (self::helper_has_capability('delete', $settings)) {
            $delete_response = Kurl_API::delete_shortlink($shorturl);
            if (empty($delete_response['ok']) && !Kurl_API::is_not_found_response($delete_response)) {
                wp_send_json_error([
                    /* translators: %s: API error message. */
                    'message' => sprintf(__('Remote deletion failed: %s', 'kurl-short-url-manager-yourls'), Kurl_Helpers::format_api_error($delete_response)),
                ], 400);
            }
            self::clear_post_link_meta($post_id);
            delete_transient('kurl_dashboard_overview');
            wp_send_json_success([
                'message' => !empty($delete_response['ok'])
                    ? __('Shortlink deleted in YOURLS and unlinked from WordPress.', 'kurl-short-url-manager-yourls')
                    : __('The YOURLS entry was already missing; the local shortlink was unlinked from WordPress.', 'kurl-short-url-manager-yourls'),
            ]);
        }
        self::clear_post_link_meta($post_id);
        delete_transient('kurl_dashboard_overview');
        wp_send_json_success(['message' => __('Shortlink unlinked from WordPress. The old entry still exists in YOURLS until you delete it there.', 'kurl-short-url-manager-yourls')]);
    }
    public static function ajax_test_api(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options_ajax(false);

        $saved = Kurl_Helpers::get_settings();

        $api_url = isset($_POST['api_url'])
            ? Kurl_Helpers::normalize_api_url(Kurl_Helpers::request_string($_POST['api_url']))
            : Kurl_Helpers::scalar_string($saved['api_url'] ?? '');
        $submitted_signature = isset($_POST['signature'])
            ? sanitize_text_field(Kurl_Helpers::request_string($_POST['signature']))
            : '';
        $signature = self::resolve_signature_for_api_url($api_url, $submitted_signature, $saved);

        if ($api_url === '' || $signature === '') {
            wp_send_json_error(['message' => __('Enter a valid YOURLS API URL and signature before testing.', 'kurl-short-url-manager-yourls')], 400);
        }

        $connection = ['api_url' => $api_url, 'signature' => $signature];
        $response   = Kurl_API::aggregate_stats($connection);
        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }

        $helper_info         = Kurl_API::extended_api_info($connection);
        $helper_found        = !empty($helper_info['available']);
        $helper_version      = sanitize_text_field(Kurl_Helpers::scalar_string($helper_info['version'] ?? ''));
        $helper_capabilities = Kurl_Helpers::sanitize_key_list(is_array($helper_info['capabilities'] ?? null) ? $helper_info['capabilities'] : []);
        $settings_match = self::urls_match($api_url, Kurl_Helpers::scalar_string($saved['api_url'] ?? ''))
            && hash_equals(Kurl_Helpers::scalar_string($saved['signature'] ?? ''), $signature);

        if ($settings_match) {
            $saved['api_extended']        = $helper_found ? 1 : 0;
            $saved['helper_version']       = $helper_version;
            $saved['helper_capabilities']  = $helper_capabilities;
            $saved['helper_checked_at']    = time();
            $helper_response = is_array($helper_info['response'] ?? null) ? $helper_info['response'] : [];
            $saved['helper_check_error']   = $helper_found ? '' : Kurl_Helpers::format_api_error($helper_response);
            update_option('kurl_settings', $saved, false);
            Kurl_Helpers::flush_settings_cache();
            delete_transient('kurl_dashboard_overview');
        }

        $tested_helper_status = [
            'api_extended'       => $helper_found ? 1 : 0,
            'helper_version'     => $helper_version,
            'helper_capabilities'=> $helper_capabilities,
        ];

        if ($helper_found) {
            $message = self::helper_is_current($tested_helper_status)
                ? __('Connection successful. Current bundled helper detected and verified.', 'kurl-short-url-manager-yourls')
                : sprintf(__('Connection successful, but the YOURLS helper is outdated or incomplete. Replace it with bundled version %s from the Settings page.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION);
        } else {
            $message = __('Connection successful. Standard API only.', 'kurl-short-url-manager-yourls');
        }
        if (!$settings_match) {
            $message .= ' ' . __('These credentials were tested only; save the settings to use them.', 'kurl-short-url-manager-yourls');
        }

        $stats = is_array($response['stats'] ?? null) ? $response['stats'] : [];
        $db_stats = is_array($response['db-stats'] ?? null) ? $response['db-stats'] : [];
        $total_links_value = $response['total_links'] ?? ($stats['total_links'] ?? ($db_stats['total_links'] ?? 0));
        $total_clicks_value = $response['total_clicks'] ?? ($stats['total_clicks'] ?? ($db_stats['total_clicks'] ?? 0));
        $total_links  = max(0, Kurl_Helpers::scalar_int($total_links_value));
        $total_clicks = max(0, Kurl_Helpers::scalar_int($total_clicks_value));

        wp_send_json_success([
            'message'        => $message,
            'total_links'    => $total_links,
            'total_clicks'   => $total_clicks,
            'helper_version' => $helper_version,
            'helper_current' => self::helper_is_current($tested_helper_status),
            'helper_status'  => self::helper_status_payload($settings_match ? Kurl_Helpers::get_settings() : $saved),
        ]);
    }
    public static function save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kurl-short-url-manager-yourls'));
        }
        check_admin_referer('kurl_save_settings');
        if (isset($_POST['disconnect_api']) && Kurl_Helpers::request_string($_POST['disconnect_api']) === '1') {
            $settings = Kurl_Helpers::get_settings();
            $settings['api_url'] = '';
            $settings['signature'] = '';
            $settings['api_extended'] = 0;
            $settings['helper_version'] = '';
            $settings['helper_capabilities'] = [];
            $settings['helper_checked_at'] = 0;
            $settings['helper_check_error'] = '';
            update_option('kurl_settings', $settings, false);
            Kurl_Helpers::flush_settings_cache();
            delete_transient('kurl_dashboard_overview');
            wp_safe_redirect(add_query_arg(['page' => 'kurl-settings', 'disconnected' => 1], admin_url('admin.php')));
            exit;
        }
        $raw_enabled_post_types = isset($_POST['enabled_post_types']) && is_array($_POST['enabled_post_types'])
            ? wp_unslash($_POST['enabled_post_types'])
            : [];
        $raw_api_url = isset($_POST['api_url']) ? Kurl_Helpers::request_string($_POST['api_url']) : '';
        $raw_signature = isset($_POST['signature']) ? Kurl_Helpers::request_string($_POST['signature']) : '';
        $existing_settings = Kurl_Helpers::get_settings();
        $raw_cache_minutes = isset($_POST['cache_minutes']) ? Kurl_Helpers::request_string($_POST['cache_minutes']) : '30';
        $raw_request_timeout = isset($_POST['request_timeout']) ? Kurl_Helpers::request_string($_POST['request_timeout']) : '15';

        $enabled_post_types = Kurl_Helpers::sanitize_key_list($raw_enabled_post_types);
        $public_post_types = get_post_types(['public' => true], 'names');
        $enabled_post_types = array_values(array_intersect($enabled_post_types, array_values($public_post_types)));
        $raw_api_url = trim($raw_api_url);
        $api_url = Kurl_Helpers::normalize_api_url($raw_api_url);
        if (($raw_api_url === '' && !empty($existing_settings['api_url'])) || ($raw_api_url !== '' && $api_url === '')) {
            wp_safe_redirect(add_query_arg([
                'page' => 'kurl-settings',
                'url_error' => 1,
            ], admin_url('admin.php')));
            exit;
        }
        $signature = $api_url !== ''
            ? self::resolve_signature_for_api_url($api_url, sanitize_text_field($raw_signature), $existing_settings)
            : '';
        if ($api_url !== '' && $signature === '') {
            wp_safe_redirect(add_query_arg([
                'page' => 'kurl-settings',
                'credential_error' => 1,
            ], admin_url('admin.php')));
            exit;
        }
        $settings = [
            'api_url'                => $api_url,
            'signature'              => $signature,
            'enabled_post_types'     => $enabled_post_types,
            'cache_minutes'          => max(1, absint($raw_cache_minutes)),
            'request_timeout'        => max(5, min(120, absint($raw_request_timeout))),
            'auto_create_on_publish' => isset($_POST['auto_create_on_publish']) && Kurl_Helpers::request_string($_POST['auto_create_on_publish']) === '1' ? 1 : 0,
            'api_extended'           => 0,
            'helper_version'         => '',
            'helper_capabilities'    => [],
            'helper_checked_at'      => 0,
            'helper_check_error'     => '',
        ];
        update_option('kurl_settings', $settings, false);
        Kurl_Helpers::flush_settings_cache();

        if ($settings['api_url'] !== '' && $settings['signature'] !== '') {
            self::refresh_helper_status(true);
        }
        update_option('kurl_delete_data', isset($_POST['delete_data']) && Kurl_Helpers::request_string($_POST['delete_data']) === '1' ? 1 : 0, false);
        delete_transient('kurl_dashboard_overview');
        wp_safe_redirect(add_query_arg(['page' => 'kurl-settings', 'updated' => 1], admin_url('admin.php')));
        exit;
    }
    public static function import_legacy(): void {
        self::assert_manage_options();
        check_admin_referer('kurl_import_legacy');
        $result = Kurl_Shortlinks::import_legacy();
        wp_safe_redirect(add_query_arg(['page' => 'kurl-settings', 'imported' => (int) $result['imported'], 'skipped' => (int) $result['skipped']], admin_url('admin.php')));
        exit;
    }

    public static function delete_legacy(): void {
        self::assert_manage_options();
        check_admin_referer('kurl_delete_legacy');
        $deleted = Kurl_Shortlinks::delete_legacy();
        wp_safe_redirect(add_query_arg(['page' => 'kurl-settings', 'deleted' => (int) $deleted], admin_url('admin.php')));
        exit;
    }

    public static function clear_log(): void {
        self::assert_manage_options();
        check_admin_referer('kurl_clear_log');
        Kurl_Logger::clear();
        wp_safe_redirect(add_query_arg(['page' => 'kurl-logs', 'cleared' => 1], admin_url('admin.php')));
        exit;
    }

    public static function cleanup_local(): void {
        self::assert_manage_options();
        check_admin_referer('kurl_cleanup_local');

        $report  = ['keywords_removed' => 0, 'stats_removed' => 0, 'urls_normalized' => 0, 'urls_removed' => 0];
        $last_id = 0;
        $limit   = 250;

        do {
            $post_ids = self::get_enabled_post_ids_batch($last_id, $limit);

            foreach ($post_ids as $post_id) {
                $post_id = (int) $post_id;
                if ($post_id <= 0) {
                    continue;
                }

                $last_id          = max($last_id, $post_id);
                $raw_managed_url   = trim(Kurl_Helpers::scalar_string(get_post_meta($post_id, KURL_META_URL, true)));
                $managed_shorturl  = Kurl_Helpers::sanitize_http_url($raw_managed_url);
                $shorturl          = $managed_shorturl !== '' ? $managed_shorturl : Kurl_Shortlinks::get_shorturl($post_id);
                $raw_keyword       = Kurl_Helpers::scalar_string(get_post_meta($post_id, KURL_META_KEYWORD, true));
                $keyword           = Kurl_Helpers::sanitize_keyword($raw_keyword);

                if ($raw_managed_url !== '' && $managed_shorturl === '') {
                    delete_post_meta($post_id, KURL_META_URL);
                    delete_post_meta($post_id, KURL_META_KEYWORD);
                    $had_stats = (bool) get_post_meta($post_id, KURL_META_STATS, true);
                    delete_post_meta($post_id, KURL_META_STATS);
                    $report['urls_removed']++;
                    if ($had_stats) {
                        $report['stats_removed']++;
                    }
                    if ($raw_keyword !== '') {
                        $report['keywords_removed']++;
                    }
                    continue;
                }

                if ($shorturl === '') {
                    if ($raw_keyword !== '') {
                        delete_post_meta($post_id, KURL_META_KEYWORD);
                        $report['keywords_removed']++;
                    }

                    if (get_post_meta($post_id, KURL_META_STATS, true)) {
                        delete_post_meta($post_id, KURL_META_STATS);
                        $report['stats_removed']++;
                    }

                    continue;
                }

                $normalized = Kurl_Helpers::sanitize_http_url($shorturl);
                if ($normalized === '') {
                    self::clear_post_link_meta($post_id);
                    $report['urls_removed']++;
                    continue;
                }
                if ($managed_shorturl !== '' && $normalized !== $raw_managed_url) {
                    update_post_meta($post_id, KURL_META_URL, $normalized);
                    $report['urls_normalized']++;
                }

                if ($raw_keyword !== '' && $keyword === '') {
                    delete_post_meta($post_id, KURL_META_KEYWORD);
                    $report['keywords_removed']++;
                }
            }
        } while (!empty($post_ids));

        Kurl_Logger::log('info', 'Local cleanup finished', $report);
        delete_transient('kurl_dashboard_overview');

        wp_safe_redirect(add_query_arg([
            'page'             => 'kurl-sync',
            'cleanup_done'     => 1,
            'keywords_removed' => (int) $report['keywords_removed'],
            'stats_removed'    => (int) $report['stats_removed'],
            'urls_normalized'  => (int) $report['urls_normalized'],
            'urls_removed'     => (int) $report['urls_removed'],
        ], admin_url('admin.php')));
        exit;
    }


    public static function reconcile_yourls(): void {
        self::assert_manage_options();
        check_admin_referer('kurl_reconcile_yourls');
        wp_safe_redirect(add_query_arg(['page' => 'kurl-sync', 'reconcile_mode' => 'batched'], admin_url('admin.php')));
        exit;
    }

    public static function ajax_reconcile_batch(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options_ajax(true);

        $batch_size = isset($_POST['batch_size'])
            ? max(1, min(25, absint(Kurl_Helpers::request_string($_POST['batch_size']))))
            : 5;
        $last_id = isset($_POST['last_id'])
            ? max(0, absint(Kurl_Helpers::request_string($_POST['last_id'])))
            : 0;
        $preview = isset($_POST['preview']) && Kurl_Helpers::request_string($_POST['preview']) === '1';

        $post_ids = self::get_reconcile_batch_post_ids($last_id, $batch_size);
        if (empty($post_ids)) {
            wp_send_json_success([
                'done'    => true,
                'last_id' => $last_id,
                'results' => [],
            ]);
        }

        $results     = [];
        $new_last_id = $last_id;
        $changed     = false;

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }

            $new_last_id = max($new_last_id, $post_id);
            $result = self::reconcile_single_post($post_id, !$preview);
            $results[] = $result;

            if (in_array($result['status'], ['imported', 'replaced'], true)) {
                $changed = true;
            }
        }

        if ($changed) {
            delete_transient('kurl_dashboard_overview');
        }

        wp_send_json_success([
            'done'    => false,
            'last_id' => $new_last_id,
            'results' => $results,
        ]);
    }

    private static function reconcile_single_post(int $post_id, bool $apply_changes): array {
        $title     = get_the_title($post_id);
        $permalink = get_permalink($post_id);

        if (!is_string($permalink) || $permalink === '') {
            return [
                'post_id' => $post_id,
                'title'   => $title,
                'status'  => 'skipped',
                'message' => __('Could not determine the permalink.', 'kurl-short-url-manager-yourls'),
            ];
        }

        $local            = Kurl_Shortlinks::get_shorturl($post_id);
        $keyword          = Kurl_Shortlinks::get_keyword($post_id);
        $helper_available = self::helper_has_capability('find_by_url', self::refresh_helper_status(false));
        $local_mismatch   = false;
        $expand_error     = [];
        $helper_error     = [];

        // A known local URL is authoritative when it expands correctly. Do this
        // before reverse lookup because YOURLS can allow duplicate long URLs.
        if ($local !== '') {
            $expand = Kurl_API::expand_shortlink($local);
            if (!empty($expand['ok'])) {
                $longurl = Kurl_API::extract_longurl($expand);
                if ($longurl !== '' && self::urls_match($longurl, $permalink)) {
                    return [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => 'verified',
                        'message' => __('Local short URL expands to the current permalink.', 'kurl-short-url-manager-yourls'),
                    ];
                }
                if ($longurl !== '') {
                    $local_mismatch = true;
                } else {
                    $expand_error = [
                        'message' => __('YOURLS returned no target URL while verifying the saved short URL.', 'kurl-short-url-manager-yourls'),
                    ];
                }
            } elseif (Kurl_API::is_not_found_response($expand)) {
                $local_mismatch = true;
            } else {
                $expand_error = $expand;
            }
        }

        if ($helper_available) {
            $helper = Kurl_API::find_by_longurl($permalink, $local);
            $found  = !empty($helper['ok']) ? Kurl_API::extract_shorturl($helper) : '';

            if ($found !== '') {
                if ($local !== '' && self::urls_match($local, $found)) {
                    return [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => 'verified',
                        'message' => __('The helper confirmed that the saved short URL belongs to the current permalink.', 'kurl-short-url-manager-yourls'),
                    ];
                }

                if ($local === '') {
                    if ($apply_changes) {
                        Kurl_Shortlinks::save_link($post_id, $found, $keyword);
                        Kurl_Logger::log('info', 'Reconcile imported shortlink from YOURLS', ['post_id' => $post_id, 'shorturl' => $found]);
                    }
                    return [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => $apply_changes ? 'imported' : 'would_import',
                        'message' => $apply_changes
                            ? sprintf(__('Imported existing YOURLS short URL: %s', 'kurl-short-url-manager-yourls'), $found)
                            : sprintf(__('Preview: would import existing YOURLS short URL: %s', 'kurl-short-url-manager-yourls'), $found),
                    ];
                }

                if (!self::urls_match($local, $found)) {
                    if ($apply_changes) {
                        Kurl_Shortlinks::save_link($post_id, $found, $keyword);
                        Kurl_Logger::log('info', 'Reconcile replaced local shortlink from YOURLS', ['post_id' => $post_id, 'old' => $local, 'new' => $found]);
                    }
                    return [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => $apply_changes ? 'replaced' : 'would_replace',
                        'message' => $apply_changes
                            ? sprintf(__('Replaced local short URL with YOURLS result: %s', 'kurl-short-url-manager-yourls'), $found)
                            : sprintf(__('Preview: would replace local short URL with YOURLS result: %s', 'kurl-short-url-manager-yourls'), $found),
                    ];
                }
            } elseif (empty($helper['ok']) && !Kurl_API::is_not_found_response($helper)) {
                $helper_error = $helper;
            }
        }

        if (!empty($helper_error)) {
            return [
                'post_id' => $post_id,
                'title'   => $title,
                'status'  => 'error',
                'message' => Kurl_Helpers::format_api_error($helper_error),
            ];
        }

        if (!empty($expand_error)) {
            return [
                'post_id' => $post_id,
                'title'   => $title,
                'status'  => 'error',
                'message' => Kurl_Helpers::format_api_error($expand_error),
            ];
        }

        if ($local_mismatch) {
            return [
                'post_id' => $post_id,
                'title'   => $title,
                'status'  => 'mismatch',
                'message' => __('Saved short URL does not match the current permalink in YOURLS.', 'kurl-short-url-manager-yourls'),
            ];
        }

        return [
            'post_id' => $post_id,
            'title'   => $title,
            'status'  => 'skipped',
            'message' => $helper_available
                ? __('No matching YOURLS entry was found for this permalink.', 'kurl-short-url-manager-yourls')
                : __('No local short URL to verify, and safe reverse lookup needs the helper plugin.', 'kurl-short-url-manager-yourls'),
        ];
    }

    public static function render_dashboard(): void {
        self::assert_manage_options();

        $settings      = Kurl_Helpers::get_settings();
        $dashboard     = self::get_dashboard_data();
        $saved_links   = (int) ($dashboard['saved_links'] ?? 0);
        $log_count     = (int) ($dashboard['log_count'] ?? 0);
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin dashboard query over plugin-managed meta.
        $recent_posts  = get_posts([
            'post_type'      => Kurl_Helpers::enabled_post_types(),
            'posts_per_page' => 10,
            'meta_key'       => KURL_META_URL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin dashboard query over plugin-managed meta.
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);

        $api_status_value = !$dashboard['configured']
            ? __('Not connected', 'kurl-short-url-manager-yourls')
            : ($dashboard['connected'] ? __('Connected', 'kurl-short-url-manager-yourls') : __('Connection failed', 'kurl-short-url-manager-yourls'));

        $api_status_note = !$dashboard['configured']
            ? __('Add your API URL and signature in Settings.', 'kurl-short-url-manager-yourls')
            : ($dashboard['connected']
                ? __('Last dashboard refresh reached your YOURLS API successfully.', 'kurl-short-url-manager-yourls')
                : ($dashboard['error'] !== '' ? $dashboard['error'] : __('The dashboard could not reach YOURLS right now.', 'kurl-short-url-manager-yourls')));

        echo '<div class="wrap kurl-admin">';
        echo '<div class="kurl-page-head"><div><h1>' . esc_html__('kURL Dashboard', 'kurl-short-url-manager-yourls') . '</h1><p class="kurl-subtitle">' . esc_html(sprintf(
                /* translators: %s: Plugin version number. */
                __('Version %s • Dashboard statistics, manual shortening, migration tools, logs, and bulk processing.', 'kurl-short-url-manager-yourls'),
                KURL_VERSION
            )) . '</p></div></div>';

        echo '<div class="kurl-cards">';
        echo wp_kses_post(self::card(__('API status', 'kurl-short-url-manager-yourls'), $api_status_value, $api_status_note));
        $helper_ui = self::helper_status_payload($settings);
        echo '<div class="kurl-card" id="kurl-helper-card"><div class="kurl-card-value" id="kurl-helper-card-value">' . esc_html($helper_ui['ui']['dashboard_value']) . '</div><div class="kurl-card-label">' . esc_html__('Helper extension', 'kurl-short-url-manager-yourls') . '</div><div class="kurl-card-note" id="kurl-helper-card-note">' . esc_html($helper_ui['ui']['dashboard_note']) . '</div></div>';
        echo wp_kses_post(self::card(__('Saved shortlinks', 'kurl-short-url-manager-yourls'), (string) $saved_links, __('Posts currently using a kURL entry in WordPress.', 'kurl-short-url-manager-yourls')));
        echo wp_kses_post(self::card(__('YOURLS total links', 'kurl-short-url-manager-yourls'), (string) $dashboard['total_links'], __('Total links reported by YOURLS.', 'kurl-short-url-manager-yourls')));
        echo wp_kses_post(self::card(__('YOURLS total clicks', 'kurl-short-url-manager-yourls'), (string) $dashboard['total_clicks'], __('Total clicks reported by YOURLS.', 'kurl-short-url-manager-yourls')));
        echo wp_kses_post(self::card(__('Log entries', 'kurl-short-url-manager-yourls'), (string) $log_count, __('Only the last 7 days are retained.', 'kurl-short-url-manager-yourls')));
        echo '</div>';

        if (!$dashboard['configured']) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The API is not configured yet. You can still review settings, but dashboard lists and manual YOURLS actions will stay inactive until you add your API credentials.', 'kurl-short-url-manager-yourls') . '</p></div>';
        } elseif (!$dashboard['connected'] && $dashboard['error'] !== '') {
            echo '<div class="notice notice-warning"><p>' . esc_html($dashboard['error']) . '</p></div>';
        }

        echo '<div class="kurl-grid kurl-grid--dashboard">';
        echo '<div class="kurl-panel kurl-dashboard-main">';
        echo '<h2>' . esc_html__('Manual shortener', 'kurl-short-url-manager-yourls') . '</h2>';
        echo '<p>' . esc_html__('Shorten any URL without saving it to WordPress. Safe lookup, delete, and regenerate use the helper plugin when available.', 'kurl-short-url-manager-yourls') . '</p>';
        echo '<div class="kurl-manual-box">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-manual-url">' . esc_html__('Target URL', 'kurl-short-url-manager-yourls') . '</label></th><td><input type="url" id="kurl-manual-url" class="regular-text code" placeholder="https://example.com/article"></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-manual-keyword">' . esc_html__('Keyword / Slug', 'kurl-short-url-manager-yourls') . '</label></th><td><input type="text" id="kurl-manual-keyword" class="regular-text code" placeholder="optional-custom-slug"><p class="description">' . esc_html__('Leave empty to let YOURLS use a random keyword. The Regenerate button uses the keyword field if you filled it in, otherwise it requests a new random keyword.', 'kurl-short-url-manager-yourls') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-manual-shorturl">' . esc_html__('Short URL', 'kurl-short-url-manager-yourls') . '</label></th><td><input type="text" id="kurl-manual-shorturl" class="regular-text code" readonly="readonly"></td></tr>';
        echo '</tbody></table>';
        echo '<p class="kurl-actions" style="margin-top:16px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;">';
        echo '<button type="button" class="button kurl-manual-check">' . esc_html__('Check YOURLS', 'kurl-short-url-manager-yourls') . '</button>';
        echo '<button type="button" class="button button-primary kurl-manual-generate">' . esc_html__('Generate / Update', 'kurl-short-url-manager-yourls') . '</button>';
        $regenerate_disabled = self::helper_is_current($settings) ? '' : ' disabled="disabled"';
        $delete_disabled     = self::helper_supports_delete($settings) ? '' : ' disabled="disabled"';
        echo '<button type="button" class="button kurl-manual-regenerate"' . $regenerate_disabled . '>' . esc_html__('Regenerate safely', 'kurl-short-url-manager-yourls') . '</button>';
        echo '<button type="button" class="button button-link-delete kurl-manual-delete" style="color:#d63638;"' . $delete_disabled . '>' . esc_html__('Delete', 'kurl-short-url-manager-yourls') . '</button>';
        echo '</p>';
        echo '<div class="kurl-inline-status kurl-manual-status"></div>';
        echo '<p class="description" style="margin-top:12px;">' . esc_html(sprintf(__('Tip: “Generate / Update” creates a link when the Short URL field is empty. If “Check YOURLS” found an existing link, updating it safely requires the current bundled kURL Helper (%s).', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION)) . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="kurl-panel kurl-dashboard-side">';
        echo '<h2>' . esc_html__('Top 10 all-time links', 'kurl-short-url-manager-yourls') . '</h2>';
        echo wp_kses_post(self::render_remote_links_table($dashboard['top_links'], 'top'));
        echo '</div>';

        echo '<div class="kurl-panel kurl-dashboard-main">';
        echo '<h2>' . esc_html__('Recent YOURLS activity across the whole instance', 'kurl-short-url-manager-yourls') . '</h2>';
        echo wp_kses_post(self::render_remote_links_table($dashboard['latest_links'], 'latest'));
        echo '</div>';

        echo '<div class="kurl-panel kurl-dashboard-side">';
        echo '<h2>' . esc_html__('Latest shortlinks saved in WordPress', 'kurl-short-url-manager-yourls') . '</h2>';
        if (!empty($recent_posts)) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Post', 'kurl-short-url-manager-yourls') . '</th><th>' . esc_html__('Short URL', 'kurl-short-url-manager-yourls') . '</th><th>' . esc_html__('Clicks', 'kurl-short-url-manager-yourls') . '</th></tr></thead><tbody>';
            foreach ($recent_posts as $post) {
                $shorturl = Kurl_Helpers::scalar_string(get_post_meta($post->ID, KURL_META_URL, true));
                $clicks   = Kurl_Helpers::scalar_int(Kurl_Shortlinks::get_stats($post->ID)['clicks'] ?? 0);
                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html(get_the_title($post)) . '</a></td>';
                echo '<td><code>' . esc_html($shorturl) . '</code></td>';
                echo '<td>' . esc_html((string) $clicks) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No saved kURL entries yet.', 'kurl-short-url-manager-yourls') . '</p>';
        }
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private static function get_dashboard_data(): array {
        $settings = Kurl_Helpers::get_settings();
        $cache    = get_transient('kurl_dashboard_overview');

        if (is_array($cache)) {
            return $cache;
        }

        $data = [
            'configured'   => Kurl_API::configured(),
            'connected'    => false,
            'error'        => '',
            'total_links'  => 0,
            'total_clicks' => 0,
            'saved_links'  => Kurl_Shortlinks::count_saved(),
            'log_count'    => Kurl_Logger::count_entries_fast(),
            'top_links'    => [],
            'latest_links' => [],
        ];

        if (!$data['configured']) {
            return $data;
        }

        $db_stats = Kurl_API::db_stats();
        if (!empty($db_stats['ok'])) {
            $data['connected']    = true;
            $data['total_links']  = Kurl_Helpers::scalar_int($db_stats['total_links'] ?? (is_array($db_stats['db-stats'] ?? null) ? ($db_stats['db-stats']['total_links'] ?? 0) : 0));
            $data['total_clicks'] = Kurl_Helpers::scalar_int($db_stats['total_clicks'] ?? (is_array($db_stats['db-stats'] ?? null) ? ($db_stats['db-stats']['total_clicks'] ?? 0) : 0));
        } else {
            $data['error'] = Kurl_Helpers::format_api_error($db_stats);
        }

        $top = Kurl_API::stats_list('top', 10);
        if (!empty($top['ok'])) {
            $data['connected'] = true;
            $data['top_links'] = self::extract_stats_links($top);
            if ($data['total_links'] === 0) {
                $data['total_links'] = Kurl_Helpers::scalar_int($top['total_links'] ?? (is_array($top['stats'] ?? null) ? ($top['stats']['total_links'] ?? 0) : 0));
            }
            if ($data['total_clicks'] === 0) {
                $data['total_clicks'] = Kurl_Helpers::scalar_int($top['total_clicks'] ?? (is_array($top['stats'] ?? null) ? ($top['stats']['total_clicks'] ?? 0) : 0));
            }
        } elseif ($data['error'] === '') {
            $data['error'] = Kurl_Helpers::format_api_error($top);
        }

        $latest = Kurl_API::stats_list('last', 10);
        if (!empty($latest['ok'])) {
            $data['connected']    = true;
            $data['latest_links'] = self::dedupe_remote_rows(self::extract_stats_links($latest), 'longurl', 10);
        } elseif ($data['error'] === '') {
            $data['error'] = Kurl_Helpers::format_api_error($latest);
        }

        if ($data['connected']) {
            set_transient('kurl_dashboard_overview', $data, ((int) $settings['cache_minutes']) * MINUTE_IN_SECONDS);
        }

        return $data;
    }

    private static function extract_stats_links(array $response): array {
        $links = [];
        if (isset($response['links']) && is_array($response['links'])) {
            $links = $response['links'];
        } elseif (isset($response['stats']['links']) && is_array($response['stats']['links'])) {
            $links = $response['stats']['links'];
        }

        $rows = [];
        foreach ($links as $key => $link) {
            if (!is_array($link)) {
                continue;
            }
            $rows[] = [
                'keyword'  => sanitize_text_field(is_scalar($link['keyword'] ?? null) ? (string) $link['keyword'] : (is_string($key) ? $key : '')),
                'shorturl' => Kurl_Helpers::sanitize_http_url(is_scalar($link['shorturl'] ?? null) ? (string) $link['shorturl'] : ''),
                'longurl'  => Kurl_Helpers::sanitize_http_url(is_scalar($link['url'] ?? null)
                    ? (string) $link['url']
                    : (is_scalar($link['longurl'] ?? null) ? (string) $link['longurl'] : '')),
                'title'    => sanitize_text_field(is_scalar($link['title'] ?? null) ? (string) $link['title'] : ''),
                'clicks'   => is_numeric($link['clicks'] ?? null) ? max(0, (int) $link['clicks']) : 0,
                'date'     => sanitize_text_field(is_scalar($link['timestamp'] ?? null)
                    ? (string) $link['timestamp']
                    : (is_scalar($link['date'] ?? null) ? (string) $link['date'] : '')),
            ];
        }

        return $rows;
    }


    private static function dedupe_remote_rows(array $rows, string $field, int $limit = 10): array {
        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $value = '';
            if (isset($row[$field]) && is_string($row[$field])) {
                $value = trim($row[$field]);
            }
            if ($value === '') {
                $value = isset($row['shorturl']) && is_string($row['shorturl']) ? trim($row['shorturl']) : '';
            }
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $unique[] = $row;
            if (count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }

    private static function render_remote_links_table(array $rows, string $mode): string {
        if (empty($rows)) {
            return '<p>' . esc_html__('No YOURLS link data is available yet for this section.', 'kurl-short-url-manager-yourls') . '</p>';
        }

        $html = '<div class="kurl-remote-table"><table class="widefat striped"><thead><tr>';
        if ($mode === 'latest') {
            $html .= '<th>' . esc_html__('Created', 'kurl-short-url-manager-yourls') . '</th>';
        }
        $html .= '<th>' . esc_html__('Short URL', 'kurl-short-url-manager-yourls') . '</th><th>' . esc_html__('Target', 'kurl-short-url-manager-yourls') . '</th>';
        if ($mode === 'top') {
            $html .= '<th>' . esc_html__('Clicks', 'kurl-short-url-manager-yourls') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $label = $row['title'] !== '' ? $row['title'] : $row['longurl'];
            $html .= '<tr>';
            if ($mode === 'latest') {
                $html .= '<td>' . esc_html($row['date'] !== '' ? $row['date'] : '—') . '</td>';
            }
            $html .= '<td><code><a href="' . esc_url($row['shorturl']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($row['shorturl']) . '</a></code></td>';
            $html .= '<td class="kurl-target-cell">';
            if ($row['longurl'] !== '') {
                $html .= '<a href="' . esc_url($row['longurl']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
            } else {
                $html .= esc_html($label !== '' ? $label : '—');
            }
            $html .= '</td>';
            if ($mode === 'top') {
                $html .= '<td>' . esc_html((string) $row['clicks']) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private static function card(string $label, string $value, string $note): string {
        return '<div class="kurl-card"><div class="kurl-card-value">' . esc_html($value) . '</div><div class="kurl-card-label">' . esc_html($label) . '</div><div class="kurl-card-note">' . esc_html($note) . '</div></div>';
    }

    public static function render_bulk(): void {
        self::assert_manage_options();
        $helper_ready = self::helper_has_capability('regenerate', Kurl_Helpers::get_settings());
        echo '<div class="wrap kurl-admin">';
        echo '<div class="kurl-page-head"><div><h1>' . esc_html__('kURL Bulk Generator', 'kurl-short-url-manager-yourls') . '</h1><p class="kurl-subtitle">' . esc_html__('AJAX batches help avoid long-running admin requests and timeout problems.', 'kurl-short-url-manager-yourls') . '</p></div></div>';
        echo '<div class="kurl-panel"><table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-bulk-post-type">' . esc_html__('Post type', 'kurl-short-url-manager-yourls') . '</label></th><td><select id="kurl-bulk-post-type">';
        foreach (Kurl_Helpers::enabled_post_types() as $post_type) {
            echo '<option value="' . esc_attr($post_type) . '">' . esc_html($post_type) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-bulk-batch-size">' . esc_html__('Batch size', 'kurl-short-url-manager-yourls') . '</label></th><td><select id="kurl-bulk-batch-size"><option>5</option><option selected>10</option><option>25</option></select></td></tr>';
        $overwrite_disabled = $helper_ready ? '' : ' disabled="disabled"';
        echo '<tr><th scope="row"><label for="kurl-bulk-mode">' . esc_html__('Existing links', 'kurl-short-url-manager-yourls') . '</label></th><td><select id="kurl-bulk-mode"><option value="skip">' . esc_html__('Skip existing', 'kurl-short-url-manager-yourls') . '</option><option value="import">' . esc_html__('Import old Better YOURLS first', 'kurl-short-url-manager-yourls') . '</option><option id="kurl-bulk-overwrite-option" value="overwrite"' . $overwrite_disabled . '>' . esc_html__('Regenerate / overwrite', 'kurl-short-url-manager-yourls') . '</option></select>';
        if (!$helper_ready) {
            echo '<p id="kurl-bulk-helper-help" class="description" style="color:#b45309;">' . esc_html(sprintf(__('Overwrite mode is disabled until bundled kURL Helper %s is installed and verified.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION)) . '</p>';
        } else {
            echo '<p id="kurl-bulk-helper-help" class="description" style="color:#166534;display:none;"></p>';
        }
        echo '</td></tr>';
        echo '</tbody></table><p><button class="button button-primary" id="kurl-bulk-start">' . esc_html__('Start bulk generation', 'kurl-short-url-manager-yourls') . '</button> <button class="button" id="kurl-bulk-stop">' . esc_html__('Stop', 'kurl-short-url-manager-yourls') . '</button></p><div class="kurl-progress"><div class="kurl-progress-bar" id="kurl-progress-bar"></div></div><div class="kurl-bulk-stats" id="kurl-bulk-stats"></div><div class="kurl-log-box kurl-light-log" id="kurl-bulk-log"></div></div></div>';
    }

    public static function render_sync_cleanup(): void {
        self::assert_manage_options();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters on a protected admin screen.
        $kurl_cleanup_done     = isset($_GET['cleanup_done']);
        $kurl_keywords_removed = isset($_GET['keywords_removed']) ? absint(Kurl_Helpers::request_string($_GET['keywords_removed'])) : 0;
        $kurl_stats_removed    = isset($_GET['stats_removed']) ? absint(Kurl_Helpers::request_string($_GET['stats_removed'])) : 0;
        $kurl_urls_normalized  = isset($_GET['urls_normalized']) ? absint(Kurl_Helpers::request_string($_GET['urls_normalized'])) : 0;
        $kurl_urls_removed     = isset($_GET['urls_removed']) ? absint(Kurl_Helpers::request_string($_GET['urls_removed'])) : 0;

        $kurl_reconcile_done   = isset($_GET['reconcile_done']);
        $kurl_imported_count   = isset($_GET['imported']) ? absint(Kurl_Helpers::request_string($_GET['imported'])) : 0;
        $kurl_replaced_count   = isset($_GET['replaced']) ? absint(Kurl_Helpers::request_string($_GET['replaced'])) : 0;
        $kurl_verified_count   = isset($_GET['verified']) ? absint(Kurl_Helpers::request_string($_GET['verified'])) : 0;
        $kurl_mismatches_count = isset($_GET['mismatches']) ? absint(Kurl_Helpers::request_string($_GET['mismatches'])) : 0;
        $kurl_skipped_count    = isset($_GET['skipped']) ? absint(Kurl_Helpers::request_string($_GET['skipped'])) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap kurl-admin">';
        echo '<div class="kurl-page-head"><div><h1>' . esc_html__('Sync & Cleanup', 'kurl-short-url-manager-yourls') . '</h1><p class="kurl-subtitle">' . esc_html__('Experimental tools to compare WordPress content with your YOURLS database and clean stale local data.', 'kurl-short-url-manager-yourls') . '</p></div></div>';

        if ($kurl_cleanup_done) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                /* translators: 1: Number of keywords removed. 2: Number of stats entries removed. 3: Number of URLs normalized. 4: Number of invalid URL records removed. */
                __('Local cleanup finished. Keywords removed: %1$d, stats removed: %2$d, URLs normalized: %3$d, invalid URL records removed: %4$d.', 'kurl-short-url-manager-yourls'),
                $kurl_keywords_removed,
                $kurl_stats_removed,
                $kurl_urls_normalized,
                $kurl_urls_removed
            )) . '</p></div>';
        }

        if ($kurl_reconcile_done) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                /* translators: 1: Imported count. 2: Replaced count. 3: Verified count. 4: Mismatch count. 5: Skipped count. */
                __('Reconciliation finished. Imported: %1$d, replaced: %2$d, verified: %3$d, mismatches: %4$d, skipped: %5$d.', 'kurl-short-url-manager-yourls'),
                $kurl_imported_count,
                $kurl_replaced_count,
                $kurl_verified_count,
                $kurl_mismatches_count,
                $kurl_skipped_count
            )) . '</p></div>';
        }
        echo '<div class="kurl-grid">';
        echo '<div class="kurl-panel"><h2>' . esc_html__('Local database cleanup', 'kurl-short-url-manager-yourls') . '</h2><p>' . esc_html__('Removes stale keywords and cached stats that no longer belong to a saved short URL and normalizes stored URLs.', 'kurl-short-url-manager-yourls') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('kurl_cleanup_local');
        echo '<input type="hidden" name="action" value="kurl_cleanup_local">';
        submit_button(__('Clean local database', 'kurl-short-url-manager-yourls'), 'secondary', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Run the local cleanup now?', 'kurl-short-url-manager-yourls')) . "');"]);
        echo '</form></div>';
        echo '<div class="kurl-panel"><h2>' . esc_html__('YOURLS reconcile / compare', 'kurl-short-url-manager-yourls') . '</h2><p>' . esc_html__('Checks enabled posts against YOURLS in AJAX batches to avoid timeouts. If the helper plugin can find a matching long URL, kURL can import or replace the local entry. Existing local short URLs are also verified against the current permalink.', 'kurl-short-url-manager-yourls') . '</p><p><strong>' . esc_html__('Experimental:', 'kurl-short-url-manager-yourls') . '</strong> ' . esc_html__('Start with preview mode, review the report, and only then apply changes. Back up your WordPress database before applying reconciliation changes on a large site.', 'kurl-short-url-manager-yourls') . '</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-reconcile-batch-size">' . esc_html__('Batch size', 'kurl-short-url-manager-yourls') . '</label></th><td><select id="kurl-reconcile-batch-size"><option selected>5</option><option>10</option><option>25</option></select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Mode', 'kurl-short-url-manager-yourls') . '</th><td><label><input type="checkbox" id="kurl-reconcile-preview" checked="checked"> ' . esc_html__('Preview only (do not change WordPress data)', 'kurl-short-url-manager-yourls') . '</label></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="button" class="button button-primary" id="kurl-reconcile-start">' . esc_html__('Start reconciliation', 'kurl-short-url-manager-yourls') . '</button> <button type="button" class="button" id="kurl-reconcile-stop">' . esc_html__('Stop', 'kurl-short-url-manager-yourls') . '</button></p>';
        echo '<div class="kurl-progress"><div class="kurl-progress-bar" id="kurl-reconcile-progress-bar"></div></div>';
        echo '<div class="kurl-bulk-stats" id="kurl-reconcile-stats"></div>';
        echo '<div class="kurl-log-box kurl-light-log" id="kurl-reconcile-log"></div>';
        echo '</div>';
        echo '</div></div>';
    }

    public static function render_logs(): void {
        self::assert_manage_options();
        $entries = Kurl_Logger::get_entries();
        echo '<div class="wrap kurl-admin"><div class="kurl-page-head"><div><h1>' . esc_html__('kURL Logs', 'kurl-short-url-manager-yourls') . '</h1><p class="kurl-subtitle">' . esc_html__('The log keeps only the last 7 days and has size caps so WordPress does not get overloaded.', 'kurl-short-url-manager-yourls') . '</p></div></div>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters.
        if (!empty($_GET['cleared'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Log cleared.', 'kurl-short-url-manager-yourls') . '</p></div>';
        }
        echo '<div class="kurl-panel"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:18px;">';
        wp_nonce_field('kurl_clear_log');
        echo '<input type="hidden" name="action" value="kurl_clear_log">';
        submit_button(__('Delete log', 'kurl-short-url-manager-yourls'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Delete the current log?', 'kurl-short-url-manager-yourls')) . "');"]);
        echo '</form><div class="kurl-log-box kurl-light-log">';
        if (!empty($entries)) {
            foreach ($entries as $entry) {
                $time = wp_date('Y-m-d H:i:s', (int) $entry['time']);
                $context = !empty($entry['context']) ? wp_json_encode($entry['context']) : '';
                echo '<div class="kurl-log-row kurl-level-' . esc_attr((string) $entry['level']) . '"><span class="kurl-log-time">' . esc_html($time) . '</span><span class="kurl-log-level">' . esc_html(strtoupper((string) $entry['level'])) . '</span><span class="kurl-log-message">' . esc_html((string) $entry['message']) . '</span>';
                if ($context !== '') {
                    echo '<pre class="kurl-log-context">' . esc_html($context) . '</pre>';
                }
                echo '</div>';
            }
        } else {
            echo '<p>' . esc_html__('No log entries in the last 7 days.', 'kurl-short-url-manager-yourls') . '</p>';
        }
        echo '</div></div></div>';
    }

    public static function render_settings(): void {
        self::assert_manage_options();
        $settings       = Kurl_Helpers::get_settings();
        $post_types     = get_post_types(['public' => true], 'objects');
        $helper_found   = !empty($settings['api_extended']);
        $helper_version = sanitize_text_field(Kurl_Helpers::scalar_string($settings['helper_version'] ?? ''));
        $helper_current = self::helper_is_current($settings);

        echo '<div class="wrap kurl-admin"><div class="kurl-page-head"><div><h1>' . esc_html__('kURL Settings', 'kurl-short-url-manager-yourls') . '</h1></div></div>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters.
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'kurl-short-url-manager-yourls') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters.
        if (!empty($_GET['disconnected'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Successfully disconnected. Your API credentials have been cleared.', 'kurl-short-url-manager-yourls') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameter.
        if (!empty($_GET['credential_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('The API URL changed. Enter the signature token for the new YOURLS endpoint; kURL will not send the previously saved token to a different host or path.', 'kurl-short-url-manager-yourls') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameter.
        if (!empty($_GET['url_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Enter a complete HTTP or HTTPS URL for your YOURLS installation. To remove a configured connection, use Disconnect. The existing connection was not changed.', 'kurl-short-url-manager-yourls') . '</p></div>';
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters on a protected admin screen.
        $legacy_import_notice  = isset($_GET['imported']);
        $legacy_imported_count = isset($_GET['imported']) ? absint(Kurl_Helpers::request_string($_GET['imported'])) : 0;
        $legacy_skipped_count  = isset($_GET['skipped']) ? absint(Kurl_Helpers::request_string($_GET['skipped'])) : 0;
        $legacy_deleted_notice = isset($_GET['deleted']);
        $legacy_deleted_count  = isset($_GET['deleted']) ? absint(Kurl_Helpers::request_string($_GET['deleted'])) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($legacy_import_notice) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Imported %1$d old links and skipped %2$d existing ones.', 'kurl-short-url-manager-yourls'), $legacy_imported_count, $legacy_skipped_count)) . '</p></div>';
        }
        if ($legacy_deleted_notice) {
            echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('Deleted %d old Better YOURLS meta rows.', 'kurl-short-url-manager-yourls'), $legacy_deleted_count)) . '</p></div>';
        }

        echo '<div class="kurl-grid">';
        echo '<div class="kurl-panel"><div class="kurl-test-box"><div><h2>' . esc_html__('API connection', 'kurl-short-url-manager-yourls') . '</h2>';
        if ($helper_current) {
            echo '<p id="kurl-helper-connection-status" style="color:#166534;font-weight:600;">' . esc_html(sprintf(__('✅ kURL Helper %s detected. Remote deletion, safe lookup, and safe regeneration are enabled.', 'kurl-short-url-manager-yourls'), $helper_version)) . '</p>';
        } elseif ($helper_found) {
            $shown_version = $helper_version !== '' ? $helper_version : __('unknown version', 'kurl-short-url-manager-yourls');
            echo '<p id="kurl-helper-connection-status" style="color:#b45309;font-weight:600;">' . esc_html(sprintf(__('⚠️ kURL Helper %1$s is outdated or incomplete. Replace it with bundled version %2$s before using helper-dependent actions.', 'kurl-short-url-manager-yourls'), $shown_version, KURL_HELPER_VERSION)) . '</p>';
        } else {
            echo '<p id="kurl-helper-connection-status" style="color:#b45309;font-weight:600;">' . esc_html__('⚠️ Standard API only. Remote deletion and safe reverse lookup are disabled.', 'kurl-short-url-manager-yourls') . '</p>';
        }
        echo '</div><div><button type="button" class="button button-primary button-large" id="kurl-test-api">' . esc_html__('Test API', 'kurl-short-url-manager-yourls') . '</button><div id="kurl-test-api-result" class="kurl-test-result"></div></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('kurl_save_settings');
        echo '<input type="hidden" name="action" value="kurl_save_settings"><table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-api-url">' . esc_html__('YOURLS API URL', 'kurl-short-url-manager-yourls') . '</label></th><td><input id="kurl-api-url" type="url" name="api_url" class="regular-text code" value="' . esc_attr($settings['api_url']) . '" placeholder="https://yalla.li"><p class="description">' . wp_kses_post(__('Enter your main YOURLS domain (for example <code>https://yalla.li</code>). kURL will automatically append <code>/yourls-api.php</code> when needed.', 'kurl-short-url-manager-yourls')) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-signature">' . esc_html__('Signature token', 'kurl-short-url-manager-yourls') . '</label></th><td><input id="kurl-signature" type="password" name="signature" autocomplete="new-password" class="regular-text code" value="" placeholder="' . esc_attr__('Leave blank only when keeping the same API URL', 'kurl-short-url-manager-yourls') . '"><p class="description">' . wp_kses_post(__('You can find your signature token in the YOURLS admin area under <strong>Tools → Secure passwordless API call</strong>.', 'kurl-short-url-manager-yourls')) . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Enabled post types', 'kurl-short-url-manager-yourls') . '</th><td>';
        foreach ($post_types as $post_type => $object) {
            $checked = in_array($post_type, $settings['enabled_post_types'], true) ? 'checked' : '';
            echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="enabled_post_types[]" value="' . esc_attr($post_type) . '" ' . esc_attr($checked) . '> ' . esc_html($object->labels->singular_name) . '</label>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="kurl-cache-minutes">' . esc_html__('Cache (minutes)', 'kurl-short-url-manager-yourls') . '</label></th><td><input id="kurl-cache-minutes" type="number" name="cache_minutes" min="1" value="' . esc_attr((string) $settings['cache_minutes']) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-request-timeout">' . esc_html__('API timeout', 'kurl-short-url-manager-yourls') . '</label></th><td><input id="kurl-request-timeout" type="number" name="request_timeout" min="5" max="120" value="' . esc_attr((string) $settings['request_timeout']) . '"></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Auto create', 'kurl-short-url-manager-yourls') . '</th><td><label><input type="checkbox" name="auto_create_on_publish" value="1" ' . checked(1, (int) $settings['auto_create_on_publish'], false) . '> ' . esc_html__('Automatically create a short URL when publishing', 'kurl-short-url-manager-yourls') . '</label></td></tr>';
        $delete_data = Kurl_Helpers::scalar_int(get_option('kurl_delete_data', 0)) === 1 ? 1 : 0;
        echo '<tr><th scope="row">' . esc_html__('Uninstall behavior', 'kurl-short-url-manager-yourls') . '</th><td><label><input type="checkbox" name="delete_data" value="1" ' . checked(1, $delete_data, false) . '> ' . esc_html__('Delete all kURL data on uninstall', 'kurl-short-url-manager-yourls') . '</label><p class="description" style="color:#d63638;font-weight:600;">' . esc_html__('Warning: if enabled, all plugin data, including links, cached stats, and logs, will be permanently removed when the plugin is uninstalled.', 'kurl-short-url-manager-yourls') . '</p></td></tr>';
        echo '</tbody></table><p class="submit">';
        submit_button(__('Save settings', 'kurl-short-url-manager-yourls'), 'primary', 'submit', false);
        echo ' ';
        if (!empty($settings['api_url'])) {
            submit_button(__('Disconnect API', 'kurl-short-url-manager-yourls'), 'delete', 'disconnect_api', false, ['onclick' => "return confirm('" . esc_js(__('Are you sure you want to disconnect? This will clear your API URL and signature.', 'kurl-short-url-manager-yourls')) . "');"]);
        }
        echo '</p></form></div>';

        $plugin_code  = self::get_helper_plugin_code();
        $download_url = wp_nonce_url(
            admin_url('admin-post.php?action=kurl_download_helper'),
            'kurl_download_helper'
        );
        $panel_color = $helper_current ? '#16a34a' : '#f59e0b';
        $heading = $helper_current
            ? __('kURL Helper', 'kurl-short-url-manager-yourls')
            : ($helper_found ? __('Update the kURL Helper plugin', 'kurl-short-url-manager-yourls') : __('Enable remote deletion and safe lookup (optional)', 'kurl-short-url-manager-yourls'));

        echo '<div class="kurl-panel" id="kurl-helper-panel" style="border-left:4px solid ' . esc_attr($panel_color) . ';"><h2 id="kurl-helper-panel-heading">' . esc_html($heading) . '</h2>';
        if ($helper_current) {
            echo '<p id="kurl-helper-panel-message" style="color:#166534;font-weight:600;">' . esc_html(sprintf(__('The current bundled helper version %s is installed and verified.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION)) . '</p>';
            echo '<p class="description">' . esc_html__('The helper file remains available here so it can be reinstalled or replaced without obtaining a separate ZIP.', 'kurl-short-url-manager-yourls') . '</p>';
        } elseif ($helper_found) {
            $shown_version = $helper_version !== '' ? $helper_version : __('unknown version', 'kurl-short-url-manager-yourls');
            echo '<p id="kurl-helper-panel-message" style="color:#b45309;font-weight:600;">' . esc_html(sprintf(__('YOURLS is using kURL Helper %1$s. Replace it with the bundled version %2$s before using remote deletion, lookup, regeneration, overwrite, or reconciliation.', 'kurl-short-url-manager-yourls'), $shown_version, KURL_HELPER_VERSION)) . '</p>';
        } else {
            echo '<p id="kurl-helper-panel-message">' . esc_html__('The standard YOURLS API can create links and return statistics. Install the bundled helper only when you also want remote deletion, safe reverse lookup, regeneration, overwrite, or reconciliation.', 'kurl-short-url-manager-yourls') . '</p>';
        }

        echo '<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:16px;">';
        echo '<a class="button button-primary" href="' . esc_url($download_url) . '">' . esc_html__('Download bundled plugin.php', 'kurl-short-url-manager-yourls') . '</a>';
        echo '<button type="button" class="button kurl-copy-code">' . esc_html__('Copy code to clipboard', 'kurl-short-url-manager-yourls') . '</button>';
        echo '<span class="kurl-copy-status" style="color:#166534;display:none;font-weight:600;">✓ ' . esc_html__('Copied!', 'kurl-short-url-manager-yourls') . '</span></p>';

        echo '<details' . ($helper_current ? '' : ' open') . '><summary style="cursor:pointer;font-weight:600;margin:12px 0;">' . esc_html__('Installation and update instructions', 'kurl-short-url-manager-yourls') . '</summary>';
        echo '<ol><li>' . esc_html__('Connect to your YOURLS server via FTP, SFTP, your hosting file manager, or SSH.', 'kurl-short-url-manager-yourls') . '</li><li>' . wp_kses_post(__('Open <code>user/plugins/kurl-api/</code>. Create the folder if it does not exist.', 'kurl-short-url-manager-yourls')) . '</li><li>' . wp_kses_post(__('Replace or create <code>plugin.php</code> with the downloaded file or the copied code below.', 'kurl-short-url-manager-yourls')) . '</li><li>' . esc_html__('Open the YOURLS plugin manager and activate “kURL Helper” if it is not already active.', 'kurl-short-url-manager-yourls') . '</li><li>' . esc_html__('Return to this page and click “Test API”. kURL will verify the installed helper version and capabilities automatically.', 'kurl-short-url-manager-yourls') . '</li></ol>';
        echo '<textarea readonly id="kurl-extension-code" class="large-text code" rows="36" style="font-family:monospace;font-size:12px;background:#f8fafc;margin-top:8px;">' . esc_textarea($plugin_code) . '</textarea></details></div>';

        echo '<div class="kurl-panel"><h2>' . esc_html__('Migration tools', 'kurl-short-url-manager-yourls') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:16px;">';
        wp_nonce_field('kurl_import_legacy');
        echo '<input type="hidden" name="action" value="kurl_import_legacy">';
        submit_button(__('Import from Better YOURLS', 'kurl-short-url-manager-yourls'), 'secondary', 'submit', false);
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('kurl_delete_legacy');
        echo '<input type="hidden" name="action" value="kurl_delete_legacy">';
        submit_button(__('Delete old Better YOURLS data', 'kurl-short-url-manager-yourls'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Delete old Better YOURLS data?', 'kurl-short-url-manager-yourls')) . "');"]);
        echo '</form><p class="description">' . esc_html__('Import first, verify a few posts, then delete the old Better YOURLS data once everything looks correct.', 'kurl-short-url-manager-yourls') . '</p></div>';
        echo '</div></div>';
    }
    private static function assert_manage_options_ajax(bool $require_configured = true): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-short-url-manager-yourls')], 403);
        }
        if ($require_configured && !Kurl_API::configured()) {
            wp_send_json_error(['message' => __('Please configure the YOURLS API first.', 'kurl-short-url-manager-yourls')], 400);
        }
    }

    private static function assert_manage_options(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kurl-short-url-manager-yourls'));
        }
    }

    private static function should_enqueue_assets(string $hook): bool {
        return $hook === 'post.php' || $hook === 'post-new.php' || strpos($hook, 'kurl') !== false;
    }

    private static function clear_post_link_meta(int $post_id): void {
        delete_post_meta($post_id, KURL_META_URL);
        delete_post_meta($post_id, KURL_META_KEYWORD);
        delete_post_meta($post_id, KURL_META_STATS);
        delete_post_meta($post_id, KURL_OLD_META_URL);
    }

    private static function resolve_signature_for_api_url(string $api_url, string $submitted_signature, array $saved_settings): string {
        $submitted_signature = sanitize_text_field($submitted_signature);
        if ($submitted_signature !== '') {
            return $submitted_signature;
        }

        $saved_api_url = Kurl_Helpers::normalize_api_url(Kurl_Helpers::scalar_string($saved_settings['api_url'] ?? ''));
        $saved_signature = sanitize_text_field(Kurl_Helpers::scalar_string($saved_settings['signature'] ?? ''));
        if ($api_url !== '' && $saved_api_url !== '' && hash_equals($saved_api_url, $api_url)) {
            return $saved_signature;
        }

        return '';
    }

    private static function shorturl_matches_keyword(string $shorturl, string $keyword): bool {
        $slug = Kurl_Helpers::keyword_from_shorturl($shorturl);
        return $slug !== '' && $slug === Kurl_Helpers::sanitize_keyword($keyword);
    }

    private static function helper_supports_delete(?array $settings = null): bool {
        $settings = is_array($settings) ? $settings : Kurl_Helpers::get_settings();
        return self::helper_has_capability('delete', $settings);
    }

    private static function helper_is_current(?array $settings = null): bool {
        $settings = is_array($settings) ? $settings : Kurl_Helpers::get_settings();
        if (empty($settings['api_extended']) || !self::helper_version_is_current(Kurl_Helpers::scalar_string($settings['helper_version'] ?? ''))) {
            return false;
        }

        $capabilities = is_array($settings['helper_capabilities'] ?? null) ? $settings['helper_capabilities'] : [];
        foreach (['delete', 'find_by_url', 'regenerate'] as $capability) {
            if (!in_array($capability, $capabilities, true)) {
                return false;
            }
        }
        return true;
    }

    private static function helper_has_capability(string $capability, ?array $settings = null): bool {
        $settings = is_array($settings) ? $settings : Kurl_Helpers::get_settings();
        $capability = sanitize_key($capability);
        return $capability !== ''
            && self::helper_is_current($settings)
            && in_array($capability, is_array($settings['helper_capabilities'] ?? null) ? $settings['helper_capabilities'] : [], true);
    }

    private static function helper_version_is_current(string $version): bool {
        $version = trim($version);
        return $version !== '' && version_compare($version, KURL_HELPER_VERSION, '==');
    }

    private static function helper_status_needs_refresh(?array $settings = null): bool {
        if (!Kurl_API::configured()) {
            return false;
        }

        $installed_version = sanitize_text_field(Kurl_Helpers::scalar_string(get_option('kurl_installed_version', '')));
        if ($installed_version !== KURL_VERSION) {
            return true;
        }

        $settings = is_array($settings) ? $settings : Kurl_Helpers::get_settings();
        $last_check = Kurl_Helpers::scalar_int($settings['helper_checked_at'] ?? 0);
        return $last_check <= 0 || $last_check < (time() - self::HELPER_STATUS_TTL);
    }

    private static function helper_status_snapshot(array $settings): array {
        return [
            'api_extended' => !empty($settings['api_extended']) ? 1 : 0,
            'version'      => sanitize_text_field(Kurl_Helpers::scalar_string($settings['helper_version'] ?? '')),
            'capabilities' => Kurl_Helpers::sanitize_key_list(is_array($settings['helper_capabilities'] ?? null) ? $settings['helper_capabilities'] : []),
            'error'        => sanitize_text_field(Kurl_Helpers::scalar_string($settings['helper_check_error'] ?? '')),
        ];
    }

    private static function helper_status_payload(array $settings): array {
        $current = self::helper_is_current($settings);
        $found = !empty($settings['api_extended']);
        $version = sanitize_text_field(Kurl_Helpers::scalar_string($settings['helper_version'] ?? ''));
        $shown_version = $version !== '' ? $version : __('unknown version', 'kurl-short-url-manager-yourls');
        $delete_available = self::helper_has_capability('delete', $settings);
        $regenerate_available = self::helper_has_capability('regenerate', $settings);
        $find_available = self::helper_has_capability('find_by_url', $settings);

        if ($current) {
            $dashboard_value = __('Active', 'kurl-short-url-manager-yourls');
            $dashboard_note = sprintf(__('Bundled helper %s is verified. Safe lookup, remote deletion, and reconciliation are available.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION);
            $connection_message = sprintf(__('✅ kURL Helper %s detected. Remote deletion, safe lookup, and safe regeneration are enabled.', 'kurl-short-url-manager-yourls'), $version);
            $panel_heading = __('kURL Helper', 'kurl-short-url-manager-yourls');
            $panel_message = sprintf(__('The current bundled helper version %s is installed and verified.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION);
            $panel_color = '#16a34a';
            $message_color = '#166534';
        } elseif ($found) {
            $dashboard_value = __('Update required', 'kurl-short-url-manager-yourls');
            $dashboard_note = sprintf(__('An outdated or incomplete helper was detected. Install bundled version %s before using helper-dependent actions.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION);
            $connection_message = sprintf(__('⚠️ kURL Helper %1$s is outdated or incomplete. Replace it with bundled version %2$s before using helper-dependent actions.', 'kurl-short-url-manager-yourls'), $shown_version, KURL_HELPER_VERSION);
            $panel_heading = __('Update the kURL Helper plugin', 'kurl-short-url-manager-yourls');
            $panel_message = sprintf(__('YOURLS is using kURL Helper %1$s. Replace it with the bundled version %2$s before using remote deletion, lookup, regeneration, overwrite, or reconciliation.', 'kurl-short-url-manager-yourls'), $shown_version, KURL_HELPER_VERSION);
            $panel_color = '#f59e0b';
            $message_color = '#b45309';
        } else {
            $dashboard_value = __('Standard API only', 'kurl-short-url-manager-yourls');
            $dashboard_note = __('Install the bundled helper to enable safe lookup, remote deletion, and advanced reconciliation.', 'kurl-short-url-manager-yourls');
            $connection_message = __('⚠️ Standard API only. Remote deletion and safe reverse lookup are disabled.', 'kurl-short-url-manager-yourls');
            $panel_heading = __('Enable remote deletion and safe lookup (optional)', 'kurl-short-url-manager-yourls');
            $panel_message = __('The standard YOURLS API can create links and return statistics. Install the bundled helper only when you also want remote deletion, safe reverse lookup, regeneration, overwrite, or reconciliation.', 'kurl-short-url-manager-yourls');
            $panel_color = '#f59e0b';
            $message_color = '#b45309';
        }

        return [
            'current'              => $current,
            'found'                => $found,
            'version'              => $version,
            'capabilities'         => Kurl_Helpers::sanitize_key_list(is_array($settings['helper_capabilities'] ?? null) ? $settings['helper_capabilities'] : []),
            'delete_available'     => $delete_available,
            'regenerate_available' => $regenerate_available,
            'find_available'       => $find_available,
            'ui'                   => [
                'delete_label'       => $delete_available ? __('Delete & Unlink', 'kurl-short-url-manager-yourls') : __('Unlink Locally', 'kurl-short-url-manager-yourls'),
                'confirm_delete'     => $delete_available
                    ? __('Are you sure you want to permanently delete this shortlink from WordPress and your YOURLS database?', 'kurl-short-url-manager-yourls')
                    : __('Are you sure you want to unlink this shortlink from WordPress? You will still need to delete the old link manually in YOURLS before reusing the same custom slug.', 'kurl-short-url-manager-yourls'),
                'dashboard_value'    => $dashboard_value,
                'dashboard_note'     => $dashboard_note,
                'connection_message' => $connection_message,
                'panel_heading'      => $panel_heading,
                'panel_message'      => $panel_message,
                'panel_color'        => $panel_color,
                'message_color'      => $message_color,
                'update_notice_title'=> __('kURL Helper update required.', 'kurl-short-url-manager-yourls'),
                'update_notice_text' => sprintf(__('YOURLS reports helper version %1$s, but this kURL release requires bundled helper %2$s with all advertised capabilities. Helper-dependent actions are disabled until you replace plugin.php from the kURL Settings page.', 'kurl-short-url-manager-yourls'), $shown_version, KURL_HELPER_VERSION),
                'update_notice_link' => __('Open kURL Settings', 'kurl-short-url-manager-yourls'),
                'bulk_help'          => sprintf(__('Overwrite mode is disabled until bundled kURL Helper %s is installed and verified.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION),
            ],
        ];
    }

    private static function clear_helper_status_if_needed(): void {
        $settings = Kurl_Helpers::get_settings();
        if (empty($settings['api_extended']) && empty($settings['helper_version']) && empty($settings['helper_capabilities']) && empty($settings['helper_checked_at']) && empty($settings['helper_check_error'])) {
            return;
        }

        $settings['api_extended'] = 0;
        $settings['helper_version'] = '';
        $settings['helper_capabilities'] = [];
        $settings['helper_checked_at'] = 0;
        $settings['helper_check_error'] = '';
        update_option('kurl_settings', $settings, false);
        Kurl_Helpers::flush_settings_cache();
    }

    private static function urls_match(string $left, string $right): bool {
        $left  = untrailingslashit(trim($left));
        $right = untrailingslashit(trim($right));
        return $left !== '' && $right !== '' && hash_equals($left, $right);
    }

    private static function get_reconcile_batch_post_ids(int $last_id, int $batch_size): array {
        add_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10, 2);

        try {
            $query = new WP_Query([
            'post_type'              => Kurl_Helpers::enabled_post_types(),
            'post_status'            => 'any',
            'posts_per_page'         => $batch_size,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters'       => false,
            'kurl_reconcile_after_id' => $last_id,
            ]);
        } finally {
            remove_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10);
        }

        return isset($query) && is_array($query->posts) ? $query->posts : [];
    }

    public static function filter_reconcile_posts_where_after_id(string $where, WP_Query $query): string {
        global $wpdb;
        $last_id = absint($query->get('kurl_reconcile_after_id'));
        if ($last_id > 0) {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $last_id);
        }
        return $where;
    }

    private static function get_enabled_post_ids_batch(int $last_id = 0, int $limit = 250): array {
        add_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10, 2);

        try {
            $query = new WP_Query([
            'post_type'              => Kurl_Helpers::enabled_post_types(),
            'post_status'            => 'any',
            'posts_per_page'         => max(1, $limit),
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters'       => false,
            'kurl_reconcile_after_id' => $last_id,
            ]);
        } finally {
            remove_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10);
        }

        return isset($query) && is_array($query->posts) ? $query->posts : [];
    }

    private static function helper_plugin_path(): string {
        return KURL_PATH . 'yourls-helper/plugin.php.txt';
    }

    private static function get_helper_plugin_code(): string {
        $path = self::helper_plugin_path();
        if (!is_readable($path)) {
            return '';
        }
        $code = file_get_contents($path);
        return is_string($code) ? $code : '';
    }

}
