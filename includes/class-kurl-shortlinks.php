<?php

defined('ABSPATH') || exit;

final class Kurl_Shortlinks {

    /** count_other_references() result when the database could not answer. */
    public const REFERENCES_UNKNOWN = -1;

    private const MIGRATION_BATCH = 500;

    public static function init(): void {
        // Always registered; the setting is evaluated per call so it follows
        // switch_to_blog() on multisite.
        add_filter('pre_get_shortlink', [__CLASS__, 'filter_shortlink'], 10, 4);
        // wp_after_insert_post runs after terms and meta are saved, including
        // REST/block-editor saves and scheduled publishing. transition_post_status
        // fires before the REST controller assigns terms, so permalinks with
        // %category% or custom taxonomy tags could still point to the default
        // term at that moment.
        add_action('wp_after_insert_post', [__CLASS__, 'maybe_create_after_insert'], 10, 4);
    }

    /**
     * pre_get_shortlink callback.
     *
     * All parameters after the first are optional and untyped because third-party
     * code sometimes applies this filter with fewer or differently typed values.
     *
     * @param false|string $shortlink   Short-circuit value.
     * @param mixed        $post_id     Post ID, WP_Post, or 0 for the current post.
     * @param mixed        $context     'post' or 'query'.
     * @param mixed        $allow_slugs Unused.
     * @return false|string
     */
    public static function filter_shortlink($shortlink, $post_id = 0, $context = 'post', $allow_slugs = true) {
        unset($allow_slugs);

        $mode = Kurl_Helpers::shortlink_mode();
        if ($mode === Kurl_Helpers::SHORTLINK_OFF) {
            return $shortlink;
        }
        // An earlier callback already decided (another plugin's URL, or ''
        // to suppress the shortlink). Respect it unless told to override.
        if ($shortlink !== false && $mode !== Kurl_Helpers::SHORTLINK_OVERRIDE) {
            return $shortlink;
        }

        // A non-string context is neither 'post' nor 'query'; core returns no
        // shortlink for it, so kURL leaves the value untouched as well.
        $post_id = self::resolve_core_post_id($post_id, is_string($context) ? $context : '');
        if ($post_id <= 0) {
            return $shortlink;
        }

        $shorturl = self::get_shorturl($post_id);
        return $shorturl !== '' ? $shorturl : $shortlink;
    }

    /**
     * Resolve the post exactly as wp_get_shortlink() does, so kURL replaces the
     * link for the same post WordPress would have used:
     *
     * - 'query' uses the queried object on singular views and ignores the ID,
     *   as core does (wp_head, the Link header, and the admin bar use it).
     * - 'post' uses get_post($id); ID 0 means the current global post. This is
     *   the default of a bare wp_get_shortlink() call, for example in a footer.
     * - Any other context (for example 'blog' with a site ID) is not a post
     *   reference; core returns no shortlink and neither does kURL.
     *
     * @param mixed  $post_id Post ID, WP_Post, or 0.
     * @param string $context Shortlink context.
     */
    private static function resolve_core_post_id($post_id, string $context): int {
        if ($context === 'query') {
            return is_singular() ? (int) get_queried_object_id() : 0;
        }
        if ($context !== 'post') {
            return 0;
        }
        if ($post_id instanceof WP_Post) {
            return (int) $post_id->ID;
        }
        if ($post_id !== null && $post_id !== '' && !is_numeric($post_id)) {
            return 0;
        }
        $post = get_post(is_numeric($post_id) ? (int) $post_id : 0);
        return $post instanceof WP_Post ? (int) $post->ID : 0;
    }

    /**
     * Tell page caches that the shortlink output of a post changed: the
     * rel="shortlink" tag, the Link header, and any footer or shortcode output.
     * Called after user-triggered changes, not during automatic creation (the
     * publish request purges caches anyway) or the legacy import (the visible
     * URL does not change).
     */
    public static function purge_post_cache(int $post_id): void {
        if ($post_id <= 0) {
            return;
        }
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }

        /**
         * Fires after the kURL short URL of a post was created, replaced, or removed.
         * Use it to purge other page or edge caches.
         *
         * @param int $post_id Post ID.
         */
        do_action('kurl_shortlink_changed', $post_id);
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

    /**
     * wp_after_insert_post callback: create a short URL the first time a post
     * becomes published.
     *
     * @param mixed $post_id     Post ID.
     * @param mixed $post        Post object.
     * @param mixed $update      Whether this is an update.
     * @param mixed $post_before Post object before the update, or null for new posts.
     */
    public static function maybe_create_after_insert($post_id, $post = null, $update = false, $post_before = null): void {
        unset($update);

        $post = get_post($post instanceof WP_Post ? $post : $post_id);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            return;
        }
        if ($post_before instanceof WP_Post && $post_before->post_status === 'publish') {
            return;
        }
        self::maybe_create_on_publish($post);
    }

    public static function maybe_create_on_publish(WP_Post $post): void {
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

        /**
         * Filter whether kURL creates a short URL for a post that was just published.
         *
         * Return false, for example during large imports, to skip the blocking
         * YOURLS request. The Bulk Generator can create the links later.
         *
         * @param bool    $create Whether to create the short URL.
         * @param WP_Post $post   Published post.
         */
        if (!apply_filters('kurl_auto_create_on_publish', true, $post)) {
            return;
        }
        if (!Kurl_Lock::acquire_post((int) $post->ID)) {
            Kurl_Logger::log('warning', 'Auto-create on publish skipped: another kURL action is running for this post', ['post_id' => $post->ID]);
            return;
        }
        try {
            self::create_for_published_post($post);
        } finally {
            Kurl_Lock::release_post((int) $post->ID);
        }
    }

    private static function create_for_published_post(WP_Post $post): void {
        if (self::get_shorturl($post->ID) !== '') {
            return;
        }
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            Kurl_Logger::log('error', 'Auto-create on publish failed: missing permalink', ['post_id' => $post->ID]);
            return;
        }
        $keyword = self::get_keyword($post->ID);
        $response = Kurl_API::create_shortlink($permalink, $keyword, Kurl_Helpers::post_title_for_api($post));
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
        if (!self::save_link($post->ID, $shorturl, $keyword)) {
            Kurl_Logger::log('error', 'Auto-create on publish: YOURLS created the link, but WordPress could not store it', ['post_id' => $post->ID, 'shorturl' => $shorturl]);
            return;
        }
        delete_transient('kurl_dashboard_overview');
        Kurl_Logger::log(
            $keyword_adjusted ? 'warning' : 'info',
            $keyword_adjusted ? 'YOURLS adjusted the requested keyword during automatic creation' : 'Shortlink created automatically on publish',
            ['post_id' => $post->ID, 'shorturl' => $shorturl]
        );
    }

    /**
     * Store a short URL for a post.
     *
     * Returns whether the value is actually stored afterwards. update_post_meta()
     * returns false both for "unchanged" and for a failed write, so the stored
     * row is read back directly instead.
     */
    public static function save_link(int $post_id, string $shorturl, string $keyword = ''): bool {
        if ($post_id <= 0) {
            return false;
        }
        $shorturl = self::normalize_shorturl($shorturl);
        if ($shorturl === '') {
            return false;
        }

        // Read the previous effective link without priming the full meta cache
        // (this also runs inside bulk loops).
        $previous_values  = self::get_link_meta_for_posts([$post_id])[$post_id] ?? [];
        $previous_managed = self::normalize_shorturl(Kurl_Helpers::scalar_string($previous_values[KURL_META_URL] ?? ''));
        $previous         = $previous_managed !== '' ? $previous_managed : self::legacy_shorturl_from_values($previous_values);

        // 1. The URL itself. Nothing else changes unless it is demonstrably stored.
        if (Kurl_Helpers::scalar_string($previous_values[KURL_META_URL] ?? '') !== $shorturl) {
            update_post_meta($post_id, KURL_META_URL, $shorturl);
        }
        if (self::read_stored_value($post_id, KURL_META_URL) !== $shorturl) {
            return false;
        }

        // 2. Dependent fields, now that the post really carries the new link.
        $actual_keyword = Kurl_Helpers::keyword_from_shorturl($shorturl);
        $keyword = $actual_keyword !== '' ? $actual_keyword : Kurl_Helpers::sanitize_keyword($keyword);
        if ($keyword !== '') {
            update_post_meta($post_id, KURL_META_KEYWORD, $keyword);
        } else {
            delete_post_meta($post_id, KURL_META_KEYWORD);
        }
        if (self::read_stored_value($post_id, KURL_META_KEYWORD) !== $keyword) {
            // The keyword is derived from the URL; a stale one would be wrong,
            // a missing one is harmless.
            delete_post_meta($post_id, KURL_META_KEYWORD);
            Kurl_Logger::log('warning', 'Could not store the keyword of a short URL', ['post_id' => $post_id, 'shorturl' => $shorturl]);
        }

        // Click counts belong to one YOURLS row. After switching to another
        // short URL the cached count of the previous one would be misleading.
        if ($previous !== $shorturl && !empty($previous_values[KURL_META_STATS])) {
            delete_post_meta($post_id, KURL_META_STATS);
            if (self::read_stored_value($post_id, KURL_META_STATS) !== '') {
                Kurl_Logger::log('warning', 'Could not remove the click count of the previous short URL', ['post_id' => $post_id]);
            }
        }
        if (!empty($previous_values[KURL_META_NO_LEGACY])) {
            delete_post_meta($post_id, KURL_META_NO_LEGACY);
        }

        return true;
    }

    /**
     * Remove a post's link to one short URL.
     *
     * Deletes kURL's own fields. Legacy fields are deleted only when they are
     * released for deletion (kurl_legacy_cleanup_meta_keys) and contain exactly
     * the unlinked URL. Anything else (another URL, or a custom key that is
     * only registered for reading) is kept, but no longer used as a fallback,
     * so the unlinked post does not silently show a different link.
     *
     * @return int Number of legacy values kept.
     */
    public static function unlink_post(int $post_id, string $unlinked_url): int {
        if ($post_id <= 0) {
            return 0;
        }
        $unlinked_url = self::normalize_shorturl($unlinked_url);
        delete_post_meta($post_id, KURL_META_URL);
        delete_post_meta($post_id, KURL_META_KEYWORD);
        delete_post_meta($post_id, KURL_META_STATS);

        $deletable = Kurl_Helpers::legacy_cleanup_url_keys();
        $kept = 0;
        foreach (Kurl_Helpers::legacy_meta_keys() as $key) {
            foreach ((array) get_post_meta($post_id, $key, false) as $raw) {
                $value = self::normalize_shorturl(Kurl_Helpers::scalar_string($raw));
                if ($value === '') {
                    continue;
                }
                if ($unlinked_url !== '' && $value === $unlinked_url && in_array($key, $deletable, true)) {
                    delete_post_meta($post_id, $key, $raw);
                } else {
                    $kept++;
                }
            }
        }
        if ($kept > 0) {
            update_post_meta($post_id, KURL_META_NO_LEGACY, 1);
        } else {
            delete_post_meta($post_id, KURL_META_NO_LEGACY);
        }
        return $kept;
    }

    /**
     * First stored value of one meta key, read directly from the database.
     */
    private static function read_stored_value(int $post_id, string $meta_key): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies a write; the cache must not answer.
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $post_id,
            $meta_key
        ));
        return is_string($value) ? $value : '';
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

    /**
     * Number of other posts that store the same short URL (managed or legacy).
     *
     * Returns REFERENCES_UNKNOWN (-1) when a query fails. Callers must then
     * abort and change nothing: a positive count is reserved for references
     * that were actually found.
     */
    public static function count_other_references(string $shorturl, int $exclude_post_id): int {
        global $wpdb;

        $shorturl = self::normalize_shorturl($shorturl);
        if ($shorturl === '') {
            return 0;
        }

        $meta_keys        = self::url_meta_keys();
        $key_placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));

        // Count distinct posts so a post carrying both managed and legacy meta is
        // not counted twice. A shared remote link must not be edited or deleted
        // from one post because that would break every other local reference.
        // Preserve a binary comparison because YOURLS keywords can be case-sensitive.
        // CAST(... AS BINARY) replaces the BINARY operator, which MySQL 8.0.27+
        // deprecates; both forms behave identically on MySQL and MariaDB.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder-only IN clause are constructed locally; every value is supplied to prepare().
        $exact_sql = "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ({$key_placeholders}) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.PreparedSQL.NotPrepared -- Targeted integrity check for plugin-owned URL meta.
        $exact_count = (int) $wpdb->get_var($wpdb->prepare($exact_sql, ...array_merge([max(0, $exclude_post_id)], $meta_keys, [$shorturl])));
        if ($wpdb->last_error !== '') {
            return self::references_unknown($shorturl, $wpdb->last_error);
        }
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

        $like_sql = implode(' OR ', array_fill(0, count($patterns), 'CAST(meta_value AS BINARY) LIKE CAST(%s AS BINARY)'));
        $query_args = array_merge([max(0, $exclude_post_id)], $meta_keys, $patterns);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder-only IN/LIKE clauses are constructed locally; every value is supplied to prepare().
        $sql = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ({$key_placeholders}) AND ({$like_sql})";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rare mutation-time integrity fallback for equivalent stored URL spellings.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$query_args));
        if ($wpdb->last_error !== '') {
            return self::references_unknown($shorturl, $wpdb->last_error);
        }

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

    /**
     * Copy legacy short URLs into kURL's own meta.
     *
     * Works through the affected posts in blocks of 500 and reads only the
     * relevant meta rows, so memory stays flat on large sites (get_post_meta()
     * would cache every meta row of every post, including large page-builder
     * data). save_link() reads the stored value back, so a write that
     * WordPress rejected is counted as failed, never as imported.
     *
     * @return array{imported:int,skipped:int,conflicts:int,failed:int}
     */
    private static function references_unknown(string $shorturl, string $error): int {
        Kurl_Logger::log('error', 'Could not check other references of a short URL; the action was stopped', ['shorturl' => $shorturl, 'error' => $error]);
        return self::REFERENCES_UNKNOWN;
    }

    public static function import_legacy(): array {
        $report = ['imported' => 0, 'skipped' => 0, 'conflicts' => 0, 'failed' => 0, 'error' => false];
        $legacy_keys = Kurl_Helpers::legacy_meta_keys();
        if (empty($legacy_keys)) {
            return $report;
        }

        $last_id = 0;
        do {
            $post_ids = self::post_ids_with_meta_batch($legacy_keys, $last_id, self::MIGRATION_BATCH);
            if ($post_ids === null) {
                $report['error'] = true;
                break;
            }
            if (empty($post_ids)) {
                break;
            }
            $last_id = max($post_ids);
            $batch_meta = self::read_link_meta($post_ids);
            if ($batch_meta === null) {
                $report['error'] = true;
                break;
            }

            foreach ($post_ids as $post_id) {
                $values  = $batch_meta[$post_id] ?? [];
                $managed = self::normalize_shorturl(Kurl_Helpers::scalar_string($values[KURL_META_URL] ?? ''));
                $legacy  = self::legacy_urls_from_values($values);
                if ($managed !== '' || empty($legacy)) {
                    $report['skipped']++;
                    continue;
                }
                // Several plugins left different links: import the one of the
                // highest-priority plugin, keep the others for review.
                if (count(array_unique($legacy)) > 1) {
                    $report['conflicts']++;
                }
                $url = reset($legacy);
                if (self::save_link($post_id, $url)) {
                    $report['imported']++;
                } else {
                    $report['failed']++;
                    Kurl_Logger::log('error', 'Legacy import could not store the short URL', ['post_id' => $post_id, 'shorturl' => $url]);
                }
            }
        } while (count($post_ids) === self::MIGRATION_BATCH);

        Kurl_Logger::log('info', 'Legacy import finished', $report);
        return $report;
    }

    /**
     * Number of posts per legacy meta key that still carry a non-empty value.
     *
     * @return array<string,int>
     */
    public static function count_legacy_by_key(): array {
        global $wpdb;

        $legacy_keys = Kurl_Helpers::legacy_meta_keys();
        $counts = array_fill_keys($legacy_keys, 0);
        if (empty($legacy_keys)) {
            return $counts;
        }

        $placeholders = implode(', ', array_fill(0, count($legacy_keys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder-only IN clause are constructed locally; every value is supplied to prepare().
        $sql = "SELECT meta_key, COUNT(DISTINCT post_id) AS total FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> '' GROUP BY meta_key";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.PreparedSQL.NotPrepared -- Settings-page summary over a few meta keys.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$legacy_keys));
        foreach ((array) $rows as $row) {
            $key = isset($row->meta_key) ? (string) $row->meta_key : '';
            if (isset($counts[$key])) {
                $counts[$key] = isset($row->total) ? (int) $row->total : 0;
            }
        }
        return $counts;
    }

    /**
     * Remove legacy plugin data only where kURL demonstrably holds the same link.
     *
     * For every post with a valid kURL short URL, a legacy URL row is deleted
     * only when its value is that very URL. Rows with a different URL, and all
     * rows of posts without a kURL link, are kept: they may be the last local
     * record of a link. YOURLS Link Creator's keyword and click-count rows are
     * removed only once no _yourls_url row of that post remains.
     *
     * @return array{deleted:int,kept:int} Deleted meta rows and posts whose legacy data was kept.
     */
    public static function delete_legacy(): array {
        global $wpdb;

        $report   = ['deleted' => 0, 'kept' => 0, 'error' => false];
        $url_keys = Kurl_Helpers::legacy_cleanup_url_keys();
        $aux_keys = Kurl_Helpers::legacy_aux_meta_keys();
        $all_keys = array_values(array_unique(array_merge($url_keys, $aux_keys)));
        if (empty($all_keys)) {
            return $report;
        }

        $last_id = 0;
        do {
            $post_ids = self::post_ids_with_meta_batch($all_keys, $last_id, self::MIGRATION_BATCH);
            if ($post_ids === null) {
                $report['error'] = true;
                break;
            }
            if (empty($post_ids)) {
                break;
            }
            $last_id = max($post_ids);

            $keys             = array_merge([KURL_META_URL, KURL_META_NO_LEGACY], $all_keys);
            $id_placeholders  = implode(', ', array_fill(0, count($post_ids), '%d'));
            $key_placeholders = implode(', ', array_fill(0, count($keys), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder-only IN clauses are constructed locally; every value is supplied to prepare().
            $sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders}) ORDER BY meta_id ASC";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.PreparedSQL.NotPrepared -- Explicit one-time migration cleanup.
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($post_ids, $keys)));
            if ($wpdb->last_error !== '') {
                Kurl_Logger::log('error', 'Legacy cleanup query failed', ['error' => $wpdb->last_error]);
                $report['error'] = true;
                break;
            }

            $by_post = [];
            foreach ((array) $rows as $row) {
                $by_post[(int) $row->post_id][] = $row;
            }

            foreach ($by_post as $post_rows) {
                $managed = '';
                foreach ($post_rows as $row) {
                    if ($row->meta_key === KURL_META_URL) {
                        $managed = self::normalize_shorturl(Kurl_Helpers::scalar_string(maybe_unserialize($row->meta_value)));
                        break;
                    }
                }

                $delete = [];
                $kept_url_rows = 0;
                $ylc_url_left  = false;
                foreach ($post_rows as $row) {
                    if (!in_array($row->meta_key, $url_keys, true)) {
                        continue;
                    }
                    $value = self::normalize_shorturl(Kurl_Helpers::scalar_string(maybe_unserialize($row->meta_value)));
                    if ($managed !== '' && $value === $managed) {
                        $delete[] = (int) $row->meta_id;
                    } else {
                        $kept_url_rows++;
                        if ($row->meta_key === KURL_OLD_META_URL) {
                            $ylc_url_left = true;
                        }
                    }
                }
                if ($managed !== '' && !$ylc_url_left) {
                    foreach ($post_rows as $row) {
                        if (in_array($row->meta_key, $aux_keys, true)) {
                            $delete[] = (int) $row->meta_id;
                        }
                    }
                }
                if ($kept_url_rows > 0) {
                    $report['kept']++;
                }
                foreach ($delete as $meta_id) {
                    // Metadata API: fires the delete hooks and clears the
                    // post-meta cache, including persistent object caches.
                    if (delete_metadata_by_mid('post', $meta_id)) {
                        $report['deleted']++;
                    }
                }
            }
        } while (count($post_ids) === self::MIGRATION_BATCH);

        // Better YOURLS settings: only after a run without errors and an
        // explicitly successful check that none of its links remain.
        if (!$report['error']) {
            $remaining = self::post_ids_with_meta_batch(['_better_yourls_short_link'], 0, 1);
            if ($remaining === []) {
                delete_option(KURL_OLD_OPTION);
            } elseif ($remaining === null) {
                $report['error'] = true;
            }
        }

        Kurl_Logger::log('info', 'Legacy cleanup finished', $report);
        return $report;
    }

    /**
     * Next block of post IDs that carry at least one of the given meta keys.
     *
     * @param string[] $meta_keys Meta keys.
     * @return int[]|null Null when the query failed (distinct from "none found").
     */
    private static function post_ids_with_meta_batch(array $meta_keys, int $after_id, int $limit): ?array {
        global $wpdb;
        if (empty($meta_keys)) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder-only IN clause are constructed locally; every value is supplied to prepare().
        $sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND post_id > %d ORDER BY post_id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.PreparedSQL.NotPrepared -- Cursor over plugin-relevant meta rows.
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...array_merge($meta_keys, [$after_id, $limit])));
        if ($wpdb->last_error !== '') {
            Kurl_Logger::log('error', 'Migration query failed', ['error' => $wpdb->last_error]);
            return null;
        }
        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    /**
     * Valid legacy URLs of one post, keyed by meta key in priority order.
     *
     * @param array<string,mixed> $values Meta values of one post.
     * @return array<string,string>
     */
    private static function legacy_urls_from_values(array $values): array {
        $urls = [];
        if (!empty($values[KURL_META_NO_LEGACY])) {
            return $urls;
        }
        foreach (Kurl_Helpers::legacy_meta_keys() as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $url = self::normalize_shorturl(Kurl_Helpers::scalar_string($values[$key]));
            if ($url !== '') {
                $urls[$key] = $url;
            }
        }
        return $urls;
    }

    /**
     * Read kURL and legacy link meta for a batch of posts with one query.
     *
     * Returns the first stored value per key (the same value get_post_meta()
     * returns with $single = true) without loading unrelated meta rows.
     *
     * @param int[] $post_ids Post IDs.
     * @return array<int,array<string,mixed>>
     */
    public static function get_link_meta_for_posts(array $post_ids): array {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
        if (empty($ids)) {
            return [];
        }
        $keys = array_values(array_unique(array_merge([KURL_META_URL, KURL_META_KEYWORD, KURL_META_STATS, KURL_META_NO_LEGACY], Kurl_Helpers::legacy_meta_keys())));

        $id_placeholders  = implode(', ', array_fill(0, count($ids), '%d'));
        $key_placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder-only IN clauses are constructed locally; every value is supplied to prepare().
        $sql = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders}) ORDER BY meta_id ASC";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.PreparedSQL.NotPrepared -- Batched maintenance read of plugin-owned meta.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($ids, $keys)));

        $meta = [];
        foreach ((array) $rows as $row) {
            $post_id = isset($row->post_id) ? (int) $row->post_id : 0;
            $key     = isset($row->meta_key) ? (string) $row->meta_key : '';
            if ($post_id <= 0 || $key === '' || isset($meta[$post_id][$key])) {
                continue;
            }
            $meta[$post_id][$key] = isset($row->meta_value) ? maybe_unserialize($row->meta_value) : '';
        }
        return $meta;
    }

    /**
     * get_link_meta_for_posts() that reports a failed query as null instead of
     * an empty result, for code that must not act on missing data.
     *
     * @param int[] $post_ids Post IDs.
     * @return array<int,array<string,mixed>>|null
     */
    private static function read_link_meta(array $post_ids): ?array {
        global $wpdb;
        $meta = self::get_link_meta_for_posts($post_ids);
        if ($wpdb->last_error !== '') {
            Kurl_Logger::log('error', 'Reading link meta failed', ['error' => $wpdb->last_error]);
            return null;
        }
        return $meta;
    }

    /**
     * First valid legacy URL from a get_link_meta_for_posts() row.
     *
     * @param array<string,mixed> $values Meta values of one post.
     */
    public static function legacy_shorturl_from_values(array $values): string {
        if (!empty($values[KURL_META_NO_LEGACY])) {
            return '';
        }
        foreach (Kurl_Helpers::legacy_meta_keys() as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $url = self::normalize_shorturl(Kurl_Helpers::scalar_string($values[$key]));
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /**
     * Managed key followed by all legacy URL keys.
     *
     * @return string[]
     */
    public static function url_meta_keys(): array {
        return array_values(array_unique(array_merge([KURL_META_URL], Kurl_Helpers::legacy_meta_keys())));
    }

    public static function get_keyword(int $post_id): string {
        return $post_id > 0 ? Kurl_Helpers::sanitize_keyword(Kurl_Helpers::scalar_string(get_post_meta($post_id, KURL_META_KEYWORD, true))) : '';
    }

    /**
     * Kept as a public wrapper for compatibility; uses the same extractor as
     * every other code path, including the link.shorturl response form.
     */
    public static function extract_shorturl_from_response(array $response): string {
        return self::normalize_shorturl(Kurl_API::extract_shorturl($response));
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

    /**
     * First valid short URL stored by a legacy YOURLS plugin.
     * All keys come from the same, already primed post-meta cache entry.
     */
    public static function get_legacy_shorturl(int $post_id): string {
        if ($post_id <= 0 || get_post_meta($post_id, KURL_META_NO_LEGACY, true)) {
            return '';
        }
        foreach (Kurl_Helpers::legacy_meta_keys() as $key) {
            $url = self::normalize_shorturl(Kurl_Helpers::scalar_string(get_post_meta($post_id, $key, true)));
            if ($url !== '') {
                return $url;
            }
        }
        return '';
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
