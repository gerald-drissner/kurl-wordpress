<?php

defined('ABSPATH') || exit;

final class Kurl_Admin {

    private static int $reconcile_cursor_last_id = 0;

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);

        add_action('wp_ajax_kurl_generate_post_link', [__CLASS__, 'ajax_generate_post_link']);
        add_action('wp_ajax_kurl_refresh_post_stats', [__CLASS__, 'ajax_refresh_post_stats']);
        add_action('wp_ajax_kurl_delete_post_link', [__CLASS__, 'ajax_delete_post_link']);
        add_action('wp_ajax_kurl_test_api', [__CLASS__, 'ajax_test_api']);
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

        foreach (Kurl_Helpers::enabled_post_types() as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", [__CLASS__, 'add_shorturl_list_column']);
            add_action("manage_{$post_type}_posts_custom_column", [__CLASS__, 'render_shorturl_list_column'], 10, 2);
        }
        add_filter('default_hidden_columns', [__CLASS__, 'default_hidden_columns'], 10, 2);
    }

    public static function menu(): void {
        add_menu_page(__('kURL', 'kurl-yourls'), __('kURL', 'kurl-yourls'), 'manage_options', 'kurl-yourls', [__CLASS__, 'render_dashboard'], 'dashicons-admin-links', 58);
        add_submenu_page('kurl-yourls', __('Dashboard', 'kurl-yourls'), __('Dashboard', 'kurl-yourls'), 'manage_options', 'kurl-yourls', [__CLASS__, 'render_dashboard']);
        add_submenu_page('kurl-yourls', __('Bulk Generator', 'kurl-yourls'), __('Bulk Generator', 'kurl-yourls'), 'manage_options', 'kurl-bulk', [__CLASS__, 'render_bulk']);
        add_submenu_page('kurl-yourls', __('Sync & Cleanup', 'kurl-yourls'), __('Sync & Cleanup', 'kurl-yourls'), 'manage_options', 'kurl-sync', [__CLASS__, 'render_sync_cleanup']);
        add_submenu_page('kurl-yourls', __('Logs', 'kurl-yourls'), __('Logs', 'kurl-yourls'), 'manage_options', 'kurl-logs', [__CLASS__, 'render_logs']);
        add_submenu_page('kurl-yourls', __('Settings', 'kurl-yourls'), __('Settings', 'kurl-yourls'), 'manage_options', 'kurl-settings', [__CLASS__, 'render_settings']);
    }

    public static function enqueue(string $hook): void {
        if (!self::should_enqueue_assets($hook)) {
            return;
        }
        $settings = Kurl_Helpers::get_settings();
        $is_extended = !empty($settings['api_extended']);
        $confirm_msg = $is_extended
            ? __('Are you sure you want to permanently delete this shortlink from WordPress and your YOURLS database?', 'kurl-yourls')
            : __('Are you sure you want to unlink this shortlink from WordPress? You will still need to delete the old link manually in YOURLS before reusing the same custom slug.', 'kurl-yourls');

        wp_enqueue_style('kurl-admin', KURL_URL . 'assets/kurl-admin.css', [], KURL_VERSION);
        wp_enqueue_script('kurl-admin', KURL_URL . 'assets/kurl-admin.js', ['jquery'], KURL_VERSION, true);
        wp_localize_script('kurl-admin', 'kurlAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('kurl_admin'),
            'strings' => [
                'working'              => __('Working…', 'kurl-yourls'),
                'done'                 => __('Done', 'kurl-yourls'),
                'error'                => __('Error', 'kurl-yourls'),
                'confirm_delete'       => $confirm_msg,
                'clicks_label'         => __('Clicks:', 'kurl-yourls'),
                'links_label'          => __('Links:', 'kurl-yourls'),
                'copy_missing'         => __('No code found to copy.', 'kurl-yourls'),
                'copy_failed'          => __('Failed to copy code. Please copy it manually.', 'kurl-yourls'),
                'bulk_processed'       => __('Processed:', 'kurl-yourls'),
                'bulk_created'         => __('Created:', 'kurl-yourls'),
                'bulk_updated'         => __('Updated:', 'kurl-yourls'),
                'bulk_imported'        => __('Imported:', 'kurl-yourls'),
                'bulk_skipped'         => __('Skipped:', 'kurl-yourls'),
                'bulk_errors'          => __('Errors:', 'kurl-yourls'),
                'bulk_status'          => __('Status:', 'kurl-yourls'),
                'bulk_done'            => __('Done.', 'kurl-yourls'),
                'bulk_done_label'      => __('done', 'kurl-yourls'),
                'bulk_stopped'         => __('Stopped.', 'kurl-yourls'),
                'bulk_error_prefix'    => __('Error:', 'kurl-yourls'),
                'bulk_ajax_prefix'     => __('AJAX error:', 'kurl-yourls'),
                'status_created'       => __('created', 'kurl-yourls'),
                'status_updated'       => __('updated', 'kurl-yourls'),
                'status_imported'      => __('imported', 'kurl-yourls'),
                'status_skipped_exist' => __('skipped existing', 'kurl-yourls'),
                'status_error'         => __('error', 'kurl-yourls'),
                'sync_button'          => __('Check / Sync', 'kurl-yourls'),
                'api_connected'        => __('Connected', 'kurl-yourls'),
                'api_not_connected'    => __('Not connected', 'kurl-yourls'),
                'manual_lookup_only'   => __('Safe lookup needs the helper plugin.', 'kurl-yourls'),
                'manual_regenerate'    => __('Regenerate', 'kurl-yourls'),
                'manual_delete'        => __('Delete', 'kurl-yourls'),
                'manual_generate'      => __('Generate / Update', 'kurl-yourls'),
                'manual_check'         => __('Check YOURLS', 'kurl-yourls'),
                'reconcile_checked'    => __('Checked:', 'kurl-yourls'),
                'reconcile_imported'   => __('Imported:', 'kurl-yourls'),
                'reconcile_replaced'   => __('Replaced:', 'kurl-yourls'),
                'reconcile_verified'   => __('Verified:', 'kurl-yourls'),
                'reconcile_mismatches' => __('Mismatches:', 'kurl-yourls'),
                'reconcile_skipped'    => __('Skipped:', 'kurl-yourls'),
                'reconcile_preview'    => __('Preview only', 'kurl-yourls'),
                'reconcile_apply'      => __('Apply changes', 'kurl-yourls'),
                'reconcile_done'       => __('Reconciliation finished.', 'kurl-yourls'),
                'reconcile_stopped'    => __('Reconciliation stopped.', 'kurl-yourls'),
                'status_would_import'  => __('would import', 'kurl-yourls'),
                'status_would_replace' => __('would replace', 'kurl-yourls'),
                'status_verified'      => __('verified', 'kurl-yourls'),
                'status_mismatch'      => __('mismatch', 'kurl-yourls'),
                'status_skipped'       => __('skipped', 'kurl-yourls'),
            ],
        ]);
    }

    public static function add_shorturl_list_column(array $columns): array {
        $new_columns = [];
        $inserted = false;
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'title') {
                $new_columns['kurl_shorturl'] = __('Short URL', 'kurl-yourls');
                $inserted = true;
            }
        }
        if (!$inserted) {
            $new_columns['kurl_shorturl'] = __('Short URL', 'kurl-yourls');
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
            add_meta_box('kurl-meta', __('kURL Shortlink', 'kurl-yourls'), [__CLASS__, 'render_meta_box'], $post_type, 'side', 'high');
        }
    }

    public static function render_meta_box(WP_Post $post): void {
        $shorturl = Kurl_Shortlinks::get_shorturl($post->ID);
        $keyword = Kurl_Shortlinks::get_keyword($post->ID);
        $stats = Kurl_Shortlinks::get_stats($post->ID);
        $settings = Kurl_Helpers::get_settings();
        $is_extended = !empty($settings['api_extended']);
        $delete_text = $is_extended ? esc_html__('Delete & Unlink', 'kurl-yourls') : esc_html__('Unlink Locally', 'kurl-yourls');
        $delete_style = $shorturl !== '' ? 'color:#d63638;display:inline-block;' : 'color:#d63638;display:none;';
        $readonly_kw = $shorturl !== '' ? 'readonly="readonly"' : '';

        echo '<div class="kurl-box">';
        echo '<p style="margin-bottom:6px;"><label><strong>' . esc_html__('Keyword / Slug', 'kurl-yourls') . '</strong></label></p>';
        echo '<input type="text" class="widefat kurl-keyword" value="' . esc_attr($keyword) . '" placeholder="' . esc_attr__('optional-custom-slug', 'kurl-yourls') . '" ' . esc_attr($readonly_kw) . '>';
        echo '<p style="margin-top:14px;margin-bottom:6px;"><label><strong>' . esc_html__('Short URL', 'kurl-yourls') . '</strong></label></p>';
        echo '<input type="text" class="widefat kurl-shorturl" value="' . esc_attr($shorturl) . '" readonly="readonly">';
        echo '<p class="kurl-actions" style="margin-top:16px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;">';
        echo '<button type="button" class="button button-primary kurl-generate" data-post="' . (int) $post->ID . '">' . esc_html__('Generate / Update', 'kurl-yourls') . '</button>';
        echo '<button type="button" class="button kurl-sync" data-post="' . (int) $post->ID . '">' . esc_html__('Check / Sync', 'kurl-yourls') . '</button>';
        echo '<button type="button" class="button kurl-refresh-stats" data-post="' . (int) $post->ID . '">' . esc_html__('Refresh Stats', 'kurl-yourls') . '</button>';
        echo '<button type="button" class="button button-link-delete kurl-delete" data-post="' . (int) $post->ID . '" style="' . esc_attr($delete_style) . '">' . esc_html($delete_text) . '</button>';
        echo '</p>';
        echo '<div class="kurl-inline-status" style="margin-top:12px;"></div>';
        if (!empty($stats)) {
            echo '<div class="kurl-meta-stats" style="margin-top:12px;"><strong>' . esc_html__('Clicks:', 'kurl-yourls') . '</strong> ' . esc_html((string) ((int) ($stats['clicks'] ?? 0))) . '</div>';
        }
        echo '<p class="description" style="margin-top:12px;">' . esc_html__('Experimental: Check whether YOURLS already has a short URL for this permalink and sync it into WordPress.', 'kurl-yourls') . '</p>';
        echo '</div>';
    }

    public static function ajax_generate_post_link(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $request     = wp_unslash($_POST);
        $raw_post_id = $request['post_id'] ?? 0;
        $post_id     = absint($raw_post_id);
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-yourls')], 403);
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            wp_send_json_error(['message' => __('Invalid post.', 'kurl-yourls')], 400);
        }
        if (!Kurl_Helpers::is_supported_post_type($post->post_type)) {
            wp_send_json_error(['message' => __('This post type is not enabled for kURL.', 'kurl-yourls')], 400);
        }
        $raw_keyword = $request['keyword'] ?? '';
        $keyword     = Kurl_Helpers::sanitize_keyword((string) $raw_keyword);
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            wp_send_json_error(['message' => __('Could not determine the permalink.', 'kurl-yourls')], 400);
        }
        $response = Kurl_API::create_shortlink($permalink, $keyword, get_the_title($post));
        if (empty($response['ok'])) {
            $message = Kurl_Helpers::format_api_error($response);
            Kurl_Logger::log('error', 'Manual generation failed', ['post_id' => $post_id, 'message' => $message]);
            wp_send_json_error(['message' => $message], 400);
        }
        $shorturl = Kurl_API::extract_shorturl($response);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-yourls')], 400);
        }
        if ($keyword !== '' && !self::shorturl_matches_keyword($shorturl, $keyword)) {
            wp_send_json_error(['message' => __('YOURLS did not accept that custom keyword. This often means the slug is already in use. Delete the old shortlink first or choose a different keyword.', 'kurl-yourls')], 400);
        }
        Kurl_Shortlinks::save_link($post_id, $shorturl, $keyword);
        delete_transient('kurl_dashboard_overview');
        wp_send_json_success(['shorturl' => $shorturl, 'message' => __('Shortlink saved successfully.', 'kurl-yourls')]);
    }

    public static function ajax_check_sync_post(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-yourls')], 403);
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            wp_send_json_error(['message' => __('Invalid post.', 'kurl-yourls')], 400);
        }
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            wp_send_json_error(['message' => __('Could not determine the permalink.', 'kurl-yourls')], 400);
        }
        $local = Kurl_Shortlinks::get_shorturl($post_id);
        $helper = Kurl_API::find_by_longurl($permalink);
        $found = !empty($helper['ok']) ? Kurl_API::extract_shorturl($helper) : '';
        if ($found !== '') {
            Kurl_Shortlinks::save_link($post_id, $found, Kurl_Shortlinks::get_keyword($post_id));
            delete_transient('kurl_dashboard_overview');
            wp_send_json_success(['shorturl' => $found, 'message' => $local && $local !== $found ? __('Local short URL replaced with the matching YOURLS entry.', 'kurl-yourls') : __('Matching YOURLS short URL imported successfully.', 'kurl-yourls')]);
        }
        if ($local !== '') {
            $expand = Kurl_API::expand_shortlink($local);
            if (!empty($expand['ok'])) {
                $longurl = Kurl_API::extract_longurl($expand);
                if ($longurl !== '' && untrailingslashit($longurl) === untrailingslashit($permalink)) {
                    wp_send_json_success(['shorturl' => $local, 'message' => __('The saved short URL already matches the current permalink.', 'kurl-yourls')]);
                }
                if ($longurl !== '' && untrailingslashit($longurl) !== untrailingslashit($permalink)) {
                    wp_send_json_error(['message' => __('The saved short URL points to a different long URL in YOURLS. Review this manually before replacing it.', 'kurl-yourls')], 400);
                }
            }
        }
        $fallback = Kurl_API::create_shortlink($permalink, '', get_the_title($post));
        if (empty($fallback['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($fallback)], 400);
        }
        $shorturl = Kurl_API::extract_shorturl($fallback);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-yourls')], 400);
        }
        Kurl_Shortlinks::save_link($post_id, $shorturl, Kurl_Shortlinks::get_keyword($post_id));
        delete_transient('kurl_dashboard_overview');
        wp_send_json_success(['shorturl' => $shorturl, 'message' => __('No helper match was found. The standard API returned or created a short URL and synced it into WordPress.', 'kurl-yourls')]);
    }


    public static function ajax_manual_lookup_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options();
        $request = wp_unslash($_POST);
        $raw_url = $request['url'] ?? '';
        $url     = esc_url_raw(trim((string) $raw_url));
        if ($url === '') {
            wp_send_json_error(['message' => __('Please enter a valid URL.', 'kurl-yourls')], 400);
        }
        $settings = Kurl_Helpers::get_settings();
        if (empty($settings['api_extended'])) {
            wp_send_json_error(['message' => __('Safe lookup needs the optional kURL Helper plugin on your YOURLS server.', 'kurl-yourls')], 400);
        }
        $response = Kurl_API::find_by_longurl($url);
        if (!empty($response['ok'])) {
            $shorturl = Kurl_API::extract_shorturl($response);
            if ($shorturl !== '') {
                wp_send_json_success([
                    'shorturl' => $shorturl,
                    'message'  => __('An existing YOURLS short URL was found for that URL.', 'kurl-yourls'),
                ]);
            }
        }
        $message = Kurl_Helpers::format_api_error($response);
        if (stripos($message, 'not found') !== false || strpos($message, '404') !== false) {
            wp_send_json_success([
                'shorturl' => '',
                'message'  => __('No existing YOURLS short URL was found for that URL.', 'kurl-yourls'),
            ]);
        }
        wp_send_json_error(['message' => $message], 400);
    }

    public static function ajax_manual_generate_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options();
        $request     = wp_unslash($_POST);
        $raw_url     = $request['url'] ?? '';
        $raw_keyword = $request['keyword'] ?? '';
        $url         = esc_url_raw(trim((string) $raw_url));
        $keyword     = Kurl_Helpers::sanitize_keyword((string) $raw_keyword);
        if ($url === '') {
            wp_send_json_error(['message' => __('Please enter a valid URL.', 'kurl-yourls')], 400);
        }
        $response = Kurl_API::create_shortlink($url, $keyword, '');
        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }
        $shorturl = Kurl_API::extract_shorturl($response);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-yourls')], 400);
        }
        if ($keyword !== '' && !self::shorturl_matches_keyword($shorturl, $keyword)) {
            wp_send_json_error(['message' => __('YOURLS did not accept that custom keyword. This often means the slug is already in use.', 'kurl-yourls')], 400);
        }
        wp_send_json_success([
            'shorturl' => $shorturl,
            'message'  => __('Short URL generated successfully.', 'kurl-yourls'),
        ]);
    }

    public static function ajax_manual_delete_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options();
        $request  = wp_unslash($_POST);
        $settings = Kurl_Helpers::get_settings();
        if (empty($settings['api_extended'])) {
            wp_send_json_error(['message' => __('Remote deletion needs the optional kURL Helper plugin on your YOURLS server.', 'kurl-yourls')], 400);
        }
        $raw_shorturl = $request['shorturl'] ?? '';
        $shorturl     = esc_url_raw(trim((string) $raw_shorturl));
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('Please enter or look up a short URL first.', 'kurl-yourls')], 400);
        }
        $response = Kurl_API::delete_shortlink($shorturl);
        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }
        wp_send_json_success(['message' => __('Short URL deleted in YOURLS.', 'kurl-yourls')]);
    }

    public static function ajax_manual_regenerate_url(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options();
        $request  = wp_unslash($_POST);
        $settings = Kurl_Helpers::get_settings();
        if (empty($settings['api_extended'])) {
            wp_send_json_error(['message' => __('Regeneration needs the optional kURL Helper plugin on your YOURLS server.', 'kurl-yourls')], 400);
        }
        $raw_url      = $request['url'] ?? '';
        $raw_keyword  = $request['keyword'] ?? '';
        $raw_shorturl = $request['shorturl'] ?? '';
        $url          = esc_url_raw(trim((string) $raw_url));
        $keyword      = Kurl_Helpers::sanitize_keyword((string) $raw_keyword);
        $shorturl     = esc_url_raw(trim((string) $raw_shorturl));
        if ($url === '') {
            wp_send_json_error(['message' => __('Please enter a valid URL.', 'kurl-yourls')], 400);
        }
        if ($shorturl === '') {
            $lookup = Kurl_API::find_by_longurl($url);
            if (!empty($lookup['ok'])) {
                $shorturl = Kurl_API::extract_shorturl($lookup);
            }
        }
        if ($shorturl !== '') {
            $delete = Kurl_API::delete_shortlink($shorturl);
            if (empty($delete['ok'])) {
                wp_send_json_error(['message' => Kurl_Helpers::format_api_error($delete)], 400);
            }
        }
        $create = Kurl_API::create_shortlink($url, $keyword, '');
        if (empty($create['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($create)], 400);
        }
        $new_shorturl = Kurl_API::extract_shorturl($create);
        if ($new_shorturl === '') {
            wp_send_json_error(['message' => __('YOURLS did not return a short URL.', 'kurl-yourls')], 400);
        }
        if ($keyword !== '' && !self::shorturl_matches_keyword($new_shorturl, $keyword)) {
            wp_send_json_error(['message' => __('YOURLS did not accept that custom keyword. This often means the slug is already in use.', 'kurl-yourls')], 400);
        }
        wp_send_json_success([
            'shorturl' => $new_shorturl,
            'message'  => $keyword !== ''
                ? __('Short URL regenerated with the requested keyword.', 'kurl-yourls')
                : __('Short URL regenerated with a new random keyword.', 'kurl-yourls'),
        ]);
    }

    public static function ajax_refresh_post_stats(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-yourls')], 403);
        }
        $shorturl = Kurl_Shortlinks::get_shorturl($post_id);
        if ($shorturl === '') {
            wp_send_json_error(['message' => __('No short URL saved yet.', 'kurl-yourls')], 400);
        }
        $response = Kurl_API::url_stats($shorturl);
        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }
        $clicks = (int) ($response['link']['clicks'] ?? ($response['clicks'] ?? 0));
        Kurl_Shortlinks::save_stats($post_id, ['clicks' => $clicks, 'updated' => current_time('mysql')]);
        wp_send_json_success(['stats' => ['clicks' => $clicks]]);
    }

    public static function ajax_delete_post_link(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-yourls')], 403);
        }
        $shorturl = Kurl_Shortlinks::get_shorturl($post_id);
        if ($shorturl === '') {
            wp_send_json_success(['message' => __('No saved shortlink was found.', 'kurl-yourls')]);
        }
        $settings = Kurl_Helpers::get_settings();
        if (!empty($settings['api_extended'])) {
            $delete_response = Kurl_API::delete_shortlink($shorturl);
            if (empty($delete_response['ok'])) {
                wp_send_json_error([
                    /* translators: %s: API error message. */
                    'message' => sprintf(__('Remote deletion failed: %s', 'kurl-yourls'), Kurl_Helpers::format_api_error($delete_response)),
                ], 400);
            }
            self::clear_post_link_meta($post_id);
            delete_transient('kurl_dashboard_overview');
            wp_send_json_success(['message' => __('Shortlink deleted in YOURLS and unlinked from WordPress.', 'kurl-yourls')]);
        }
        self::clear_post_link_meta($post_id);
        delete_transient('kurl_dashboard_overview');
        wp_send_json_success(['message' => __('Shortlink unlinked from WordPress. The old entry still exists in YOURLS until you delete it there.', 'kurl-yourls')]);
    }

    public static function ajax_test_api(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        self::assert_manage_options();

        $request = wp_unslash($_POST);

        $posted_api_url   = isset($request['api_url']) ? self::normalize_api_url((string) $request['api_url']) : '';
        $posted_signature = isset($request['signature']) ? sanitize_text_field((string) $request['signature']) : '';

        if ($posted_api_url !== '' || $posted_signature !== '') {
            $settings = Kurl_Helpers::get_settings();
            if ($posted_api_url !== '') {
                $settings['api_url'] = $posted_api_url;
            }
            if ($posted_signature !== '') {
                $settings['signature'] = $posted_signature;
            }
            $settings['api_extended'] = 0;
            update_option('kurl_settings', $settings, false);
            Kurl_Helpers::flush_settings_cache();
            delete_transient('kurl_dashboard_overview');
        }

        $response = Kurl_API::aggregate_stats();
        if (empty($response['ok'])) {
            wp_send_json_error(['message' => Kurl_Helpers::format_api_error($response)], 400);
        }

        $extended = Kurl_API::check_extended_api();
        $settings = Kurl_Helpers::get_settings();
        $settings['api_extended'] = $extended ? 1 : 0;
        update_option('kurl_settings', $settings, false);
        Kurl_Helpers::flush_settings_cache();

        $message = $extended ? __('Connection successful. Helper plugin detected.', 'kurl-yourls') : __('Connection successful. Standard API only.', 'kurl-yourls');
        $total_links = (int) ($response['total_links'] ?? ($response['stats']['total_links'] ?? 0) ?? ($response['db-stats']['total_links'] ?? 0));
        $total_clicks = (int) ($response['total_clicks'] ?? ($response['stats']['total_clicks'] ?? 0) ?? ($response['db-stats']['total_clicks'] ?? 0));

        wp_send_json_success(['message' => $message, 'total_links' => $total_links, 'total_clicks' => $total_clicks]);
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kurl-yourls'));
        }
        check_admin_referer('kurl_save_settings');
        $request = wp_unslash($_POST);
        if (!empty($request['disconnect_api'])) {
            $settings = Kurl_Helpers::get_settings();
            $settings['api_url'] = '';
            $settings['signature'] = '';
            $settings['api_extended'] = 0;
            update_option('kurl_settings', $settings, false);
            Kurl_Helpers::flush_settings_cache();
            delete_transient('kurl_dashboard_overview');
            wp_safe_redirect(add_query_arg(['page' => 'kurl-settings', 'disconnected' => 1], admin_url('admin.php')));
            exit;
        }
        $raw_enabled_post_types = $request['enabled_post_types'] ?? [];
        $raw_api_url            = $request['api_url'] ?? '';
        $raw_signature          = $request['signature'] ?? '';
        $raw_cache_minutes      = $request['cache_minutes'] ?? 30;
        $raw_request_timeout    = $request['request_timeout'] ?? 15;

        $enabled_post_types = array_values(array_filter(array_map('sanitize_key', (array) $raw_enabled_post_types)));
        $settings = [
            'api_url'                => self::normalize_api_url((string) $raw_api_url),
            'signature'              => sanitize_text_field((string) $raw_signature),
            'enabled_post_types'     => $enabled_post_types,
            'cache_minutes'          => max(1, absint($raw_cache_minutes)),
            'request_timeout'        => max(5, absint($raw_request_timeout)),
            'auto_create_on_publish' => isset($request['auto_create_on_publish']) ? 1 : 0,
            'api_extended'           => 0,
        ];
        update_option('kurl_settings', $settings, false);
        Kurl_Helpers::flush_settings_cache();
        if ($settings['api_url'] !== '' && $settings['signature'] !== '' && Kurl_API::check_extended_api()) {
            $settings['api_extended'] = 1;
            update_option('kurl_settings', $settings, false);
            Kurl_Helpers::flush_settings_cache();
        }
        update_option('kurl_delete_data', isset($request['delete_data']) ? 1 : 0, false);
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

        $report  = ['keywords_removed' => 0, 'stats_removed' => 0, 'urls_normalized' => 0];
        $last_id = 0;
        $limit   = 250;

        do {
            $post_ids = self::get_enabled_post_ids_batch($last_id, $limit);

            foreach ($post_ids as $post_id) {
                $post_id = (int) $post_id;
                if ($post_id <= 0) {
                    continue;
                }

                $last_id     = max($last_id, $post_id);
                $shorturl    = Kurl_Shortlinks::get_shorturl($post_id);
                $raw_keyword = (string) get_post_meta($post_id, KURL_META_KEYWORD, true);
                $keyword     = Kurl_Helpers::sanitize_keyword($raw_keyword);

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

                $normalized = trim(esc_url_raw($shorturl));
                if ($normalized !== $shorturl) {
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
        self::assert_manage_options();

        $request        = wp_unslash($_POST);
        $raw_batch_size = $request['batch_size'] ?? 5;
        $raw_last_id    = $request['last_id'] ?? 0;
        $raw_preview    = $request['preview'] ?? '';

        $batch_size = max(1, min(25, absint($raw_batch_size)));
        $last_id    = max(0, absint($raw_last_id));
        $preview    = !empty($raw_preview);

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
        $title = get_the_title($post_id);
        $permalink = get_permalink($post_id);

        if (!is_string($permalink) || $permalink === '') {
            return [
                'post_id' => $post_id,
                'title'   => $title,
                'status'  => 'skipped',
                'message' => __('Could not determine the permalink.', 'kurl-yourls'),
            ];
        }

        $local   = Kurl_Shortlinks::get_shorturl($post_id);
        $keyword = Kurl_Shortlinks::get_keyword($post_id);
        $helper_available = !empty(Kurl_Helpers::get_settings()['api_extended']);

        if ($helper_available) {
            $helper = Kurl_API::find_by_longurl($permalink);
            $found  = !empty($helper['ok']) ? Kurl_API::extract_shorturl($helper) : '';

            if ($found !== '') {
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
                            ? sprintf(
                        /* translators: %s: Existing matching short URL found in YOURLS. */
                        __('Imported existing YOURLS short URL: %s', 'kurl-yourls'),
                        $found
                    )
                            : sprintf(
                        /* translators: %s: Existing matching short URL found in YOURLS. */
                        __('Preview: would import existing YOURLS short URL: %s', 'kurl-yourls'),
                        $found
                    ),
                    ];
                }

                if (untrailingslashit($local) !== untrailingslashit($found)) {
                    if ($apply_changes) {
                        Kurl_Shortlinks::save_link($post_id, $found, $keyword);
                        Kurl_Logger::log('info', 'Reconcile replaced local shortlink from YOURLS', ['post_id' => $post_id, 'old' => $local, 'new' => $found]);
                    }
                    return [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => $apply_changes ? 'replaced' : 'would_replace',
                        'message' => $apply_changes
                            ? sprintf(
                        /* translators: %s: Replacement short URL found in YOURLS. */
                        __('Replaced local short URL with YOURLS result: %s', 'kurl-yourls'),
                        $found
                    )
                            : sprintf(
                        /* translators: %s: Replacement short URL found in YOURLS. */
                        __('Preview: would replace local short URL with YOURLS result: %s', 'kurl-yourls'),
                        $found
                    ),
                    ];
                }

                return [
                    'post_id' => $post_id,
                    'title'   => $title,
                    'status'  => 'verified',
                    'message' => __('Local short URL matches YOURLS.', 'kurl-yourls'),
                ];
            }
        }

        if ($local !== '') {
            $expand = Kurl_API::expand_shortlink($local);
            if (!empty($expand['ok'])) {
                $longurl = Kurl_API::extract_longurl($expand);
                if ($longurl !== '' && untrailingslashit($longurl) === untrailingslashit($permalink)) {
                    return [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => 'verified',
                        'message' => __('Local short URL expands to the current permalink.', 'kurl-yourls'),
                    ];
                }
            }

            return [
                'post_id' => $post_id,
                'title'   => $title,
                'status'  => 'mismatch',
                'message' => __('Saved short URL does not match the current permalink in YOURLS.', 'kurl-yourls'),
            ];
        }

        return [
            'post_id' => $post_id,
            'title'   => $title,
            'status'  => 'skipped',
            'message' => $helper_available
                ? __('No matching YOURLS entry was found for this permalink.', 'kurl-yourls')
                : __('No local short URL to verify, and safe reverse lookup needs the helper plugin.', 'kurl-yourls'),
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
            ? __('Not connected', 'kurl-yourls')
            : ($dashboard['connected'] ? __('Connected', 'kurl-yourls') : __('Connection failed', 'kurl-yourls'));

        $api_status_note = !$dashboard['configured']
            ? __('Add your API URL and signature in Settings.', 'kurl-yourls')
            : ($dashboard['connected']
                ? __('Last dashboard refresh reached your YOURLS API successfully.', 'kurl-yourls')
                : ($dashboard['error'] !== '' ? $dashboard['error'] : __('The dashboard could not reach YOURLS right now.', 'kurl-yourls')));

        echo '<div class="wrap kurl-admin">';
        echo '<div class="kurl-page-head"><div><h1>' . esc_html__('kURL Dashboard', 'kurl-yourls') . '</h1><p class="kurl-subtitle">' . esc_html(sprintf(
                /* translators: %s: Plugin version number. */
                __('Version %s • Dashboard statistics, manual shortening, migration tools, logs, and bulk processing.', 'kurl-yourls'),
                KURL_VERSION
            )) . '</p></div></div>';

        echo '<div class="kurl-cards">';
        echo wp_kses_post(self::card(__('API status', 'kurl-yourls'), $api_status_value, $api_status_note));
        echo wp_kses_post(self::card(__('Helper extension', 'kurl-yourls'), !empty($settings['api_extended']) ? __('Active', 'kurl-yourls') : __('Standard API only', 'kurl-yourls'), !empty($settings['api_extended']) ? __('Safe lookup, remote deletion, and reconciliation are available.', 'kurl-yourls') : __('Install the helper to enable safe lookup, remote deletion, and advanced reconciliation.', 'kurl-yourls')));
        echo wp_kses_post(self::card(__('Saved shortlinks', 'kurl-yourls'), (string) $saved_links, __('Posts currently using a kURL entry in WordPress.', 'kurl-yourls')));
        echo wp_kses_post(self::card(__('YOURLS total links', 'kurl-yourls'), (string) $dashboard['total_links'], __('Total links reported by YOURLS.', 'kurl-yourls')));
        echo wp_kses_post(self::card(__('YOURLS total clicks', 'kurl-yourls'), (string) $dashboard['total_clicks'], __('Total clicks reported by YOURLS.', 'kurl-yourls')));
        echo wp_kses_post(self::card(__('Log entries', 'kurl-yourls'), (string) $log_count, __('Only the last 7 days are retained.', 'kurl-yourls')));
        echo '</div>';

        if (!$dashboard['configured']) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The API is not configured yet. You can still review settings, but dashboard lists and manual YOURLS actions will stay inactive until you add your API credentials.', 'kurl-yourls') . '</p></div>';
        } elseif (!$dashboard['connected'] && $dashboard['error'] !== '') {
            echo '<div class="notice notice-warning"><p>' . esc_html($dashboard['error']) . '</p></div>';
        }

        echo '<div class="kurl-grid kurl-grid--dashboard">';
        echo '<div class="kurl-panel kurl-dashboard-main">';
        echo '<h2>' . esc_html__('Manual shortener', 'kurl-yourls') . '</h2>';
        echo '<p>' . esc_html__('Shorten any URL without saving it to WordPress. Safe lookup, delete, and regenerate use the helper plugin when available.', 'kurl-yourls') . '</p>';
        echo '<div class="kurl-manual-box">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-manual-url">' . esc_html__('Target URL', 'kurl-yourls') . '</label></th><td><input type="url" id="kurl-manual-url" class="regular-text code" placeholder="https://example.com/article"></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-manual-keyword">' . esc_html__('Keyword / Slug', 'kurl-yourls') . '</label></th><td><input type="text" id="kurl-manual-keyword" class="regular-text code" placeholder="optional-custom-slug"><p class="description">' . esc_html__('Leave empty to let YOURLS use a random keyword. The Regenerate button uses the keyword field if you filled it in, otherwise it requests a new random keyword.', 'kurl-yourls') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-manual-shorturl">' . esc_html__('Short URL', 'kurl-yourls') . '</label></th><td><input type="text" id="kurl-manual-shorturl" class="regular-text code" readonly="readonly"></td></tr>';
        echo '</tbody></table>';
        echo '<p class="kurl-actions" style="margin-top:16px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;">';
        echo '<button type="button" class="button kurl-manual-check">' . esc_html__('Check YOURLS', 'kurl-yourls') . '</button>';
        echo '<button type="button" class="button button-primary kurl-manual-generate">' . esc_html__('Generate / Update', 'kurl-yourls') . '</button>';
        echo '<button type="button" class="button kurl-manual-regenerate">' . esc_html__('Delete & Regenerate', 'kurl-yourls') . '</button>';
        echo '<button type="button" class="button button-link-delete kurl-manual-delete" style="color:#d63638;">' . esc_html__('Delete', 'kurl-yourls') . '</button>';
        echo '</p>';
        echo '<div class="kurl-inline-status kurl-manual-status"></div>';
        echo '<p class="description" style="margin-top:12px;">' . esc_html__('Tip: “Check YOURLS” only performs a safe reverse lookup when the helper plugin is active. “Generate / Update” can still create or return a short URL through the standard API.', 'kurl-yourls') . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="kurl-panel kurl-dashboard-side">';
        echo '<h2>' . esc_html__('Top 10 all-time links', 'kurl-yourls') . '</h2>';
        echo wp_kses_post(self::render_remote_links_table($dashboard['top_links'], 'top'));
        echo '</div>';

        echo '<div class="kurl-panel kurl-dashboard-main">';
        echo '<h2>' . esc_html__('Recent YOURLS activity across the whole instance', 'kurl-yourls') . '</h2>';
        echo wp_kses_post(self::render_remote_links_table($dashboard['latest_links'], 'latest'));
        echo '</div>';

        echo '<div class="kurl-panel kurl-dashboard-side">';
        echo '<h2>' . esc_html__('Latest shortlinks saved in WordPress', 'kurl-yourls') . '</h2>';
        if (!empty($recent_posts)) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Post', 'kurl-yourls') . '</th><th>' . esc_html__('Short URL', 'kurl-yourls') . '</th><th>' . esc_html__('Clicks', 'kurl-yourls') . '</th></tr></thead><tbody>';
            foreach ($recent_posts as $post) {
                $shorturl = (string) get_post_meta($post->ID, KURL_META_URL, true);
                $clicks   = (int) (Kurl_Shortlinks::get_stats($post->ID)['clicks'] ?? 0);
                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html(get_the_title($post)) . '</a></td>';
                echo '<td><code>' . esc_html($shorturl) . '</code></td>';
                echo '<td>' . esc_html((string) $clicks) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No saved kURL entries yet.', 'kurl-yourls') . '</p>';
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
            $data['total_links']  = (int) ($db_stats['total_links'] ?? ($db_stats['db-stats']['total_links'] ?? 0));
            $data['total_clicks'] = (int) ($db_stats['total_clicks'] ?? ($db_stats['db-stats']['total_clicks'] ?? 0));
        } else {
            $data['error'] = Kurl_Helpers::format_api_error($db_stats);
        }

        $top = Kurl_API::stats_list('top', 10);
        if (!empty($top['ok'])) {
            $data['connected'] = true;
            $data['top_links'] = self::extract_stats_links($top);
            if ($data['total_links'] === 0) {
                $data['total_links'] = (int) ($top['total_links'] ?? ($top['stats']['total_links'] ?? 0));
            }
            if ($data['total_clicks'] === 0) {
                $data['total_clicks'] = (int) ($top['total_clicks'] ?? ($top['stats']['total_clicks'] ?? 0));
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
                'keyword'  => sanitize_text_field((string) ($link['keyword'] ?? (is_string($key) ? $key : ''))),
                'shorturl' => trim(esc_url_raw((string) ($link['shorturl'] ?? ''))),
                'longurl'  => trim(esc_url_raw((string) ($link['url'] ?? ($link['longurl'] ?? '')))),
                'title'    => sanitize_text_field((string) ($link['title'] ?? '')),
                'clicks'   => (int) ($link['clicks'] ?? 0),
                'date'     => sanitize_text_field((string) ($link['timestamp'] ?? ($link['date'] ?? ''))),
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
            return '<p>' . esc_html__('No YOURLS link data is available yet for this section.', 'kurl-yourls') . '</p>';
        }

        $html = '<div class="kurl-remote-table"><table class="widefat striped"><thead><tr>';
        if ($mode === 'latest') {
            $html .= '<th>' . esc_html__('Created', 'kurl-yourls') . '</th>';
        }
        $html .= '<th>' . esc_html__('Short URL', 'kurl-yourls') . '</th><th>' . esc_html__('Target', 'kurl-yourls') . '</th>';
        if ($mode === 'top') {
            $html .= '<th>' . esc_html__('Clicks', 'kurl-yourls') . '</th>';
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
        echo '<div class="wrap kurl-admin">';
        echo '<div class="kurl-page-head"><div><h1>' . esc_html__('kURL Bulk Generator', 'kurl-yourls') . '</h1><p class="kurl-subtitle">' . esc_html__('AJAX batches help avoid long-running admin requests and timeout problems.', 'kurl-yourls') . '</p></div></div>';
        echo '<div class="kurl-panel"><table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-bulk-post-type">' . esc_html__('Post type', 'kurl-yourls') . '</label></th><td><select id="kurl-bulk-post-type">';
        foreach (Kurl_Helpers::enabled_post_types() as $post_type) {
            echo '<option value="' . esc_attr($post_type) . '">' . esc_html($post_type) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-bulk-batch-size">' . esc_html__('Batch size', 'kurl-yourls') . '</label></th><td><select id="kurl-bulk-batch-size"><option>5</option><option selected>10</option><option>25</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-bulk-mode">' . esc_html__('Existing links', 'kurl-yourls') . '</label></th><td><select id="kurl-bulk-mode"><option value="skip">' . esc_html__('Skip existing', 'kurl-yourls') . '</option><option value="import">' . esc_html__('Import old Better YOURLS first', 'kurl-yourls') . '</option><option value="overwrite">' . esc_html__('Regenerate / overwrite', 'kurl-yourls') . '</option></select></td></tr>';
        echo '</tbody></table><p><button class="button button-primary" id="kurl-bulk-start">' . esc_html__('Start bulk generation', 'kurl-yourls') . '</button> <button class="button" id="kurl-bulk-stop">' . esc_html__('Stop', 'kurl-yourls') . '</button></p><div class="kurl-progress"><div class="kurl-progress-bar" id="kurl-progress-bar"></div></div><div class="kurl-bulk-stats" id="kurl-bulk-stats"></div><div class="kurl-log-box kurl-light-log" id="kurl-bulk-log"></div></div></div>';
    }

    public static function render_sync_cleanup(): void {
        self::assert_manage_options();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters on a protected admin screen.
        $kurl_cleanup_done     = isset($_GET['cleanup_done']);
        $kurl_keywords_removed = isset($_GET['keywords_removed']) ? absint(wp_unslash($_GET['keywords_removed'])) : 0;
        $kurl_stats_removed    = isset($_GET['stats_removed']) ? absint(wp_unslash($_GET['stats_removed'])) : 0;
        $kurl_urls_normalized  = isset($_GET['urls_normalized']) ? absint(wp_unslash($_GET['urls_normalized'])) : 0;

        $kurl_reconcile_done   = isset($_GET['reconcile_done']);
        $kurl_imported_count   = isset($_GET['imported']) ? absint(wp_unslash($_GET['imported'])) : 0;
        $kurl_replaced_count   = isset($_GET['replaced']) ? absint(wp_unslash($_GET['replaced'])) : 0;
        $kurl_verified_count   = isset($_GET['verified']) ? absint(wp_unslash($_GET['verified'])) : 0;
        $kurl_mismatches_count = isset($_GET['mismatches']) ? absint(wp_unslash($_GET['mismatches'])) : 0;
        $kurl_skipped_count    = isset($_GET['skipped']) ? absint(wp_unslash($_GET['skipped'])) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap kurl-admin">';
        echo '<div class="kurl-page-head"><div><h1>' . esc_html__('Sync & Cleanup', 'kurl-yourls') . '</h1><p class="kurl-subtitle">' . esc_html__('Experimental tools to compare WordPress content with your YOURLS database and clean stale local data.', 'kurl-yourls') . '</p></div></div>';

        if ($kurl_cleanup_done) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                /* translators: 1: Number of keywords removed. 2: Number of stats entries removed. 3: Number of URLs normalized. */
                __('Local cleanup finished. Keywords removed: %1$d, stats removed: %2$d, URLs normalized: %3$d.', 'kurl-yourls'),
                $kurl_keywords_removed,
                $kurl_stats_removed,
                $kurl_urls_normalized
            )) . '</p></div>';
        }

        if ($kurl_reconcile_done) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                /* translators: 1: Imported count. 2: Replaced count. 3: Verified count. 4: Mismatch count. 5: Skipped count. */
                __('Reconciliation finished. Imported: %1$d, replaced: %2$d, verified: %3$d, mismatches: %4$d, skipped: %5$d.', 'kurl-yourls'),
                $kurl_imported_count,
                $kurl_replaced_count,
                $kurl_verified_count,
                $kurl_mismatches_count,
                $kurl_skipped_count
            )) . '</p></div>';
        }
        echo '<div class="kurl-grid">';
        echo '<div class="kurl-panel"><h2>' . esc_html__('Local database cleanup', 'kurl-yourls') . '</h2><p>' . esc_html__('Removes stale keywords and cached stats that no longer belong to a saved short URL and normalizes stored URLs.', 'kurl-yourls') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('kurl_cleanup_local');
        echo '<input type="hidden" name="action" value="kurl_cleanup_local">';
        submit_button(__('Clean local database', 'kurl-yourls'), 'secondary', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Run the local cleanup now?', 'kurl-yourls')) . "');"]);
        echo '</form></div>';
        echo '<div class="kurl-panel"><h2>' . esc_html__('YOURLS reconcile / compare', 'kurl-yourls') . '</h2><p>' . esc_html__('Checks enabled posts against YOURLS in AJAX batches to avoid timeouts. If the helper plugin can find a matching long URL, kURL can import or replace the local entry. Existing local short URLs are also verified against the current permalink.', 'kurl-yourls') . '</p><p><strong>' . esc_html__('Experimental:', 'kurl-yourls') . '</strong> ' . esc_html__('Start with preview mode, review the report, and only then apply changes. Back up your WordPress database before applying reconciliation changes on a large site.', 'kurl-yourls') . '</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-reconcile-batch-size">' . esc_html__('Batch size', 'kurl-yourls') . '</label></th><td><select id="kurl-reconcile-batch-size"><option selected>5</option><option>10</option><option>25</option></select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Mode', 'kurl-yourls') . '</th><td><label><input type="checkbox" id="kurl-reconcile-preview" checked="checked"> ' . esc_html__('Preview only (do not change WordPress data)', 'kurl-yourls') . '</label></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="button" class="button button-primary" id="kurl-reconcile-start">' . esc_html__('Start reconciliation', 'kurl-yourls') . '</button> <button type="button" class="button" id="kurl-reconcile-stop">' . esc_html__('Stop', 'kurl-yourls') . '</button></p>';
        echo '<div class="kurl-progress"><div class="kurl-progress-bar" id="kurl-reconcile-progress-bar"></div></div>';
        echo '<div class="kurl-bulk-stats" id="kurl-reconcile-stats"></div>';
        echo '<div class="kurl-log-box kurl-light-log" id="kurl-reconcile-log"></div>';
        echo '</div>';
        echo '</div></div>';
    }

    public static function render_logs(): void {
        self::assert_manage_options();
        $entries = Kurl_Logger::get_entries();
        echo '<div class="wrap kurl-admin"><div class="kurl-page-head"><div><h1>' . esc_html__('kURL Logs', 'kurl-yourls') . '</h1><p class="kurl-subtitle">' . esc_html__('The log keeps only the last 7 days and has size caps so WordPress does not get overloaded.', 'kurl-yourls') . '</p></div></div>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters.
        if (!empty($_GET['cleared'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Log cleared.', 'kurl-yourls') . '</p></div>';
        }
        echo '<div class="kurl-panel"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:18px;">';
        wp_nonce_field('kurl_clear_log');
        echo '<input type="hidden" name="action" value="kurl_clear_log">';
        submit_button(__('Delete log', 'kurl-yourls'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Delete the current log?', 'kurl-yourls')) . "');"]);
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
            echo '<p>' . esc_html__('No log entries in the last 7 days.', 'kurl-yourls') . '</p>';
        }
        echo '</div></div></div>';
    }

    public static function render_settings(): void {
        self::assert_manage_options();
        $settings = Kurl_Helpers::get_settings();
        $post_types = get_post_types(['public' => true], 'objects');
        echo '<div class="wrap kurl-admin"><div class="kurl-page-head"><div><h1>' . esc_html__('kURL Settings', 'kurl-yourls') . '</h1></div></div>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters.
        $settings_updated = !empty($_GET['updated']);
        if ($settings_updated) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'kurl-yourls') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters.
        $settings_disconnected = !empty($_GET['disconnected']);
        if ($settings_disconnected) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Successfully disconnected. Your API credentials have been cleared.', 'kurl-yourls') . '</p></div>';
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice parameters on a protected admin screen.
        $legacy_import_notice  = isset($_GET['imported']);
        $legacy_imported_count = isset($_GET['imported']) ? absint(wp_unslash($_GET['imported'])) : 0;
        $legacy_skipped_count  = isset($_GET['skipped']) ? absint(wp_unslash($_GET['skipped'])) : 0;
        $legacy_deleted_notice = isset($_GET['deleted']);
        $legacy_deleted_count  = isset($_GET['deleted']) ? absint(wp_unslash($_GET['deleted'])) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($legacy_import_notice) {
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                /* translators: 1: Imported count. 2: Skipped count. */
                __('Imported %1$d old links and skipped %2$d existing ones.', 'kurl-yourls'),
                $legacy_imported_count,
                $legacy_skipped_count
            )) . '</p></div>';
        }

        if ($legacy_deleted_notice) {
            echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
                /* translators: %d: Number of deleted legacy meta rows. */
                __('Deleted %d old Better YOURLS meta rows.', 'kurl-yourls'),
                $legacy_deleted_count
            )) . '</p></div>';
        }
        echo '<div class="kurl-grid">';
        echo '<div class="kurl-panel"><div class="kurl-test-box"><div><h2>' . esc_html__('API connection', 'kurl-yourls') . '</h2>';
        echo !empty($settings['api_extended']) ? '<p style="color:#166534;font-weight:600;">' . esc_html__('✅ kURL Helper plugin detected. Remote deletion and safe lookup are enabled.', 'kurl-yourls') . '</p>' : '<p style="color:#b45309;font-weight:600;">' . esc_html__('⚠️ Standard API only. Remote deletion and safe reverse lookup are disabled.', 'kurl-yourls') . '</p>';
        echo '</div><div><button type="button" class="button button-primary button-large" id="kurl-test-api">' . esc_html__('Test API', 'kurl-yourls') . '</button><div id="kurl-test-api-result" class="kurl-test-result"></div></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('kurl_save_settings');
        echo '<input type="hidden" name="action" value="kurl_save_settings"><table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="kurl-api-url">' . esc_html__('YOURLS API URL', 'kurl-yourls') . '</label></th><td><input id="kurl-api-url" type="url" name="api_url" class="regular-text code" value="' . esc_attr($settings['api_url']) . '" placeholder="https://yalla.li"><p class="description">' . wp_kses_post(__('Enter your main YOURLS domain (for example <code>https://yalla.li</code>). kURL will automatically append <code>/yourls-api.php</code> when needed.', 'kurl-yourls')) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-signature">' . esc_html__('Signature token', 'kurl-yourls') . '</label></th><td><input id="kurl-signature" type="password" name="signature" autocomplete="off" class="regular-text code" value="' . esc_attr($settings['signature']) . '"><p class="description">' . wp_kses_post(__('You can find your signature token in the YOURLS admin area under <strong>Tools → Secure passwordless API call</strong>.', 'kurl-yourls')) . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Enabled post types', 'kurl-yourls') . '</th><td>';
        foreach ($post_types as $post_type => $object) {
            $checked = in_array($post_type, $settings['enabled_post_types'], true) ? 'checked' : '';
            echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="enabled_post_types[]" value="' . esc_attr($post_type) . '" ' . esc_attr($checked) . '> ' . esc_html($object->labels->singular_name) . '</label>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="kurl-cache-minutes">' . esc_html__('Cache (minutes)', 'kurl-yourls') . '</label></th><td><input id="kurl-cache-minutes" type="number" name="cache_minutes" min="1" value="' . esc_attr((string) $settings['cache_minutes']) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="kurl-request-timeout">' . esc_html__('API timeout', 'kurl-yourls') . '</label></th><td><input id="kurl-request-timeout" type="number" name="request_timeout" min="5" value="' . esc_attr((string) $settings['request_timeout']) . '"></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Auto create', 'kurl-yourls') . '</th><td><label><input type="checkbox" name="auto_create_on_publish" value="1" ' . checked(1, (int) $settings['auto_create_on_publish'], false) . '> ' . esc_html__('Automatically create a short URL when publishing', 'kurl-yourls') . '</label></td></tr>';
        $delete_data = (int) get_option('kurl_delete_data', 0);
        echo '<tr><th scope="row">' . esc_html__('Uninstall behavior', 'kurl-yourls') . '</th><td><label><input type="checkbox" name="delete_data" value="1" ' . checked(1, $delete_data, false) . '> ' . esc_html__('Delete all kURL data on uninstall', 'kurl-yourls') . '</label><p class="description" style="color:#d63638;font-weight:600;">' . esc_html__('Warning: if enabled, all plugin data, including links, cached stats, and logs, will be permanently removed when the plugin is uninstalled.', 'kurl-yourls') . '</p></td></tr>';
        echo '</tbody></table><p class="submit">';
        submit_button(__('Save settings', 'kurl-yourls'), 'primary', 'submit', false);
        echo ' ';
        if (!empty($settings['api_url'])) {
            submit_button(
                __('Disconnect API', 'kurl-yourls'),
                'delete',
                'disconnect_api',
                false,
                ['onclick' => "return confirm('" . esc_js(__('Are you sure you want to disconnect? This will clear your API URL and signature.', 'kurl-yourls')) . "');"]
            );
        }
        echo '</p></form></div>';
        if (empty($settings['api_extended'])) {
            $plugin_code = self::get_helper_plugin_code();
            echo '<div class="kurl-panel" style="border-left:4px solid #f59e0b;"><h2 style="color:#b45309;">' . esc_html__('Enable true remote deletion and safe lookup (optional)', 'kurl-yourls') . '</h2><p>' . esc_html__('By default, YOURLS does not allow external apps to edit or delete links, and it has no built-in reverse-lookup endpoint for “find this long URL without creating one”.', 'kurl-yourls') . '</p><p>' . esc_html__('Install the official kURL helper extension on your YOURLS server to enable remote deletion and safe long-URL lookup for sync and cleanup features.', 'kurl-yourls') . '</p><ol><li>' . esc_html__('Connect to your YOURLS server via FTP or SSH.', 'kurl-yourls') . '</li><li>' . wp_kses_post(__('Navigate to <code>user/plugins/</code> and create a folder named <code>kurl-api</code>.', 'kurl-yourls')) . '</li><li>' . wp_kses_post(__('Create a file inside that folder called <code>plugin.php</code> and paste the code below into it.', 'kurl-yourls')) . '</li><li>' . esc_html__('Log into your YOURLS admin area, open Manage Plugins, and activate “kURL Helper”.', 'kurl-yourls') . '</li><li>' . esc_html__('Return here and click “Test API” so kURL can detect it.', 'kurl-yourls') . '</li></ol><div style="margin-top:14px;margin-bottom:6px;"><button type="button" class="button kurl-copy-code">' . esc_html__('Copy code to clipboard', 'kurl-yourls') . '</button> <span class="kurl-copy-status" style="color:#166534;margin-left:8px;display:none;font-weight:600;">✓ ' . esc_html__('Copied!', 'kurl-yourls') . '</span></div><textarea readonly id="kurl-extension-code" class="large-text code" rows="28" style="font-family:monospace;font-size:12px;background:#f8fafc;margin-top:0;">' . esc_textarea($plugin_code) . '</textarea></div>';
        }
        echo '<div class="kurl-panel"><h2>' . esc_html__('Migration tools', 'kurl-yourls') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:16px;">';
        wp_nonce_field('kurl_import_legacy');
        echo '<input type="hidden" name="action" value="kurl_import_legacy">';
        submit_button(__('Import from Better YOURLS', 'kurl-yourls'), 'secondary', 'submit', false);
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('kurl_delete_legacy');
        echo '<input type="hidden" name="action" value="kurl_delete_legacy">';
        submit_button(__('Delete old Better YOURLS data', 'kurl-yourls'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Delete old Better YOURLS data?', 'kurl-yourls')) . "');"]);
        echo '</form><p class="description">' . esc_html__('Import first, verify a few posts, then delete the old Better YOURLS data once everything looks correct.', 'kurl-yourls') . '</p></div>';
        echo '</div></div>';
    }


    private static function assert_manage_options_ajax(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-yourls')], 403);
        }
        if (!Kurl_API::configured()) {
            wp_send_json_error(['message' => __('Please configure the YOURLS API first.', 'kurl-yourls')], 400);
        }
    }

    private static function assert_manage_options(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kurl-yourls'));
        }
    }

    private static function should_enqueue_assets(string $hook): bool {
        return $hook === 'post.php' || $hook === 'post-new.php' || strpos($hook, 'kurl') !== false;
    }

    private static function clear_post_link_meta(int $post_id): void {
        delete_post_meta($post_id, KURL_META_URL);
        delete_post_meta($post_id, KURL_META_KEYWORD);
        delete_post_meta($post_id, KURL_META_STATS);
    }

    private static function normalize_api_url(string $raw_url): string {
        $raw_url = trim($raw_url);
        if ($raw_url === '') {
            return '';
        }
        $raw_url = esc_url_raw($raw_url);
        if ($raw_url === '') {
            return '';
        }
        $path = wp_parse_url($raw_url, PHP_URL_PATH);
        if (!is_string($path) || substr($path, -4) !== '.php') {
            $raw_url = rtrim($raw_url, '/') . '/yourls-api.php';
        }
        return esc_url_raw($raw_url);
    }

    private static function shorturl_matches_keyword(string $shorturl, string $keyword): bool {
        $path = wp_parse_url($shorturl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }
        $slug = basename(trim($path, '/'));
        $slug = Kurl_Helpers::sanitize_keyword($slug);
        return $slug !== '' && $slug === Kurl_Helpers::sanitize_keyword($keyword);
    }

    private static function get_reconcile_batch_post_ids(int $last_id, int $batch_size): array {
        self::$reconcile_cursor_last_id = $last_id;
        add_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10, 2);

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
        ]);

        remove_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10);
        self::$reconcile_cursor_last_id = 0;

        return is_array($query->posts) ? $query->posts : [];
    }

    public static function filter_reconcile_posts_where_after_id(string $where, WP_Query $query): string {
        global $wpdb;
        if (self::$reconcile_cursor_last_id > 0) {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", self::$reconcile_cursor_last_id);
        }
        return $where;
    }

    private static function get_enabled_post_ids_batch(int $last_id = 0, int $limit = 250): array {
        self::$reconcile_cursor_last_id = $last_id;
        add_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10, 2);

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
        ]);

        remove_filter('posts_where', [__CLASS__, 'filter_reconcile_posts_where_after_id'], 10);
        self::$reconcile_cursor_last_id = 0;

        return is_array($query->posts) ? $query->posts : [];
    }

    private static function get_helper_plugin_code(): string {
        return <<<'EOD'
<?php
/*
Plugin Name: kURL Helper
Description: Enables remote deletion, safe long-URL lookup, and advanced API pinging for the kURL WordPress plugin.
Version: 1.0.0
Author: Gerald Drißner
*/

if ( ! defined( 'YOURLS_ABSPATH' ) ) {
    die();
}

yourls_add_filter( 'api_action_kurl_ping', 'kurl_api_ping' );
yourls_add_filter( 'api_action_kurl_delete', 'kurl_api_delete' );
yourls_add_filter( 'api_action_kurl_find_by_url', 'kurl_api_find_by_url' );

function kurl_api_ping() {
    return [
        'statusCode'    => 200,
        'status'        => 'success',
        'message'       => 'success',
        'kurl_extended' => true,
    ];
}

function kurl_api_delete() {
    if ( empty( $_REQUEST['shorturl'] ) ) {
        return [
            'statusCode' => 400,
            'status'     => 'fail',
            'message'    => 'Missing shorturl',
        ];
    }

    $shorturl = trim( (string) $_REQUEST['shorturl'] );
    $path     = wp_parse_url( $shorturl, PHP_URL_PATH );
    $keyword  = is_string( $path ) ? basename( trim( $path, '/' ) ) : '';
    $keyword  = preg_replace( '~[^A-Za-z0-9\-_]~', '', $keyword );

    if ( $keyword === '' ) {
        return [
            'statusCode' => 400,
            'status'     => 'fail',
            'message'    => 'Invalid shorturl',
        ];
    }

    if ( yourls_is_shorturl( $keyword ) ) {
        yourls_delete_link_by_keyword( $keyword );
        return [
            'statusCode' => 200,
            'status'     => 'success',
            'message'    => 'Deleted',
        ];
    }

    return [
        'statusCode' => 404,
        'status'     => 'fail',
        'message'    => 'Not found',
    ];
}

function kurl_api_find_by_url() {
    if ( empty( $_REQUEST['url'] ) ) {
        return [
            'statusCode' => 400,
            'status'     => 'fail',
            'message'    => 'Missing url',
        ];
    }

    $longurl = trim( (string) $_REQUEST['url'] );
    if ( $longurl === '' ) {
        return [
            'statusCode' => 400,
            'status'     => 'fail',
            'message'    => 'Invalid url',
        ];
    }

    $table = YOURLS_DB_TABLE_URL;
    $sql   = "SELECT keyword, url, title FROM `$table` WHERE `url` = :url ORDER BY keyword ASC LIMIT 1";
    $binds = [ 'url' => $longurl ];
    $row   = yourls_get_db()->fetchObject( $sql, $binds );

    if ( ! $row || empty( $row->keyword ) ) {
        return [
            'statusCode' => 404,
            'status'     => 'fail',
            'message'    => 'Not found',
        ];
    }

    return [
        'statusCode' => 200,
        'status'     => 'success',
        'message'    => 'Found',
        'shorturl'   => yourls_link( $row->keyword ),
        'longurl'    => (string) $row->url,
        'keyword'    => (string) $row->keyword,
        'title'      => isset( $row->title ) ? (string) $row->title : '',
    ];
}
EOD;
    }
}
