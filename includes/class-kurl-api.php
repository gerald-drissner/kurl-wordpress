<?php

defined('ABSPATH') || exit;

final class Kurl_API {

    private const MAX_RESPONSE_BYTES = 1048576;

    public static function configured(): bool {
        $settings = Kurl_Helpers::get_settings();
        return !empty($settings['api_url']) && !empty($settings['signature']);
    }

    /**
     * Send an authenticated request to YOURLS.
     *
     * @param string $action     YOURLS API action.
     * @param array  $params     Additional request parameters.
     * @param array  $connection Optional runtime connection override containing api_url and signature.
     * @return array
     */
    public static function request(string $action, array $params = [], array $connection = []): array {
        $settings  = Kurl_Helpers::get_settings();
        $api_url   = isset($connection['api_url']) ? Kurl_Helpers::normalize_api_url(Kurl_Helpers::scalar_string($connection['api_url'])) : Kurl_Helpers::scalar_string($settings['api_url']);
        $signature = isset($connection['signature']) ? sanitize_text_field(Kurl_Helpers::scalar_string($connection['signature'])) : Kurl_Helpers::scalar_string($settings['signature']);

        if ($api_url === '' || $signature === '') {
            return ['ok' => false, 'message' => __('Missing YOURLS API settings.', 'kurl-short-url-manager-yourls')];
        }

        $action = sanitize_key($action);
        if ($action === '') {
            return ['ok' => false, 'message' => __('Invalid API action.', 'kurl-short-url-manager-yourls')];
        }

        // Authentication and routing fields are authoritative. Additional
        // parameters must never be able to replace the signature, action, or
        // requested response format.
        $body = array_merge(self::sanitize_request_params($params), [
            'signature' => $signature,
            'action'    => $action,
            'format'    => 'json',
        ]);

        $connection_timeout = isset($connection['timeout'])
            ? Kurl_Helpers::scalar_int($connection['timeout'])
            : 0;
        $timeout = $connection_timeout > 0
            ? max(5, min(120, $connection_timeout))
            : max(5, min(120, Kurl_Helpers::scalar_int($settings['request_timeout'] ?? 15, 15)));
        $args = [
            'timeout'             => $timeout,
            // Do not forward the signature token across redirects. The final
            // yourls-api.php URL must be configured directly.
            'redirection'         => 0,
            'user-agent'          => 'kURL/' . KURL_VERSION,
            'sslverify'           => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'body'                => $body,
        ];

        /**
         * Allow private or otherwise non-public YOURLS API endpoints.
         *
         * Safe HTTP requests are used by default to prevent SSRF. Sites that intentionally
         * run YOURLS on a private address may opt out with this filter.
         *
         * @param bool   $allow_private Whether to use the unrestricted HTTP client.
         * @param string $api_url       Configured YOURLS API URL.
         */
        $allow_private = (bool) apply_filters('kurl_allow_private_api_url', false, $api_url);
        $response = $allow_private
            ? wp_remote_post($api_url, $args)
            : wp_safe_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            Kurl_Logger::log('error', 'API request failed', ['action' => $action, 'message' => $message]);
            return ['ok' => false, 'message' => $message];
        }

        $http_code   = (int) wp_remote_retrieve_response_code($response);
        $body        = (string) wp_remote_retrieve_body($response);
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $data        = json_decode($body, true);
        $json_error  = json_last_error();

        if ($http_code < 200 || $http_code >= 300) {
            return self::handle_http_error($action, $http_code, $body, $data, $content_type, $json_error, $signature);
        }

        if (!is_array($data)) {
            $message = self::non_json_message($body, $content_type, $json_error);
            Kurl_Logger::log('error', 'Invalid JSON from API', [
                'action'       => $action,
                'http_code'    => $http_code,
                'content_type' => $content_type,
                'json_error'   => function_exists('json_last_error_msg') ? json_last_error_msg() : (string) $json_error,
                'body'         => self::truncate_for_log($body, 500, [$signature]),
            ]);
            return ['ok' => false, 'message' => $message];
        }

        // YOURLS has returned an existing short URL with code error:url
        // using different HTTP status conventions across releases. Older
        // versions may send HTTP 200 while newer versions use an HTTP error
        // status. In either case, the response is usable when it contains a
        // valid short URL and should be adopted rather than reported as a
        // creation failure.
        if (self::is_existing_url_response($action, $data)) {
            $data['ok'] = true;
            return $data;
        }

        if (self::response_indicates_success($data)) {
            $data['ok'] = true;
            return $data;
        }

        $message = Kurl_Helpers::format_api_error($data);
        Kurl_Logger::log('error', 'YOURLS returned an error', ['action' => $action, 'message' => $message, 'body' => self::truncate_for_log($body, 500, [$signature])]);
        return [
            'ok'          => false,
            'message'     => $message,
            'raw'         => $data,
            'status_code' => self::extract_response_status_code($data),
        ];
    }

    public static function create_shortlink(string $url, string $keyword = '', string $title = '', array $connection = []): array {
        $url = Kurl_Helpers::sanitize_http_url($url);
        if ($url === '') {
            return ['ok' => false, 'message' => __('Missing or invalid target URL.', 'kurl-short-url-manager-yourls')];
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
        return self::request('shorturl', $params, $connection);
    }

    public static function aggregate_stats(array $connection = []): array {
        return self::request('stats', ['filter' => 'top', 'limit' => 10], $connection);
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
        $shorturl = Kurl_Helpers::sanitize_http_url($shorturl);
        if ($shorturl === '') {
            return ['ok' => false, 'message' => __('Missing short URL.', 'kurl-short-url-manager-yourls')];
        }
        return self::request('url-stats', ['shorturl' => $shorturl]);
    }

    public static function expand_shortlink(string $shorturl): array {
        $shorturl = Kurl_Helpers::sanitize_http_url($shorturl);
        if ($shorturl === '') {
            return ['ok' => false, 'message' => __('Missing short URL.', 'kurl-short-url-manager-yourls')];
        }
        return self::request('expand', ['shorturl' => $shorturl]);
    }

    public static function extended_api_info(array $connection = []): array {
        if (empty($connection) && !self::configured()) {
            return ['available' => false, 'version' => '', 'response' => []];
        }
        $response = self::request('kurl_ping', [], $connection);
        $extended_flag = isset($response['kurl_extended']) && is_scalar($response['kurl_extended'])
            ? strtolower(trim((string) $response['kurl_extended']))
            : '';
        $available = !empty($response['ok']) && in_array($extended_flag, ['1', 'true', 'yes'], true);
        $version = $available
            ? sanitize_text_field(Kurl_Helpers::scalar_string($response['kurl_helper_version'] ?? ''))
            : '';
        $capabilities = $available && !empty($response['kurl_capabilities']) && is_array($response['kurl_capabilities'])
            ? Kurl_Helpers::sanitize_key_list($response['kurl_capabilities'])
            : [];
        return [
            'available'    => $available,
            'version'      => $version,
            'capabilities' => $capabilities,
            'response'     => $response,
        ];
    }

    public static function check_extended_api(array $connection = []): bool {
        return !empty(self::extended_api_info($connection)['available']);
    }

    public static function delete_shortlink(string $shorturl): array {
        $shorturl = Kurl_Helpers::sanitize_http_url($shorturl);
        if ($shorturl === '') {
            return ['ok' => false, 'message' => __('Missing short URL.', 'kurl-short-url-manager-yourls')];
        }
        return self::request('kurl_delete', ['shorturl' => $shorturl]);
    }

    public static function find_by_longurl(string $url, string $preferred_shorturl = ''): array {
        $url = Kurl_Helpers::sanitize_http_url($url);
        if ($url === '') {
            return ['ok' => false, 'message' => __('Missing or invalid target URL.', 'kurl-short-url-manager-yourls')];
        }
        $params = ['url' => $url];
        $preferred_shorturl = Kurl_Helpers::sanitize_http_url($preferred_shorturl);
        if ($preferred_shorturl !== '') {
            $params['preferred_shorturl'] = $preferred_shorturl;
        }
        return self::request('kurl_find_by_url', $params);
    }

    public static function regenerate_shortlink(string $url, string $shorturl, string $keyword = '', string $title = ''): array {
        $url      = Kurl_Helpers::sanitize_http_url($url);
        $shorturl = Kurl_Helpers::sanitize_http_url($shorturl);
        if ($url === '' || $shorturl === '') {
            return ['ok' => false, 'message' => __('A valid target URL and existing short URL are required.', 'kurl-short-url-manager-yourls')];
        }
        $params = [
            'url'      => $url,
            'shorturl' => $shorturl,
        ];
        $keyword = Kurl_Helpers::sanitize_keyword($keyword);
        if ($keyword !== '') {
            $params['keyword'] = $keyword;
        }
        $title = sanitize_text_field($title);
        if ($title !== '') {
            $params['title'] = $title;
        }
        return self::request('kurl_regenerate', $params);
    }

    public static function extract_shorturl(array $response): string {
        if (!empty($response['shorturl']) && is_string($response['shorturl'])) {
            return Kurl_Helpers::sanitize_http_url($response['shorturl']);
        }
        if (isset($response['url']) && is_array($response['url']) && !empty($response['url']['shorturl']) && is_string($response['url']['shorturl'])) {
            return Kurl_Helpers::sanitize_http_url($response['url']['shorturl']);
        }
        if (isset($response['link']) && is_array($response['link']) && !empty($response['link']['shorturl']) && is_string($response['link']['shorturl'])) {
            return Kurl_Helpers::sanitize_http_url($response['link']['shorturl']);
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
                return Kurl_Helpers::sanitize_http_url($value);
            }
        }
        return '';
    }


    public static function is_not_found_response(array $response): bool {
        // Only application-level YOURLS errors count as a missing link. A
        // transport-level 404 with HTML or an empty body usually means the API
        // endpoint itself is wrong and must not trigger create/replace logic.
        $codes = [
            isset($response['statusCode']) && is_numeric($response['statusCode']) ? (int) $response['statusCode'] : 0,
            isset($response['errorCode']) && is_numeric($response['errorCode']) ? (int) $response['errorCode'] : 0,
        ];
        if (!empty($response['raw']) && is_array($response['raw'])) {
            $codes[] = self::extract_response_status_code($response['raw']);
        }
        if (in_array(404, $codes, true)) {
            return true;
        }

        $messages = [];
        foreach (['message', 'error'] as $key) {
            if (!empty($response[$key]) && is_scalar($response[$key])) {
                $messages[] = strtolower(trim((string) $response[$key]));
            }
            if (isset($response['raw']) && is_array($response['raw']) && !empty($response['raw'][$key]) && is_scalar($response['raw'][$key])) {
                $messages[] = strtolower(trim((string) $response['raw'][$key]));
            }
        }
        foreach ($messages as $message) {
            if ($message === 'not found'
                || preg_match('~^(?:error:\s*)?(?:short url|keyword|link)\b.*\bnot found$~i', $message)
            ) {
                return true;
            }
        }
        return false;
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

    /**
     * Whether YOURLS returned an existing short URL for a duplicate long URL.
     *
     * YOURLS has used HTTP 200, 400, and 409 for this application-level result
     * across releases. The stable contract is the error:url code together with
     * a valid returned short URL.
     */
    private static function is_existing_url_response(string $action, array $data): bool {
        if ($action !== 'shorturl') {
            return false;
        }

        $code = isset($data['code']) && is_scalar($data['code'])
            ? strtolower(trim((string) $data['code']))
            : '';

        return $code === 'error:url' && self::extract_shorturl($data) !== '';
    }

    private static function response_indicates_success(array $data): bool {
        $status = isset($data['status']) && is_scalar($data['status']) ? strtolower(trim((string) $data['status'])) : '';
        $status_code = isset($data['statusCode']) && is_numeric($data['statusCode']) ? (int) $data['statusCode'] : 0;
        $error_code = isset($data['errorCode']) && is_numeric($data['errorCode']) ? (int) $data['errorCode'] : 0;

        if (in_array($status, ['fail', 'failed', 'error'], true) || $status_code >= 400 || $error_code >= 400) {
            return false;
        }
        if ($status === 'success' || ($status_code >= 200 && $status_code < 300)) {
            return true;
        }
        if (isset($data['statusCode']) && is_scalar($data['statusCode']) && strtolower((string) $data['statusCode']) === 'success') {
            return true;
        }

        $has_known_payload = isset($data['db-stats'])
            || isset($data['shorturl'])
            || (isset($data['url']) && is_array($data['url']) && isset($data['url']['shorturl']))
            || isset($data['link'])
            || isset($data['links'])
            || isset($data['stats'])
            || isset($data['kurl_extended']);

        if (!empty($data['message']) && is_string($data['message']) && strtolower(trim($data['message'])) === 'success') {
            return $has_known_payload;
        }

        return $has_known_payload;
    }

    private static function handle_http_error(string $action, int $http_code, string $body, $data, string $content_type, int $json_error, string $secret = ''): array {
        if (in_array($http_code, [400, 409], true)
            && is_array($data)
            && self::is_existing_url_response($action, $data)
        ) {
            $data['ok'] = true;
            return $data;
        }

        /* translators: %d: HTTP status code. */
        $error_message = sprintf(__('HTTP error %d.', 'kurl-short-url-manager-yourls'), $http_code);
        if (is_array($data)) {
            $api_message = Kurl_Helpers::format_api_error($data);
            if ($api_message !== '') {
                /* translators: %s: Message returned by YOURLS. */
                $error_message .= ' ' . sprintf(__('YOURLS says: "%s"', 'kurl-short-url-manager-yourls'), sanitize_text_field($api_message));
            }
        }

        if ($http_code >= 300 && $http_code < 400) {
            $error_message .= ' ' . __('The API endpoint redirected the request. Enter the final yourls-api.php URL directly so the signature is not forwarded to another location.', 'kurl-short-url-manager-yourls');
        } elseif ($http_code === 403) {
            $error_message .= ' ' . __('Forbidden. Check your signature or a firewall/security layer.', 'kurl-short-url-manager-yourls');
        } elseif ($http_code === 404 && !is_array($data)) {
            $error_message .= ' ' . __('Endpoint not found. Check the API URL.', 'kurl-short-url-manager-yourls');
        } elseif ($http_code === 429) {
            $error_message .= ' ' . __('Too many requests. Please try again later.', 'kurl-short-url-manager-yourls');
        } elseif ($http_code >= 500 && !is_array($data)) {
            $error_message .= ' ' . __('The YOURLS server returned an internal error.', 'kurl-short-url-manager-yourls');
        }
        if (!is_array($data)) {
            $error_message .= ' ' . self::non_json_message($body, $content_type, $json_error);
        }
        Kurl_Logger::log('error', 'API returned HTTP error', [
            'action'       => $action,
            'http_code'    => $http_code,
            'content_type' => $content_type,
            'body'         => self::truncate_for_log($body, 500, [$secret]),
        ]);
        return [
            'ok'          => false,
            'message'     => trim($error_message),
            'raw'         => is_array($data) ? $data : [],
            'status_code' => $http_code,
        ];
    }

    private static function extract_response_status_code(array $data): int {
        foreach (['statusCode', 'errorCode', 'code'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }
        return 0;
    }

    private static function non_json_message(string $body, string $content_type, int $json_error): string {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return __('YOURLS returned an empty response. Check the YOURLS server and PHP error log.', 'kurl-short-url-manager-yourls');
        }
        if (stripos($content_type, 'text/html') !== false || preg_match('~<(?:!doctype|html|body|br|b|pre)\b~i', $trimmed)) {
            return __('YOURLS returned HTML instead of JSON. This usually indicates a PHP error, login page, proxy, or firewall response; check the YOURLS PHP error log.', 'kurl-short-url-manager-yourls');
        }
        $detail = function_exists('json_last_error_msg') ? json_last_error_msg() : (string) $json_error;
        /* translators: %s: JSON parser error. */
        return sprintf(__('YOURLS returned a non-JSON response (%s). Check the YOURLS server and helper plugin.', 'kurl-short-url-manager-yourls'), sanitize_text_field($detail));
    }

    private static function truncate_for_log(string $text, int $limit = 500, array $secrets = []): string {
        foreach ($secrets as $secret) {
            $secret = Kurl_Helpers::scalar_string($secret);
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        $text = preg_replace(
            '~((?:signature|password|passwd|token|authorization|api[_-]?key)\s*[\"\']?\s*[:=]\s*[\"\']?)[^&\s<>\"\']+~i',
            '$1[redacted]',
            $text
        ) ?: $text;
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
