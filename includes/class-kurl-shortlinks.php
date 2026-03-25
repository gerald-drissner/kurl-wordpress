<?php

defined('ABSPATH') || exit;

final class Kurl_Shortlinks {

    public static function init(): void {
        add_filter('pre_get_shortlink', [__CLASS__, 'filter_shortlink'], 10, 4);
        add_action('transition_post_status', [__CLASS__, 'maybe_create_on_publish'], 10, 3);
    }

    public static function filter_shortlink($shortlink, $post_id, string $context, bool $allow_slugs) {
        unset($context, $allow_slugs);
        $post_id = $post_id instanceof WP_Post ? (int) $post_id->ID : (int) $post_id;
        if ($post_id <= 0) {
            return $shortlink;
        }
        $saved = self::get_saved_shorturl($post_id);
        if ($saved !== '') {
            return $saved;
        }
        $legacy = self::get_legacy_shorturl($post_id);
        return $legacy !== '' ? $legacy : $shortlink;
    }

    public static function get_shorturl(int $post_id): string {
        if ($post_id <= 0) {
            return '';
        }
        $saved = self::get_saved_shorturl($post_id);
        return $saved !== '' ? $saved : self::get_legacy_shorturl($post_id);
    }

    public static function maybe_create_on_publish(string $new_status, string $old_status, WP_Post $post): void {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        if ($post->ID <= 0 || wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }
        if (!Kurl_Helpers::is_supported_post_type($post->post_type)) {
            return;
        }
        $settings = Kurl_Helpers::get_settings();
        if (empty($settings['auto_create_on_publish']) || !Kurl_API::configured()) {
            return;
        }
        if (self::get_saved_shorturl($post->ID) !== '') {
            return;
        }
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            Kurl_Logger::log('error', 'Auto-create on publish failed: missing permalink', ['post_id' => $post->ID]);
            return;
        }
        $keyword = self::get_keyword($post->ID);
        $title = get_the_title($post);
        $response = Kurl_API::create_shortlink($permalink, $keyword, is_string($title) ? $title : '');
        if (empty($response['ok'])) {
            Kurl_Logger::log('error', 'Auto-create on publish failed', ['post_id' => $post->ID, 'message' => Kurl_Helpers::format_api_error($response)]);
            return;
        }
        $shorturl = self::extract_shorturl_from_response($response);
        if ($shorturl === '') {
            Kurl_Logger::log('error', 'Auto-create on publish failed: no short URL returned', ['post_id' => $post->ID]);
            return;
        }
        self::save_link($post->ID, $shorturl, $keyword);
        delete_transient('kurl_dashboard_overview');
        Kurl_Logger::log('info', 'Shortlink created automatically on publish', ['post_id' => $post->ID, 'shorturl' => $shorturl]);
    }

    public static function save_link(int $post_id, string $shorturl, string $keyword = ''): void {
        if ($post_id <= 0) {
            return;
        }
        $shorturl = self::normalize_shorturl($shorturl);
        if ($shorturl === '') {
            return;
        }
        update_post_meta($post_id, KURL_META_URL, $shorturl);
        $keyword = Kurl_Helpers::sanitize_keyword($keyword);
        if ($keyword !== '') {
            update_post_meta($post_id, KURL_META_KEYWORD, $keyword);
        } else {
            delete_post_meta($post_id, KURL_META_KEYWORD);
        }
    }

    public static function save_stats(int $post_id, array $stats): void {
        if ($post_id > 0) {
            update_post_meta($post_id, KURL_META_STATS, self::sanitize_stats($stats));
        }
    }

    public static function get_stats(int $post_id): array {
        if ($post_id <= 0) {
            return [];
        }
        $stats = get_post_meta($post_id, KURL_META_STATS, true);
        return is_array($stats) ? $stats : [];
    }

    public static function count_saved(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Efficient aggregate count for plugin-managed meta.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", KURL_META_URL));
    }

    public static function import_legacy(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy one-time migration query.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", KURL_OLD_META_URL));
        $imported = 0;
        $skipped = 0;
        foreach ((array) $rows as $row) {
            $post_id = isset($row->post_id) ? (int) $row->post_id : 0;
            if ($post_id <= 0 || self::get_saved_shorturl($post_id) !== '') {
                $skipped++;
                continue;
            }
            $legacy_url = self::normalize_shorturl((string) $row->meta_value);
            if ($legacy_url === '') {
                $skipped++;
                continue;
            }
            update_post_meta($post_id, KURL_META_URL, $legacy_url);
            $imported++;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public static function delete_legacy(): int {
        global $wpdb;
        delete_option(KURL_OLD_OPTION);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Legacy cleanup by plugin-owned meta key during explicit migration cleanup.
        $deleted = (int) $wpdb->delete($wpdb->postmeta, ['meta_key' => KURL_OLD_META_URL], ['%s']);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key

        return $deleted;
    }

    public static function get_keyword(int $post_id): string {
        return $post_id > 0 ? Kurl_Helpers::sanitize_keyword((string) get_post_meta($post_id, KURL_META_KEYWORD, true)) : '';
    }

    public static function extract_shorturl_from_response(array $response): string {
        $shorturl = '';
        if (!empty($response['shorturl']) && is_string($response['shorturl'])) {
            $shorturl = $response['shorturl'];
        } elseif (!empty($response['url']['shorturl']) && is_string($response['url']['shorturl'])) {
            $shorturl = $response['url']['shorturl'];
        }
        return self::normalize_shorturl($shorturl);
    }

    private static function normalize_shorturl(string $shorturl): string {
        $shorturl = trim($shorturl);
        if ($shorturl === '') {
            return '';
        }
        $shorturl = esc_url_raw($shorturl);
        return is_string($shorturl) ? trim($shorturl) : '';
    }

    private static function get_saved_shorturl(int $post_id): string {
        return self::normalize_shorturl((string) get_post_meta($post_id, KURL_META_URL, true));
    }

    private static function get_legacy_shorturl(int $post_id): string {
        return self::normalize_shorturl((string) get_post_meta($post_id, KURL_OLD_META_URL, true));
    }

    private static function sanitize_stats(array $stats): array {
        $clean = [];
        if (isset($stats['clicks'])) {
            $clean['clicks'] = max(0, (int) $stats['clicks']);
        }
        if (isset($stats['updated'])) {
            $clean['updated'] = sanitize_text_field((string) $stats['updated']);
        }
        foreach ($stats as $key => $value) {
            if (isset($clean[$key])) {
                continue;
            }
            $sanitized_key = sanitize_key((string) $key);
            if (is_scalar($value) || $value === null) {
                $clean[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }
        return $clean;
    }
}
