<?php

defined('ABSPATH') || exit;

final class Kurl_Helpers {

    private static ?array $cached_settings = null;

    public static function get_settings(): array {
        if (self::$cached_settings !== null) {
            return self::$cached_settings;
        }

        $settings = get_option('kurl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings = wp_parse_args($settings, self::defaults());
        $settings['api_url']                = self::normalize_api_url(self::scalar_string($settings['api_url']));
        $settings['signature']              = sanitize_text_field(self::scalar_string($settings['signature']));
        $enabled_post_types = isset($settings['enabled_post_types']) && is_array($settings['enabled_post_types'])
            ? $settings['enabled_post_types']
            : [];
        $helper_capabilities = isset($settings['helper_capabilities']) && is_array($settings['helper_capabilities'])
            ? $settings['helper_capabilities']
            : [];
        $settings['enabled_post_types']     = self::normalize_post_types($enabled_post_types);
        $settings['cache_minutes']          = max(1, self::scalar_int($settings['cache_minutes'] ?? 30, 30));
        $settings['request_timeout']        = max(5, min(120, self::scalar_int($settings['request_timeout'] ?? 15, 15)));
        $settings['auto_create_on_publish'] = self::scalar_int($settings['auto_create_on_publish'] ?? 0) === 1 ? 1 : 0;
        $settings['api_extended']           = self::scalar_int($settings['api_extended'] ?? 0) === 1 ? 1 : 0;
        $settings['helper_version']         = sanitize_text_field(self::scalar_string($settings['helper_version'] ?? ''));
        $settings['helper_capabilities']    = self::sanitize_key_list($helper_capabilities);
        $settings['helper_checked_at']      = max(0, self::scalar_int($settings['helper_checked_at'] ?? 0));
        $settings['helper_check_error']     = sanitize_text_field(self::scalar_string($settings['helper_check_error'] ?? ''));

        self::$cached_settings = $settings;

        return $settings;
    }

    public static function flush_settings_cache(): void {
        self::$cached_settings = null;
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

    private static function normalize_post_types(array $post_types): array {
        $post_types = self::sanitize_key_list($post_types);
        if (empty($post_types)) {
            $post_types = ['post', 'page'];
        }
        return $post_types;
    }
}
