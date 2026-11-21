<?php
// Tests the YOURLS helper's authentication gate with YOURLS function doubles.
// yourls_is_private() reproduces YOURLS 1.10 logic (includes/functions.php).
// Usage: php helper-test.php <plugin dir> <scenario>
define('YOURLS_ABSPATH', __DIR__);
$scenario = $argv[2];
if ($scenario === 'private')               { define('YOURLS_PRIVATE', true); }
if ($scenario === 'public')                { define('YOURLS_PRIVATE', false); }
if ($scenario === 'public-api')            { define('YOURLS_PRIVATE', true); define('YOURLS_PRIVATE_API', false); }
function yourls_is_API() { return true; }
function yourls_is_infos() { return false; }
function yourls_apply_filter($name, $value, ...$args) { return $value; }
function yourls_is_private() {
    $private = defined('YOURLS_PRIVATE') && YOURLS_PRIVATE;
    if ($private && yourls_is_API() && defined('YOURLS_PRIVATE_API')) { $private = YOURLS_PRIVATE_API; }
    return yourls_apply_filter('is_private', $private);
}
function yourls_is_valid_user() { return ($_REQUEST['signature'] ?? '') === 'good-token' ? true : 'Please log in'; }
function yourls_add_filter(...$a) {}
function yourls_sanitize_keyword($v, $strict = true) { return preg_replace('/[^a-zA-Z0-9]/', '', $v); }
function yourls_link($k) { return 'https://short.example/' . $k; }
function yourls_sanitize_url($u) { return $u; }
function yourls_sanitize_title($t) { return strip_tags($t); }
function yourls_get_keyword_infos($k, $c = false) { return $GLOBALS['rows'][$k] ?? false; }
function yourls_delete_link_by_keyword($k) { if (!isset($GLOBALS['rows'][$k])) { return 0; } unset($GLOBALS['rows'][$k]); return 1; }
function yourls_get_longurl_keywords($u, $o = 'ASC') { return array_keys(array_filter($GLOBALS['rows'], fn($r) => $r['url'] === $u)); }
function yourls_edit_link($url, $old, $new, $title) { $GLOBALS['rows'][$old]['url'] = $url; return ['status' => 'success', 'url' => []]; }
require $argv[1] . '/yourls-helper/plugin.php.txt';
$out = [];
foreach (['' => 'anonymous', 'bad-token' => 'wrong token', 'good-token' => 'valid token'] as $sig => $who) {
    $GLOBALS['rows'] = ['AbC' => ['url' => 'https://wp.example/1', 'title' => 't']];
    $_REQUEST = $_POST = $_GET = [];
    if ($sig !== '') { $_REQUEST['signature'] = $_POST['signature'] = $sig; }
    $_POST['shorturl'] = 'https://short.example/AbC';
    $del = kurl_api_delete();
    $GLOBALS['rows'] = ['AbC' => ['url' => 'https://wp.example/1', 'title' => 't']];
    $_POST['url'] = 'https://evil.example/'; $_POST['keyword'] = 'AbC';
    $reg = kurl_api_regenerate();
    $ping = kurl_api_ping();
    $out[] = sprintf("  %-12s delete=%-7s regenerate=%-7s ping=%s  target-after-regenerate=%s", $who, $del['status'], $reg['status'], $ping['status'] ?? '?', $GLOBALS['rows']['AbC']['url'] ?? '-');
}
echo "scenario $scenario:\n" . implode("\n", $out) . "\n";
