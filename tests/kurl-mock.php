<?php
/**
 * Test-only mu-plugin: simulates a YOURLS API at https://sho.rt/yourls-api.php
 * and records every request in $GLOBALS['kurl_mock_calls'].
 */

$GLOBALS['kurl_mock_calls'] = [];
$GLOBALS['kurl_mock_mode']  = 'ok';

function kurl_mock_response(int $code, array $data): array {
    return [
        'headers'  => ['content-type' => 'application/json'],
        'body'     => wp_json_encode($data),
        'response' => ['code' => $code, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'yourls-api.php') === false) {
        return $pre;
    }
    $body = isset($args['body']) && is_array($args['body']) ? $args['body'] : [];
    $GLOBALS['kurl_mock_calls'][] = ['url' => $url, 'body' => $body, 'reject_unsafe_urls' => !empty($args['reject_unsafe_urls'])];

    if (!empty($GLOBALS['kurl_mock_hook'])) {
        ($GLOBALS['kurl_mock_hook'])($body['action'] ?? '');
    }
    if ($GLOBALS['kurl_mock_mode'] === 'echo-secret') {
        return kurl_mock_response(403, ['status' => 'fail', 'message' => 'Invalid signature ' . ($body['signature'] ?? ''), 'statusCode' => 403]);
    }
    if ($GLOBALS['kurl_mock_mode'] === 'no-helper' && ($body['action'] ?? '') === 'kurl_ping') {
        return kurl_mock_response(400, ['statusCode' => 400, 'message' => 'Unknown action']);
    }
    if ($GLOBALS['kurl_mock_mode'] === 'auth403') {
        // YOURLS core when the signature is wrong (includes/auth.php).
        return kurl_mock_response(403, ['simple' => 'Please log in', 'message' => 'Please log in', 'errorCode' => '403', 'callback' => '']);
    }
    if ($GLOBALS['kurl_mock_mode'] === 'waf403') {
        return ['headers' => ['content-type' => 'text/html'], 'body' => '<html><body><h1>403 Forbidden</h1></body></html>', 'response' => ['code' => 403, 'message' => 'Forbidden'], 'cookies' => [], 'filename' => null];
    }
    if ($GLOBALS['kurl_mock_mode'] === 'http503') {
        $r = kurl_mock_response(503, []);
        $r['body'] = '<html><body>Service Unavailable</body></html>';
        $r['headers'] = ['content-type' => 'text/html'];
        return $r;
    }
    if ($GLOBALS['kurl_mock_mode'] === 'down') {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds');
    }
    if ($GLOBALS['kurl_mock_mode'] === 'array-content-type') {
        $r = kurl_mock_response(500, ['x' => 1]);
        $r['body'] = '<html><body>Fatal</body></html>';
        $r['headers'] = ['content-type' => ['text/html', 'text/html; charset=UTF-8']];
        return $r;
    }

    $action = $body['action'] ?? '';
    switch ($action) {
        case 'shorturl':
            $keyword = !empty($body['keyword']) ? $body['keyword'] : 'k' . substr(md5($body['url'] ?? ''), 0, 5);
            return kurl_mock_response(200, [
                'status'     => 'success',
                'url'        => ['keyword' => $keyword, 'url' => $body['url'] ?? '', 'title' => $body['title'] ?? ''],
                'shorturl'   => 'https://sho.rt/' . $keyword,
                'statusCode' => 200,
            ]);
        case 'db-stats':
            return kurl_mock_response(200, ['db-stats' => ['total_links' => '5', 'total_clicks' => '9'], 'statusCode' => 200, 'message' => 'success']);
        case 'stats':
            return kurl_mock_response(200, ['links' => [], 'stats' => ['total_links' => 5, 'total_clicks' => 9], 'statusCode' => 200, 'message' => 'success']);
        case 'kurl_regenerate':
            $kw = 'r' . substr(md5(($body['shorturl'] ?? '') . 'x'), 0, 4);
            return kurl_mock_response(200, ['statusCode' => 200, 'status' => 'success', 'message' => 'Regenerated', 'shorturl' => 'https://sho.rt/' . (!empty($body['keyword']) ? $body['keyword'] : $kw)]);
        case 'kurl_delete':
            return kurl_mock_response(200, ['statusCode' => 200, 'status' => 'success', 'message' => 'Deleted']);
        case 'kurl_ping':
            return kurl_mock_response(200, ['statusCode' => 200, 'status' => 'success', 'message' => 'success', 'kurl_extended' => true, 'kurl_helper_version' => (defined('KURL_HELPER_VERSION') ? KURL_HELPER_VERSION : '1.1.5'), 'kurl_capabilities' => ['delete', 'find_by_url', 'regenerate']]);
    }
    return kurl_mock_response(400, ['statusCode' => 400, 'message' => 'Unknown action']);
}, 10, 3);
