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
        $settings['api_url']                = esc_url_raw(trim((string) $settings['api_url']));
        $settings['signature']              = sanitize_text_field((string) $settings['signature']);
        $settings['enabled_post_types']     = self::normalize_post_types((array) $settings['enabled_post_types']);
        $settings['cache_minutes']          = max(1, (int) $settings['cache_minutes']);
        $settings['request_timeout']        = max(5, (int) $settings['request_timeout']);
        $settings['auto_create_on_publish'] = !empty($settings['auto_create_on_publish']) ? 1 : 0;
        $settings['api_extended']           = !empty($settings['api_extended']) ? 1 : 0;

        self::$cached_settings = $settings;

        return $settings;
    }

    public static function flush_settings_cache(): void {
        self::$cached_settings = null;
    }

    public static function enabled_post_types(): array {
        return self::get_settings()['enabled_post_types'];
    }

    public static function is_supported_post_type(string $post_type): bool {
        $post_type = sanitize_key($post_type);
        return $post_type !== '' && in_array($post_type, self::enabled_post_types(), true);
    }

    public static function sanitize_keyword(string $keyword): string {
        $keyword = trim(wp_unslash($keyword));
        if ($keyword === '') {
            return '';
        }
        $keyword = preg_replace('~[^A-Za-z0-9\-_]~', '', $keyword);
        return strtolower((string) $keyword);
    }

    public static function format_api_error(array $response): string {
        if (!empty($response['message']) && is_string($response['message'])) {
            return sanitize_text_field($response['message']);
        }
        if (!empty($response['error']) && is_string($response['error'])) {
            return sanitize_text_field($response['error']);
        }
        if (!empty($response['statusCode'])) {
            /* translators: %s: Status code returned by YOURLS. */
            return sprintf(__('YOURLS status %s', 'kurl-yourls'), sanitize_text_field((string) $response['statusCode']));
        }
        if (!empty($response['code']) && is_string($response['code'])) {
            /* translators: %s: Error code returned by YOURLS. */
            return sprintf(__('YOURLS error code: %s', 'kurl-yourls'), sanitize_text_field($response['code']));
        }
        if (!empty($response['raw']) && is_array($response['raw'])) {
            if (!empty($response['raw']['message']) && is_string($response['raw']['message'])) {
                return sanitize_text_field($response['raw']['message']);
            }
            if (!empty($response['raw']['error']) && is_string($response['raw']['error'])) {
                return sanitize_text_field($response['raw']['error']);
            }
        }
        return __('Unknown YOURLS error.', 'kurl-yourls');
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
        ];
    }

    private static function normalize_post_types(array $post_types): array {
        $post_types = array_values(array_unique(array_filter(array_map('sanitize_key', $post_types))));
        if (empty($post_types)) {
            $post_types = ['post', 'page'];
        }
        return $post_types;
    }
}
