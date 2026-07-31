<?php

defined('ABSPATH') || exit;

final class Kurl_Bulk {


    public static function init(): void {
        add_action('wp_ajax_kurl_bulk_batch', [__CLASS__, 'ajax_batch']);
    }

    public static function ajax_batch(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-short-url-manager-yourls')], 403);
        }
        $post_type = isset($_POST['post_type'])
            ? sanitize_key(Kurl_Helpers::request_string($_POST['post_type']))
            : 'post';
        if (!Kurl_Helpers::is_supported_post_type($post_type)) {
            wp_send_json_error(['message' => __('Post type not enabled.', 'kurl-short-url-manager-yourls')], 400);
        }
        $batch_size = isset($_POST['batch_size'])
            ? max(1, min(50, absint(Kurl_Helpers::request_string($_POST['batch_size']))))
            : 10;
        $requested_mode = isset($_POST['mode']) ? Kurl_Helpers::request_string($_POST['mode']) : 'skip';
        $mode = in_array($requested_mode, ['skip', 'import', 'overwrite'], true) ? $requested_mode : 'skip';
        $last_id = isset($_POST['last_id'])
            ? max(0, absint(Kurl_Helpers::request_string($_POST['last_id'])))
            : 0;
        if (!Kurl_API::configured()) {
            wp_send_json_error(['message' => __('Please configure the YOURLS API first.', 'kurl-short-url-manager-yourls')], 400);
        }
        if ($mode === 'overwrite' && !Kurl_Admin::helper_is_ready('regenerate')) {
            wp_send_json_error([
                'message' => sprintf(__('Overwrite mode requires the current bundled kURL Helper (%s). Update it on the Settings page and try again.', 'kurl-short-url-manager-yourls'), KURL_HELPER_VERSION),
            ], 400);
        }

        $posts = self::query_batch($post_type, $batch_size, $last_id);
        if (empty($posts)) {
            wp_send_json_success(['done' => true, 'results' => [], 'last_id' => $last_id]);
        }

        $results = [];
        $new_last_id = $last_id;
        $has_changes = false;

        foreach ($posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            $post_id = (int) $post->ID;
            $new_last_id = max($new_last_id, $post_id);
            $managed_shorturl = Kurl_Shortlinks::get_managed_shorturl($post_id);
            $legacy_shorturl  = self::get_legacy_url($post_id);
            $current_shorturl = $managed_shorturl !== '' ? $managed_shorturl : $legacy_shorturl;
            $title = get_the_title($post_id);

            if ($mode === 'skip' && $current_shorturl !== '') {
                /* translators: %s: Existing short URL. */
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'skipped_existing', 'message' => sprintf(__('Already has URL: %s', 'kurl-short-url-manager-yourls'), $current_shorturl)];
                continue;
            }

            if ($mode === 'import' && $managed_shorturl !== '') {
                /* translators: %s: Existing kURL-managed short URL. */
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'skipped_existing', 'message' => sprintf(__('Already imported: %s', 'kurl-short-url-manager-yourls'), $managed_shorturl)];
                continue;
            }

            if ($mode === 'import' && $legacy_shorturl !== '') {
                Kurl_Shortlinks::save_link($post_id, $legacy_shorturl);
                /* translators: %s: Imported legacy short URL. */
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'imported', 'message' => sprintf(__('Imported old URL: %s', 'kurl-short-url-manager-yourls'), $legacy_shorturl)];
                Kurl_Logger::log('info', 'Bulk imported legacy shortlink', ['post_id' => $post_id, 'shorturl' => $legacy_shorturl]);
                $has_changes = true;
                continue;
            }

            $permalink = get_permalink($post_id);
            if (!is_string($permalink) || $permalink === '') {
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'error', 'message' => __('Could not get permalink.', 'kurl-short-url-manager-yourls')];
                continue;
            }

            if ($mode === 'overwrite' && $current_shorturl !== '') {
                if (Kurl_Shortlinks::count_other_references($current_shorturl, $post_id) > 0) {
                    $results[] = [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => 'error',
                        'message' => __('This short URL is shared with another WordPress post, so kURL did not edit the remote entry.', 'kurl-short-url-manager-yourls'),
                    ];
                    continue;
                }
                if (!Kurl_Admin::helper_is_ready('regenerate')) {
                    $results[] = [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'status'  => 'error',
                        'message' => __('Safe overwrite requires the current bundled kURL Helper. Update the helper on the Settings page before using overwrite mode.', 'kurl-short-url-manager-yourls'),
                    ];
                    continue;
                }
                $api_response = Kurl_API::regenerate_shortlink($permalink, $current_shorturl, '', is_string($title) ? $title : '');
            } else {
                $api_response = Kurl_API::create_shortlink($permalink, '', is_string($title) ? $title : '');
            }
            if (empty($api_response['ok'])) {
                $message = Kurl_Helpers::format_api_error($api_response);
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'error', 'message' => $message];
                Kurl_Logger::log('error', 'Bulk generation API error', ['post_id' => $post_id, 'message' => $message]);
                continue;
            }

            $shorturl = Kurl_API::extract_shorturl($api_response);
            if ($shorturl === '') {
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'error', 'message' => __('API did not return a short URL.', 'kurl-short-url-manager-yourls')];
                continue;
            }

            Kurl_Shortlinks::save_link($post_id, $shorturl);
            $status = $current_shorturl !== '' ? 'updated' : 'created';
            /* translators: %s: Saved short URL. */
            $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => $status, 'message' => sprintf(__('Saved: %s', 'kurl-short-url-manager-yourls'), $shorturl)];
            Kurl_Logger::log('info', 'Bulk shortlink ' . $status, ['post_id' => $post_id, 'shorturl' => $shorturl]);
            $has_changes = true;
        }

        if ($has_changes) {
            delete_transient('kurl_dashboard_overview');
        }

        wp_send_json_success(['done' => false, 'results' => $results, 'last_id' => $new_last_id]);
    }

    private static function query_batch(string $post_type, int $batch_size, int $last_id): array {
        add_filter('posts_where', [__CLASS__, 'filter_posts_where_after_id'], 10, 2);
        try {
            $query = new WP_Query([
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => $batch_size,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters'       => false,
            'kurl_bulk_after_id'     => $last_id,
            ]);
        } finally {
            remove_filter('posts_where', [__CLASS__, 'filter_posts_where_after_id'], 10);
        }
        return isset($query) && is_array($query->posts) ? $query->posts : [];
    }

    public static function filter_posts_where_after_id(string $where, WP_Query $query): string {
        global $wpdb;
        $last_id = absint($query->get('kurl_bulk_after_id'));
        if ($last_id > 0) {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $last_id);
        }
        return $where;
    }

    private static function get_legacy_url(int $post_id): string {
        $old_url = Kurl_Helpers::scalar_string(get_post_meta($post_id, KURL_OLD_META_URL, true));
        return Kurl_Helpers::sanitize_http_url($old_url);
    }
}
