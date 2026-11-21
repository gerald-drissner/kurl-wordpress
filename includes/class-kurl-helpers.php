<?php

defined('ABSPATH') || exit;

final class Kurl_Helpers {

    public const FRONTEND_OPTION = 'kurl_replace_wp_shortlink';

    /**
     * The helper verdict lives in its own option, bound to a fingerprint of the
     * connection it was obtained with. A slow connection test or status check
     * therefore never writes kurl_settings, and cannot restore an API URL,
     * signature, or any other setting that another request saved meanwhile.
     */
    public const HELPER_STATUS_OPTION = 'kurl_helper_status';

    private const HELPER_FIELDS = ['api_extended', 'helper_version', 'helper_capabilities', 'helper_checked_at', 'helper_check_error'];

    /**
     * Normalized settings per site. Keyed by blog ID so code that calls
     * switch_to_blog() never receives the API URL or signature of another site.
     *
     * @var array<int,array>
     */
    private static array $cached_settings = [];

    public static function get_settings(): array {
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        if (isset(self::$cached_settings[$blog_id])) {
            return self::$cached_settings[$blog_id];
        }

        $settings = get_option('kurl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        // Until 1.0.9 an empty selection was replaced by post and page. Settings
        // saved by 1.1.0 or later carry post_types_explicit, so an intentionally
        // empty selection now stays empty. Older stored values keep their
        // previous effective behavior.
        $post_types_explicit = !empty($settings['post_types_explicit']);

        $settings = wp_parse_args($settings, self::defaults());
        $settings['api_url']                = self::normalize_api_url(self::scalar_string($settings['api_url']));
        $settings['signature']              = sanitize_text_field(self::scalar_string($settings['signature']));
        $enabled_post_types = isset($settings['enabled_post_types']) && is_array($settings['enabled_post_types'])
            ? $settings['enabled_post_types']
            : [];
        $helper_capabilities = isset($settings['helper_capabilities']) && is_array($settings['helper_capabilities'])
            ? $settings['helper_capabilities']
            : [];
        $settings['enabled_post_types']     = self::normalize_post_types($enabled_post_types, $post_types_explicit);
        $settings['post_types_explicit']    = $post_types_explicit ? 1 : 0;
        $settings['cache_minutes']          = max(1, self::scalar_int($settings['cache_minutes'] ?? 30, 30));
        $settings['request_timeout']        = max(5, min(120, self::scalar_int($settings['request_timeout'] ?? 15, 15)));
        $settings['auto_create_on_publish'] = self::scalar_int($settings['auto_create_on_publish'] ?? 0) === 1 ? 1 : 0;
        $settings['api_extended']           = self::scalar_int($settings['api_extended'] ?? 0) === 1 ? 1 : 0;
        $settings['helper_version']         = sanitize_text_field(self::scalar_string($settings['helper_version'] ?? ''));
        $settings['helper_capabilities']    = self::sanitize_key_list($helper_capabilities);
        $settings['helper_checked_at']      = max(0, self::scalar_int($settings['helper_checked_at'] ?? 0));
        $settings['helper_check_error']     = sanitize_text_field(self::scalar_string($settings['helper_check_error'] ?? ''));

        // Helper fields stored inside kurl_settings by 1.0.9 are ignored; only a
        // verdict bound to exactly this connection counts.
        $settings = array_merge($settings, self::helper_status_for($settings['api_url'], $settings['signature']));

        self::$cached_settings[$blog_id] = $settings;

        return $settings;
    }

    public static function flush_settings_cache(): void {
        self::$cached_settings = [];
    }

    public static function connection_fingerprint(string $api_url, string $signature): string {
        return ($api_url === '' || $signature === '') ? '' : hash('sha256', $api_url . "\n" . $signature);
    }

    /**
     * Stored helper verdict for one connection, or an empty verdict.
     *
     * @return array<string,mixed>
     */
    public static function helper_status_for(string $api_url, string $signature): array {
        $empty = [
            'api_extended'        => 0,
            'helper_version'      => '',
            'helper_capabilities' => [],
            'helper_checked_at'   => 0,
            'helper_check_error'  => '',
        ];
        $fingerprint = self::connection_fingerprint($api_url, $signature);
        $stored = get_option(self::HELPER_STATUS_OPTION, []);
        if ($fingerprint === '' || !is_array($stored) || !hash_equals($fingerprint, self::scalar_string($stored['fingerprint'] ?? ''))) {
            return $empty;
        }
        return [
            'api_extended'        => self::scalar_int($stored['api_extended'] ?? 0) === 1 ? 1 : 0,
            'helper_version'      => sanitize_text_field(self::scalar_string($stored['helper_version'] ?? '')),
            'helper_capabilities' => self::sanitize_key_list(is_array($stored['helper_capabilities'] ?? null) ? $stored['helper_capabilities'] : []),
            'helper_checked_at'   => max(0, self::scalar_int($stored['helper_checked_at'] ?? 0)),
            'helper_check_error'  => sanitize_text_field(self::scalar_string($stored['helper_check_error'] ?? '')),
        ];
    }

    /**
     * Store a helper verdict for exactly the connection it was obtained with.
     * Never writes kurl_settings.
     *
     * @param array<string,mixed> $status Helper fields.
     */
    public static function save_helper_status(string $api_url, string $signature, array $status): void {
        $fingerprint = self::connection_fingerprint($api_url, $signature);
        if ($fingerprint === '') {
            return;
        }
        update_option(self::HELPER_STATUS_OPTION, [
            'fingerprint'         => $fingerprint,
            'api_extended'        => !empty($status['api_extended']) ? 1 : 0,
            'helper_version'      => sanitize_text_field(self::scalar_string($status['helper_version'] ?? '')),
            'helper_capabilities' => self::sanitize_key_list(is_array($status['helper_capabilities'] ?? null) ? $status['helper_capabilities'] : []),
            'helper_checked_at'   => max(0, self::scalar_int($status['helper_checked_at'] ?? 0)),
            'helper_check_error'  => sanitize_text_field(self::scalar_string($status['helper_check_error'] ?? '')),
        ], false);
        self::flush_settings_cache();
    }

    public static function clear_helper_status(): void {
        delete_option(self::HELPER_STATUS_OPTION);
        self::flush_settings_cache();
    }

    /**
     * Write kurl_settings without the helper fields, which have their own option.
     *
     * @param array<string,mixed> $settings Settings.
     */
    public static function update_settings(array $settings): void {
        foreach (self::HELPER_FIELDS as $field) {
            unset($settings[$field]);
        }
        update_option('kurl_settings', $settings, false);
        self::flush_settings_cache();
    }

    public const SHORTLINK_OFF      = 0;
    public const SHORTLINK_DEFER    = 1;
    public const SHORTLINK_OVERRIDE = 2;

    /**
     * How kURL treats the WordPress shortlink (wp_get_shortlink(), the
     * rel="shortlink" tag, the Link header, and the admin bar):
     *
     * - 0: keep the WordPress shortlink.
     * - 1: use the kURL short URL unless an earlier pre_get_shortlink callback
     *      already returned a value, including a deliberately empty string
     *      (default; follows the short-circuit contract of the filter).
     * - 2: always use the kURL short URL, also over other shortlink plugins.
     *
     * Read on every call, so the value of the current site applies after
     * switch_to_blog(). The option is autoloaded and costs no query.
     */
    public static function shortlink_mode(): int {
        $mode = self::scalar_int(get_option(self::FRONTEND_OPTION, self::SHORTLINK_DEFER), self::SHORTLINK_DEFER);
        if (!in_array($mode, [self::SHORTLINK_OFF, self::SHORTLINK_DEFER, self::SHORTLINK_OVERRIDE], true)) {
            $mode = self::SHORTLINK_DEFER;
        }

        /**
         * Filter whether kURL replaces the WordPress shortlink at all.
         *
         * @param bool $enabled Value derived from the "WordPress shortlink" setting.
         */
        if (!apply_filters('kurl_replace_wp_shortlink', $mode !== self::SHORTLINK_OFF)) {
            return self::SHORTLINK_OFF;
        }

        /**
         * Filter whether kURL overrides a shortlink that an earlier
         * pre_get_shortlink callback (another plugin) already provided.
         *
         * @param bool $override Value derived from the "WordPress shortlink" setting.
         */
        return apply_filters('kurl_override_existing_shortlink', $mode === self::SHORTLINK_OVERRIDE)
            ? self::SHORTLINK_OVERRIDE
            : self::SHORTLINK_DEFER;
    }

    public static function replace_wp_shortlink(): bool {
        return self::shortlink_mode() !== self::SHORTLINK_OFF;
    }

    /**
     * Create the autoloaded front-end option for installations that were
     * updated without running the activation hook.
     */
    public static function ensure_frontend_option(): void {
        if (get_option(self::FRONTEND_OPTION, null) === null) {
            add_option(self::FRONTEND_OPTION, self::SHORTLINK_DEFER, '', true);
        }
    }

    /**
     * Post-meta keys written by older YOURLS plugins, in priority order.
     *
     * - _yourls_url:               YOURLS Link Creator (Andrew Norcross)
     * - _better_yourls_short_link: Better YOURLS (Chris Wiegman / Aaron Eaton)
     * - yourls_shorturl:           YOURLS: WordPress to Twitter (Ozh)
     *
     * @return string[]
     */
    public static function legacy_meta_keys(): array {
        /**
         * Filter the post-meta keys that kURL reads as legacy short URLs.
         *
         * @param string[] $keys Meta keys in priority order.
         */
        $keys = apply_filters('kurl_legacy_meta_keys', [KURL_OLD_META_URL, '_better_yourls_short_link', 'yourls_shorturl']);

        $own_keys = [KURL_META_URL, KURL_META_KEYWORD, KURL_META_STATS];
        $clean = [];
        foreach ((array) $keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $key = trim($key);
            if ($key === '' || strlen($key) > 255 || in_array($key, $own_keys, true)) {
                continue;
            }
            $clean[] = $key;
        }
        return array_values(array_unique($clean));
    }

    /**
     * Legacy URL keys the cleanup tool may delete. Only the keys of the three
     * known plugins by default: a custom key added to kurl_legacy_meta_keys
     * for reading is not deleted unless it is also added here.
     *
     * @return string[]
     */
    public static function legacy_cleanup_url_keys(): array {
        /**
         * Filter the legacy URL meta keys that "Delete legacy plugin data" may remove.
         * Only keys that kURL also reads (kurl_legacy_meta_keys) are used.
         *
         * @param string[] $keys Meta keys.
         */
        $allowed = apply_filters('kurl_legacy_cleanup_meta_keys', [KURL_OLD_META_URL, '_better_yourls_short_link', 'yourls_shorturl']);
        $allowed = array_map('strval', array_filter((array) $allowed, 'is_string'));
        return array_values(array_intersect(self::legacy_meta_keys(), $allowed));
    }

    /**
     * YOURLS Link Creator's keyword and click-count keys.
     *
     * @return string[]
     */
    public static function legacy_aux_meta_keys(): array {
        return ['_yourls_keyword', '_yourls_clicks'];
    }

    /**
     * Plain-text post title for YOURLS. get_the_title() returns HTML entities
     * (for example &#8211; or &#038;) and a "Protected:" prefix, which YOURLS
     * would otherwise store literally.
     *
     * @param int|WP_Post $post Post ID or object.
     */
    public static function post_title_for_api($post): string {
        $post = get_post($post);
        if (!$post instanceof WP_Post) {
            return '';
        }
        $title = wp_strip_all_tags((string) $post->post_title);
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', $title);
        return is_string($title) ? trim($title) : '';
    }

    /**
     * Convert an untrusted request value to an unslashed scalar string.
     * Arrays and objects are rejected instead of being cast to the literal
     * string "Array" or triggering PHP warnings.
     *
     * @param mixed $value Request value.
     */
    public static function request_string($value): string {
        return wp_unslash(self::scalar_string($value));
    }

    /**
     * Convert a mixed value to a string without PHP's array/object conversion
     * warnings. This is also used defensively for stored options and API data.
     *
     * @param mixed  $value   Value to convert.
     * @param string $default Fallback for non-scalar values.
     */
    public static function scalar_string($value, string $default = ''): string {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Convert a numeric scalar to an integer without accepting arrays/objects.
     *
     * @param mixed $value   Value to convert.
     * @param int   $default Fallback for non-numeric values.
     */
    public static function scalar_int($value, int $default = 0): int {
        return is_scalar($value) && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Sanitize a list of scalar values as WordPress keys.
     */
    public static function sanitize_key_list(array $values): array {
        $clean = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $key = sanitize_key((string) $value);
            if ($key !== '') {
                $clean[] = $key;
            }
        }
        return array_values(array_unique($clean));
    }

    public static function enabled_post_types(): array {
        return self::get_settings()['enabled_post_types'];
    }

    public static function is_supported_post_type(string $post_type): bool {
        $post_type = sanitize_key($post_type);
        return $post_type !== '' && in_array($post_type, self::enabled_post_types(), true);
    }

    /**
     * Sanitize a complete HTTP(S) URL and reject incomplete or unsafe schemes.
     */
    public static function sanitize_http_url(string $url): string {
        $url = trim(wp_unslash($url));
        if ($url === '') {
            return '';
        }

        $url = esc_url_raw($url, ['http', 'https']);
        if (!is_string($url) || $url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        // esc_url_raw() prefixes scheme-less input with http:// and encodes
        // spaces, so arbitrary text such as "not a url" would otherwise become
        // "http://not%20a%20url". No resolvable host name contains "%" or
        // whitespace; treat those values as invalid.
        if (preg_match('~[%\s]~', (string) $parts['host'])) {
            return '';
        }

        return $url;
    }

    /**
     * Normalize a YOURLS base URL or API URL to the final yourls-api.php path.
     * Query strings, fragments, and user-info are intentionally discarded so
     * they cannot override authenticated POST parameters or expose credentials.
     */
    public static function normalize_api_url(string $raw_url): string {
        $raw_url = self::sanitize_http_url($raw_url);
        if ($raw_url === '') {
            return '';
        }

        $parts = wp_parse_url($raw_url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) $parts['host']);
        if (strpos($host, ':') !== false && substr($host, 0, 1) !== '[') {
            $host = '[' . $host . ']';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $path = '/' . ltrim(rtrim($path, '/'), '/');

        // A frequently pasted YOURLS admin URL points one directory below the
        // API endpoint. Bring it back to the installation root first.
        $path = preg_replace('~/admin(?:/[^/]+\.php)?/?$~i', '/', $path) ?: $path;

        if (!preg_match('~/yourls-api\.php$~i', $path)) {
            if (preg_match('~/[^/]+\.php$~i', $path)) {
                $path = preg_replace('~/[^/]+\.php$~i', '/yourls-api.php', $path) ?: $path;
            } else {
                $path = rtrim($path, '/') . '/yourls-api.php';
            }
        }

        $normalized = $scheme . '://' . $host . $port . $path;
        return self::sanitize_http_url($normalized);
    }

    public static function sanitize_keyword(string $keyword): string {
        $keyword = rawurldecode(trim(wp_unslash($keyword)));
        if ($keyword === '') {
            return '';
        }

        // Preserve case because YOURLS can use a base-62 charset. Remove only URL
        // delimiters, controls, and whitespace; YOURLS remains authoritative for
        // its configured keyword charset.
        $keyword = preg_replace('~[\x00-\x20\x7F/\\?#&=]+~u', '', $keyword);
        $keyword = is_string($keyword) ? $keyword : '';
        if (function_exists('mb_substr')) {
            return mb_substr($keyword, 0, 199);
        }
        return substr($keyword, 0, 199);
    }

    /**
     * Extract an existing URL's final path segment without silently changing it.
     * A decoded slash or other delimiter must fail instead of identifying a
     * different YOURLS row for a destructive operation.
     */
    public static function keyword_from_shorturl(string $shorturl): string {
        $shorturl = self::sanitize_http_url($shorturl);
        if ($shorturl === '') {
            return '';
        }

        $path = wp_parse_url($shorturl, PHP_URL_PATH);
        if (!is_string($path) || trim($path, '/') === '') {
            return '';
        }

        $segment = basename(rtrim($path, '/'));
        $decoded = rawurldecode($segment);
        if ($decoded === '' || preg_match('~[\x00-\x20\x7F/\\?#&=]~u', $decoded)) {
            return '';
        }

        $sanitized = self::sanitize_keyword($decoded);
        return $sanitized !== '' && hash_equals($decoded, $sanitized) ? $sanitized : '';
    }

    public static function format_api_error(array $response): string {
        if (!empty($response['message']) && is_string($response['message'])) {
            return sanitize_text_field($response['message']);
        }
        if (!empty($response['error']) && is_string($response['error'])) {
            return sanitize_text_field($response['error']);
        }
        if (isset($response['statusCode']) && is_scalar($response['statusCode']) && (string) $response['statusCode'] !== '') {
            /* translators: %s: Status code returned by YOURLS. */
            return sprintf(__('YOURLS status %s', 'kurl-short-url-manager-yourls'), sanitize_text_field((string) $response['statusCode']));
        }
        if (!empty($response['code']) && is_string($response['code'])) {
            /* translators: %s: Error code returned by YOURLS. */
            return sprintf(__('YOURLS error code: %s', 'kurl-short-url-manager-yourls'), sanitize_text_field($response['code']));
        }
        if (!empty($response['raw']) && is_array($response['raw'])) {
            if (!empty($response['raw']['message']) && is_string($response['raw']['message'])) {
                return sanitize_text_field($response['raw']['message']);
            }
            if (!empty($response['raw']['error']) && is_string($response['raw']['error'])) {
                return sanitize_text_field($response['raw']['error']);
            }
        }
        return __('Unknown YOURLS error.', 'kurl-short-url-manager-yourls');
    }

    private static function defaults(): array {
        return [
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
    }

    private static function normalize_post_types(array $post_types, bool $explicit = false): array {
        $post_types = self::sanitize_key_list($post_types);
        if (empty($post_types) && !$explicit) {
            $post_types = ['post', 'page'];
        }
        return $post_types;
    }

    /**
     * Replace every occurrence of known secrets and common credential patterns.
     * Used for log entries, API error messages, and stored response bodies.
     *
     * @param string   $text    Text to clean.
     * @param string[] $secrets Additional secrets, for example an unsaved signature under test.
     */
    public static function redact_secrets(string $text, array $secrets = []): string {
        if ($text === '') {
            return '';
        }
        $stored = get_option('kurl_settings', []);
        if (is_array($stored) && isset($stored['signature'])) {
            $secrets[] = self::scalar_string($stored['signature']);
        }
        foreach (array_unique($secrets) as $secret) {
            $secret = trim(self::scalar_string($secret));
            // Very short values would redact ordinary words.
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        $redacted = preg_replace(
            '~((?:signature|password|passwd|token|secret|authorization|api[_-]?key)\s*["\']?\s*[:=]\s*["\']?)[^&\s<>"\',}]+~i',
            '$1[redacted]',
            $text
        );
        return is_string($redacted) ? $redacted : $text;
    }

    /**
     * Seconds an AJAX batch may spend before it returns and lets the browser
     * request the next one. Keeps admin-ajax requests below common proxy and
     * FastCGI limits (Cloudflare closes requests after 100 seconds), because
     * PHP's max_execution_time does not count time spent waiting for HTTP.
     */
    public static function batch_time_budget(): float {
        /**
         * Filter the wall-clock budget of one bulk or reconciliation batch.
         *
         * @param float $seconds Budget in seconds. Default 20.
         */
        $seconds = (float) apply_filters('kurl_batch_time_budget', 20.0);
        return max(0.0, $seconds);
    }
}
