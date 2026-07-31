<?php

defined('ABSPATH') || exit;

final class Kurl_Shortlinks {

    public static function init(): void {
        add_filter('pre_get_shortlink', [__CLASS__, 'filter_shortlink'], 10, 4);
        add_action('transition_post_status', [__CLASS__, 'maybe_create_on_publish'], 10, 3);
    }

    public static function filter_shortlink($shortlink, $post_id, string $context, bool $allow_slugs) {
        unset($allow_slugs);

        // Normalize $post_id — it can be int, string, WP_Post, or 0.
        if ($post_id instanceof WP_Post) {
            $post_id = (int) $post_id->ID;
        } else {
            $post_id = (int) $post_id;
        }

        // Core calls wp_get_shortlink(0, 'query') from wp_shortlink_wp_head() and
        // wp_shortlink_header(), expecting the filter to resolve the current
        // queried object itself. If we bail on $post_id <= 0 we silently break
        // the <link rel="shortlink"> tag and the Link: HTTP header on every
        // singular view. Resolve it ourselves instead.
        if ($post_id <= 0) {
            if ($context !== 'query' || !is_singular()) {
                return $shortlink;
            }
            $post_id = (int) get_queried_object_id();
            if ($post_id <= 0) {
                return $shortlink;
            }
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

    public static function get_managed_shorturl(int $post_id): string {
        return $post_id > 0 ? self::get_saved_shorturl($post_id) : '';
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
        if (self::get_shorturl($post->ID) !== '') {
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
        $keyword_adjusted = $keyword !== ''
            && Kurl_Helpers::keyword_from_shorturl($shorturl) !== Kurl_Helpers::sanitize_keyword($keyword);
        self::save_link($post->ID, $shorturl, $keyword);
        delete_transient('kurl_dashboard_overview');
        Kurl_Logger::log(
            $keyword_adjusted ? 'warning' : 'info',
            $keyword_adjusted ? 'YOURLS adjusted the requested keyword during automatic creation' : 'Shortlink created automatically on publish',
            ['post_id' => $post->ID, 'shorturl' => $shorturl]
        );
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
        $actual_keyword = Kurl_Helpers::keyword_from_shorturl($shorturl);
        $keyword = $actual_keyword !== '' ? $actual_keyword : Kurl_Helpers::sanitize_keyword($keyword);
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

    public static function count_other_references(string $shorturl, int $exclude_post_id): int {
        global $wpdb;

        $shorturl = self::normalize_shorturl($shorturl);
        if ($shorturl === '') {
            return 0;
        }

        // Count distinct posts so a post carrying both managed and legacy meta is
        // not counted twice. A shared remote link must not be edited or deleted
        // from one post because that would break every other local reference.
        // Preserve a binary comparison because YOURLS keywords can be case-sensitive.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Targeted integrity check for plugin-owned URL meta.
        $exact_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN (%s, %s) AND BINARY meta_value = BINARY %s",
            max(0, $exclude_post_id),
            KURL_META_URL,
            KURL_OLD_META_URL,
            $shorturl
        ));
        if ($exact_count > 0) {
            return $exact_count;
        }

        // Imported data can contain the same YOURLS keyword with a harmless URL
        // spelling difference (host case, default port, trailing slash, or an old
        // alias hostname). An exact URL comparison would miss that shared remote
        // row. Search only final-segment candidates, then verify the decoded
        // keyword in PHP with an exact case-sensitive comparison. This slower
        // fallback runs only before a destructive/editing action and only when the
        // fast exact query found nothing.
        $keyword = Kurl_Helpers::keyword_from_shorturl($shorturl);
        if ($keyword === '') {
            return 0;
        }

        $segments = array_values(array_unique([
            $keyword,
            rawurlencode($keyword),
            strtolower(rawurlencode($keyword)),
        ]));
        $patterns = [];
        foreach ($segments as $segment) {
            $escaped = $wpdb->esc_like('/' . $segment);
            $patterns[] = '%' . $escaped;
            $patterns[] = '%' . $escaped . '/';
            $patterns[] = '%' . $escaped . '?%';
            $patterns[] = '%' . $escaped . '#%';
        }
        $patterns = array_values(array_unique($patterns));
        if (empty($patterns)) {
            return 0;
        }

        $like_sql = implode(' OR ', array_fill(0, count($patterns), 'BINARY meta_value LIKE BINARY %s'));
        $query_args = array_merge([
            max(0, $exclude_post_id),
            KURL_META_URL,
            KURL_OLD_META_URL,
        ], $patterns);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder-only LIKE clause are constructed locally; every value is supplied to prepare().
        $sql = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN (%s, %s) AND ({$like_sql})";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rare mutation-time integrity fallback for equivalent stored URL spellings.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$query_args));

        $post_ids = [];
        foreach ((array) $rows as $row) {
            $candidate_post_id = isset($row->post_id) ? (int) $row->post_id : 0;
            $candidate_url = isset($row->meta_value) && is_scalar($row->meta_value)
                ? self::normalize_shorturl((string) $row->meta_value)
                : '';
            if ($candidate_post_id <= 0 || $candidate_url === '') {
                continue;
            }
            if (Kurl_Helpers::keyword_from_shorturl($candidate_url) === $keyword) {
                $post_ids[$candidate_post_id] = true;
            }
        }

        return count($post_ids);
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
            $legacy_url = self::normalize_shorturl(isset($row->meta_value) && is_scalar($row->meta_value) ? (string) $row->meta_value : '');
            if ($legacy_url === '') {
                $skipped++;
                continue;
            }
            self::save_link($post_id, $legacy_url);
            $imported++;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public static function delete_legacy(): int {
        global $wpdb;
        delete_option(KURL_OLD_OPTION);

        // Count first so the settings notice can still report affected rows. Use
        // WordPress's metadata API for the deletion itself; unlike a direct SQL
        // DELETE, it invalidates post-meta caches, including persistent caches.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count before an explicit one-time migration cleanup.
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            KURL_OLD_META_URL
        ));
        if ($count <= 0) {
            return 0;
        }

        return delete_post_meta_by_key(KURL_OLD_META_URL) ? $count : 0;
    }

    public static function get_keyword(int $post_id): string {
        return $post_id > 0 ? Kurl_Helpers::sanitize_keyword(Kurl_Helpers::scalar_string(get_post_meta($post_id, KURL_META_KEYWORD, true))) : '';
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
        return Kurl_Helpers::sanitize_http_url($shorturl);
    }

    private static function get_saved_shorturl(int $post_id): string {
        return self::normalize_shorturl(Kurl_Helpers::scalar_string(get_post_meta($post_id, KURL_META_URL, true)));
    }

    private static function get_legacy_shorturl(int $post_id): string {
        return self::normalize_shorturl(Kurl_Helpers::scalar_string(get_post_meta($post_id, KURL_OLD_META_URL, true)));
    }

    private static function sanitize_stats(array $stats): array {
        $clean = [];
        if (isset($stats['clicks'])) {
            $clean['clicks'] = max(0, Kurl_Helpers::scalar_int($stats['clicks']));
        }
        if (isset($stats['updated'])) {
            $clean['updated'] = sanitize_text_field(Kurl_Helpers::scalar_string($stats['updated']));
        }
        foreach ($stats as $key => $value) {
            if (isset($clean[$key])) {
                continue;
            }
            $sanitized_key = sanitize_key((string) $key);
            if ($sanitized_key === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$sanitized_key] = sanitize_text_field((string) $value);
            }
        }
        return $clean;
    }
}
