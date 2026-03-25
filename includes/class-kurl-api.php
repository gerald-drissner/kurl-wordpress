<?php

defined('ABSPATH') || exit;

final class Kurl_API {

    public static function configured(): bool {
        $settings = Kurl_Helpers::get_settings();
        return !empty($settings['api_url']) && !empty($settings['signature']);
    }

    public static function request(string $action, array $params = []): array {
        $settings = Kurl_Helpers::get_settings();
        if (empty($settings['api_url']) || empty($settings['signature'])) {
            return ['ok' => false, 'message' => __('Missing YOURLS API settings.', 'kurl-yourls')];
        }

        $action = sanitize_key($action);
        if ($action === '') {
            return ['ok' => false, 'message' => __('Invalid API action.', 'kurl-yourls')];
        }

        $body = array_merge([
            'signature' => (string) $settings['signature'],
            'action'    => $action,
            'format'    => 'json',
        ], self::sanitize_request_params($params));

        $timeout = max(5, (int) ($settings['request_timeout'] ?? 15));
        $response = wp_remote_post((string) $settings['api_url'], [
            'timeout'     => $timeout,
            'redirection' => 3,
            'user-agent'  => 'kURL/' . KURL_VERSION . '; ' . home_url('/'),
            'sslverify'   => true,
            'body'        => $body,
        ]);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            Kurl_Logger::log('error', 'API request failed', ['action' => $action, 'message' => $message]);
            return ['ok' => false, 'message' => $message];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code < 200 || $http_code >= 300) {
            return self::handle_http_error($action, $http_code, $body, $data);
        }

        if (!is_array($data)) {
            Kurl_Logger::log('error', 'Invalid JSON from API', ['action' => $action, 'body' => self::truncate_for_log($body)]);
            return ['ok' => false, 'message' => __('Invalid JSON response from YOURLS.', 'kurl-yourls')];
        }

        if (self::response_indicates_success($data)) {
            $data['ok'] = true;
            return $data;
        }

        $message = Kurl_Helpers::format_api_error($data);
        Kurl_Logger::log('error', 'YOURLS returned an error', ['action' => $action, 'message' => $message, 'body' => self::truncate_for_log($body)]);
        return ['ok' => false, 'message' => $message, 'raw' => $data];
    }

    public static function create_shortlink(string $url, string $keyword = '', string $title = ''): array {
        $url = esc_url_raw(trim($url));
        if ($url === '') {
            return ['ok' => false, 'message' => __('Missing or invalid target URL.', 'kurl-yourls')];
        }
        $params = ['url' => $url];
        $keyword = Kurl_Helpers::sanitize_keyword($keyword);
        if ($keyword !== '') {
            $params['keyword'] = $keyword;
        }
        $title = sanitize_text_field($title);
        if ($title !== '') {
            $params['title'] = $title;
        }
        return self::request('shorturl', $params);
    }

    public static function aggregate_stats(): array {
        return self::request('stats', ['filter' => 'top', 'limit' => 10]);
    }


    public static function db_stats(): array {
        return self::request('db-stats');
    }

    public static function stats_list(string $filter = 'top', int $limit = 10): array {
        $filter = strtolower(trim($filter));
        if (!in_array($filter, ['top', 'bottom', 'rand', 'last'], true)) {
            $filter = 'top';
        }
        $limit = max(1, min(50, $limit));
        return self::request('stats', ['filter' => $filter, 'limit' => $limit]);
    }

    public static function url_stats(string $shorturl): array {
        $shorturl = esc_url_raw(trim($shorturl));
        if ($shorturl === '') {
            return ['ok' => false, 'message' => __('Missing short URL.', 'kurl-yourls')];
        }
        return self::request('url-stats', ['shorturl' => $shorturl]);
    }

    public static function expand_shortlink(string $shorturl): array {
        $shorturl = esc_url_raw(trim($shorturl));
        if ($shorturl === '') {
            return ['ok' => false, 'message' => __('Missing short URL.', 'kurl-yourls')];
        }
        return self::request('expand', ['shorturl' => $shorturl]);
    }

    public static function check_extended_api(): bool {
        if (!self::configured()) {
            return false;
        }
        $response = self::request('kurl_ping');
        return !empty($response['ok']) && !empty($response['kurl_extended']);
    }

    public static function delete_shortlink(string $shorturl): array {
        $shorturl = esc_url_raw(trim($shorturl));
        if ($shorturl === '') {
            return ['ok' => false, 'message' => __('Missing short URL.', 'kurl-yourls')];
        }
        return self::request('kurl_delete', ['shorturl' => $shorturl]);
    }

    public static function find_by_longurl(string $url): array {
        $url = esc_url_raw(trim($url));
        if ($url === '') {
            return ['ok' => false, 'message' => __('Missing or invalid target URL.', 'kurl-yourls')];
        }
        return self::request('kurl_find_by_url', ['url' => $url]);
    }

    public static function extract_shorturl(array $response): string {
        if (!empty($response['shorturl']) && is_string($response['shorturl'])) {
            return trim(esc_url_raw($response['shorturl']));
        }
        if (!empty($response['url']['shorturl']) && is_string($response['url']['shorturl'])) {
            return trim(esc_url_raw($response['url']['shorturl']));
        }
        if (!empty($response['link']['shorturl']) && is_string($response['link']['shorturl'])) {
            return trim(esc_url_raw($response['link']['shorturl']));
        }
        return '';
    }

    public static function extract_longurl(array $response): string {
        foreach ([['longurl'], ['url', 'url'], ['url'], ['link', 'url'], ['link', 'longurl']] as $path) {
            $value = $response;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_string($value) && $value !== '') {
                return trim(esc_url_raw($value));
            }
        }
        return '';
    }

    private static function sanitize_request_params(array $params): array {
        $clean = [];
        foreach ($params as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            if (is_bool($value)) {
                $clean[$key] = $value ? '1' : '0';
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $clean[$key] = (string) $value;
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = (string) $value;
            }
        }
        return $clean;
    }

    private static function response_indicates_success(array $data): bool {
        if (!empty($data['status']) && strtolower((string) $data['status']) === 'success') {
            return true;
        }
        if (!empty($data['statusCode'])) {
            $status_code = strtolower((string) $data['statusCode']);
            if ($status_code === 'success' || $status_code === '200') {
                return true;
            }
        }
        if (!empty($data['message']) && is_string($data['message']) && strtolower(trim($data['message'])) === 'success') {
            if (isset($data['db-stats']) || isset($data['shorturl']) || isset($data['url']['shorturl']) || isset($data['link']) || isset($data['links']) || isset($data['stats']) || isset($data['kurl_extended'])) {
                return true;
            }
        }
        if (isset($data['db-stats']) || isset($data['shorturl']) || isset($data['url']['shorturl']) || isset($data['link']) || isset($data['links']) || isset($data['stats']) || isset($data['kurl_extended'])) {
            return true;
        }
        return false;
    }

    private static function handle_http_error(string $action, int $http_code, string $body, $data): array {
        if ($http_code === 400 && is_array($data) && isset($data['code']) && $data['code'] === 'error:url' && !empty($data['shorturl'])) {
            $data['ok'] = true;
            return $data;
        }
        /* translators: %d: HTTP status code. */
            $error_message = sprintf(__('HTTP error %d.', 'kurl-yourls'), $http_code);
        if ($http_code === 403) {
            $error_message .= ' ' . __('Forbidden. Check your signature or a firewall/security layer.', 'kurl-yourls');
        } elseif ($http_code === 404) {
            $error_message .= ' ' . __('Endpoint not found. Check the API URL.', 'kurl-yourls');
        } elseif ($http_code === 429) {
            $error_message .= ' ' . __('Too many requests. Please try again later.', 'kurl-yourls');
        } elseif ($http_code >= 500) {
            $error_message .= ' ' . __('The YOURLS server returned an internal error.', 'kurl-yourls');
        }
        if (is_array($data) && !empty($data['message'])) {
            /* translators: %s: Message returned by YOURLS. */
                $error_message .= ' ' . sprintf(__('YOURLS says: "%s"', 'kurl-yourls'), sanitize_text_field((string) $data['message']));
        }
        Kurl_Logger::log('error', 'API returned HTTP error', ['action' => $action, 'http_code' => $http_code, 'body' => self::truncate_for_log($body)]);
        return ['ok' => false, 'message' => trim($error_message), 'raw' => is_array($data) ? $data : []];
    }

    private static function truncate_for_log(string $text, int $limit = 500): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }
        return substr($text, 0, $limit);
    }
}
