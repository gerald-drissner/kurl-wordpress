<?php

defined('ABSPATH') || exit;

final class Kurl_Bulk {

    private static int $cursor_last_id = 0;

    public static function init(): void {
        add_action('wp_ajax_kurl_bulk_batch', [__CLASS__, 'ajax_batch']);
    }

    public static function ajax_batch(): void {
        check_ajax_referer('kurl_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'kurl-yourls')], 403);
        }
        $request        = wp_unslash($_POST);
        $raw_post_type  = $request['post_type'] ?? 'post';
        $raw_batch_size = $request['batch_size'] ?? 10;
        $raw_mode       = $request['mode'] ?? 'skip';
        $raw_last_id    = $request['last_id'] ?? 0;

        $post_type = sanitize_key((string) $raw_post_type);
        if (!Kurl_Helpers::is_supported_post_type($post_type)) {
            wp_send_json_error(['message' => __('Post type not enabled.', 'kurl-yourls')], 400);
        }
        $batch_size = max(1, min(50, absint($raw_batch_size)));
        $mode = in_array((string) $raw_mode, ['skip', 'import', 'overwrite'], true) ? (string) $raw_mode : 'skip';
        $last_id = max(0, absint($raw_last_id));
        if (!Kurl_API::configured()) {
            wp_send_json_error(['message' => __('Please configure the YOURLS API first.', 'kurl-yourls')], 400);
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
            $current_shorturl = Kurl_Shortlinks::get_shorturl($post_id);
            $title = get_the_title($post_id);

            if ($mode === 'skip' && $current_shorturl !== '') {
                /* translators: %s: Existing short URL. */
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'skipped_existing', 'message' => sprintf(__('Already has URL: %s', 'kurl-yourls'), $current_shorturl)];
                continue;
            }

            if ($mode === 'import' && $current_shorturl === '') {
                $old_url = self::get_legacy_url($post_id);
                if ($old_url !== '') {
                    Kurl_Shortlinks::save_link($post_id, $old_url);
                    /* translators: %s: Imported legacy short URL. */
                    $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'imported', 'message' => sprintf(__('Imported old URL: %s', 'kurl-yourls'), $old_url)];
                    Kurl_Logger::log('info', 'Bulk imported legacy shortlink', ['post_id' => $post_id, 'shorturl' => $old_url]);
                    $has_changes = true;
                    continue;
                }
            }

            $permalink = get_permalink($post_id);
            if (!is_string($permalink) || $permalink === '') {
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'error', 'message' => __('Could not get permalink.', 'kurl-yourls')];
                continue;
            }

            $api_response = Kurl_API::create_shortlink($permalink, '', is_string($title) ? $title : '');
            if (empty($api_response['ok'])) {
                $message = Kurl_Helpers::format_api_error($api_response);
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'error', 'message' => $message];
                Kurl_Logger::log('error', 'Bulk generation API error', ['post_id' => $post_id, 'message' => $message]);
                continue;
            }

            $shorturl = Kurl_API::extract_shorturl($api_response);
            if ($shorturl === '') {
                $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => 'error', 'message' => __('API did not return a short URL.', 'kurl-yourls')];
                continue;
            }

            Kurl_Shortlinks::save_link($post_id, $shorturl);
            $status = $current_shorturl !== '' ? 'updated' : 'created';
            /* translators: %s: Saved short URL. */
            $results[] = ['post_id' => $post_id, 'title' => $title, 'status' => $status, 'message' => sprintf(__('Saved: %s', 'kurl-yourls'), $shorturl)];
            Kurl_Logger::log('info', 'Bulk shortlink ' . $status, ['post_id' => $post_id, 'shorturl' => $shorturl]);
            $has_changes = true;
        }

        if ($has_changes) {
            delete_transient('kurl_dashboard_overview');
        }

        wp_send_json_success(['done' => false, 'results' => $results, 'last_id' => $new_last_id]);
    }

    private static function query_batch(string $post_type, int $batch_size, int $last_id): array {
        self::$cursor_last_id = $last_id;
        add_filter('posts_where', [__CLASS__, 'filter_posts_where_after_id'], 10, 2);
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
        ]);
        remove_filter('posts_where', [__CLASS__, 'filter_posts_where_after_id'], 10);
        self::$cursor_last_id = 0;
        return is_array($query->posts) ? $query->posts : [];
    }

    public static function filter_posts_where_after_id(string $where, WP_Query $query): string {
        global $wpdb;
        if (self::$cursor_last_id > 0) {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", self::$cursor_last_id);
        }
        return $where;
    }

    private static function get_legacy_url(int $post_id): string {
        $old_url = (string) get_post_meta($post_id, KURL_OLD_META_URL, true);
        $old_url = trim(esc_url_raw($old_url));
        return is_string($old_url) ? $old_url : '';
    }
}
