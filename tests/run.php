<?php
/**
 * kURL regression tests. Run with: wp eval-file run.php
 * Works against 1.0.9 (to show the old behaviour) and 1.1.0.
 */

$is_new = version_compare(KURL_VERSION, '1.1.0', '>=');
$results = ['pass' => 0, 'fail' => 0];
$php_errors = [];

set_error_handler(function ($no, $str, $file, $line) use (&$php_errors) {
    if (strpos($file, 'kurl-short-url-manager-yourls') !== false) {
        $php_errors[] = basename($file) . ":$line $str";
    }
    return false;
});

function t(string $name, $actual, $expected, array &$results): void {
    $ok = $actual === $expected;
    $results[$ok ? 'pass' : 'fail']++;
    echo ($ok ? "  PASS " : "  FAIL ") . $name . ($ok ? '' : "\n         expected: " . var_export($expected, true) . "\n         actual:   " . var_export($actual, true)) . "\n";
}
function t_true(string $name, bool $cond, array &$results, string $detail = ''): void {
    $results[$cond ? 'pass' : 'fail']++;
    echo ($cond ? "  PASS " : "  FAIL ") . $name . ($cond || $detail === '' ? '' : "\n         " . $detail) . "\n";
}
function go_singular(int $id): void {
    $GLOBALS['wp_query'] = new WP_Query(['p' => $id, 'post_type' => 'any']);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($id);
    setup_postdata($GLOBALS['post']);
}
function go_archive(int $last_post_id): void {
    $GLOBALS['wp_query'] = new WP_Query(['post_type' => 'post', 'posts_per_page' => 5]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($last_post_id); // what remains after the archive loop
}
function mock_calls(string $action = ''): array {
    return array_values(array_filter($GLOBALS['kurl_mock_calls'], function ($c) use ($action) {
        return $action === '' || ($c['body']['action'] ?? '') === $action;
    }));
}
function make_post(array $args = [], array $meta = []): int {
    // Disable auto-create while building fixtures; restore afterwards.
    $settings = get_option('kurl_settings');
    $off = $settings; $off['auto_create_on_publish'] = 0;
    update_option('kurl_settings', $off); Kurl_Helpers::flush_settings_cache();
    $id = wp_insert_post(array_merge(['post_title' => 'P', 'post_status' => 'publish', 'post_type' => 'post'], $args));
    foreach ($meta as $k => $v) { add_post_meta($id, $k, $v); }
    update_option('kurl_settings', $settings); Kurl_Helpers::flush_settings_cache();
    return $id;
}

if (!class_exists('Kurl_Admin')) {
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    require_once KURL_PATH . 'includes/class-kurl-admin.php';
    require_once KURL_PATH . 'includes/class-kurl-bulk.php';
}
echo "kURL " . KURL_VERSION . " on WordPress " . get_bloginfo('version') . "\n";
wp_set_current_user(1);

// ---------------------------------------------------------------------------
echo "\n[1] wp_get_shortlink() – the case from Markus' e-mail\n";
$p_kurl = make_post(['post_title' => 'Managed'], ['_kurl_shorturl' => 'https://sho.rt/abc']);
$p_ylc  = make_post(['post_title' => 'YLC'], ['_yourls_url' => 'https://sho.rt/ylc', '_yourls_keyword' => 'ylc', '_yourls_clicks' => '7']);
$p_bet  = make_post(['post_title' => 'Better'], ['_better_yourls_short_link' => 'https://sho.rt/better']);
$p_none = make_post(['post_title' => 'None']);

go_singular($p_kurl);
t('footer: wp_get_shortlink() on a single post', wp_get_shortlink(), 'https://sho.rt/abc', $results);
t('head: wp_get_shortlink(0, "query")', wp_get_shortlink(0, 'query'), 'https://sho.rt/abc', $results);
t('explicit ID: wp_get_shortlink($id)', wp_get_shortlink($p_kurl), 'https://sho.rt/abc', $results);
go_singular($p_ylc);
t('footer: YOURLS Link Creator link (_yourls_url)', wp_get_shortlink(), 'https://sho.rt/ylc', $results);
go_singular($p_bet);
t('footer: Better YOURLS link (_better_yourls_short_link)', wp_get_shortlink(), 'https://sho.rt/better', $results);
go_singular($p_none);
t('post without link keeps core shortlink', wp_get_shortlink(), home_url('?p=' . $p_none), $results);
go_archive($p_kurl);
t('archive footer mirrors core (last loop post)', wp_get_shortlink(), 'https://sho.rt/abc', $results);
t('archive head (query context) stays empty', wp_get_shortlink(0, 'query'), '', $results);

// Loosely typed / short argument lists from third-party code.
go_singular($p_kurl);
try {
    $v = apply_filters('pre_get_shortlink', false, $p_kurl);
    t('pre_get_shortlink with only 2 args', $v, 'https://sho.rt/abc', $results);
} catch (Throwable $e) {
    t_true('pre_get_shortlink with only 2 args', false, $results, get_class($e) . ': ' . $e->getMessage());
}
try {
    $v = apply_filters('pre_get_shortlink', false, (string) $p_kurl, null, null);
    t('pre_get_shortlink with null context (no TypeError)', $v, $is_new ? false : 'https://sho.rt/abc', $results);
} catch (Throwable $e) {
    t_true('pre_get_shortlink with null context', false, $results, get_class($e) . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
echo "\n[2] Auto-create on publish (block editor / REST, %category% permalinks)\n";
$cat = wp_insert_term('Reise', 'category', ['slug' => 'reise']);
$cat_id = is_array($cat) ? (int) $cat['term_id'] : 0;
$GLOBALS['kurl_mock_calls'] = [];
$req = new WP_REST_Request('POST', '/wp/v2/posts');
$req->set_body_params(['title' => 'Tom & Jerry – Test', 'status' => 'publish', 'categories' => [$cat_id], 'content' => 'x']);
$res = rest_do_request($req);
$rest_id = (int) ($res->get_data()['id'] ?? 0);
$calls = mock_calls('shorturl');
t('REST publish triggers exactly one YOURLS create', count($calls), 1, $results);
$sent_url = $calls[0]['body']['url'] ?? '';
t('long URL contains the real category', strpos($sent_url, '/reise/') !== false, true, $results);
echo "         sent url: $sent_url\n";
t('title sent as plain text', $calls[0]['body']['title'] ?? '', 'Tom & Jerry – Test', $results);
t('short URL saved', get_post_meta($rest_id, '_kurl_shorturl', true) !== '', true, $results);

$GLOBALS['kurl_mock_calls'] = [];
wp_update_post(['ID' => $rest_id, 'post_title' => 'Edited']);
t('updating a published post creates nothing', count(mock_calls('shorturl')), 0, $results);

$GLOBALS['kurl_mock_calls'] = [];
$classic = wp_insert_post(['post_title' => 'Classic', 'post_status' => 'draft', 'post_category' => [$cat_id]]);
t('saving a draft creates nothing', count(mock_calls('shorturl')), 0, $results);
wp_update_post(['ID' => $classic, 'post_status' => 'publish']);
t('draft → publish creates one link', count(mock_calls('shorturl')), 1, $results);

$GLOBALS['kurl_mock_calls'] = [];
$future = wp_insert_post(['post_title' => 'Scheduled', 'post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + 3600)]);
wp_publish_post($future);
t('scheduled → publish (cron path) creates one link', count(mock_calls('shorturl')), 1, $results);

// ---------------------------------------------------------------------------
echo "\n[3] Shared-link detection (CAST AS BINARY on this database)\n";
$s1 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/AbC']);
$s2 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/AbC']);
$s3 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/abc2']);
$s4 = make_post([], ['_better_yourls_short_link' => 'https://sho.rt/Shared']);
$s5 = make_post([], ['_kurl_shorturl' => 'https://SHO.rt/Shared/']);
global $wpdb;
$wpdb->suppress_errors(false);
$wpdb->last_error = '';
t('exact match counts other post', Kurl_Shortlinks::count_other_references('https://sho.rt/AbC', $s1), 1, $results);
t('no false match on different case', Kurl_Shortlinks::count_other_references('https://sho.rt/abc2', $s3), 0, $results);
t('fallback finds equivalent spelling', Kurl_Shortlinks::count_other_references('https://sho.rt/Shared', $s4), 1, $results);
t('no SQL error', $wpdb->last_error, '', $results);
if ($is_new) {
    t('legacy Better YOURLS key is a shared reference', Kurl_Shortlinks::count_other_references('https://SHO.rt/Shared/', $s5), 1, $results);
}

// ---------------------------------------------------------------------------
if ($is_new) {
    echo "\n[4] Shortcode and template tags\n";
    go_singular($p_kurl);
    t('[kurl_shortlink]', do_shortcode('[kurl_shortlink]'), 'https://sho.rt/abc', $results);
    t('[kurl_shortlink link="1"]', do_shortcode('[kurl_shortlink link="1"]'), '<a href="https://sho.rt/abc" class="kurl-shortlink">https://sho.rt/abc</a>', $results);
    t('target _blank adds noopener', do_shortcode('[kurl_shortlink link="1" text="Teilen" target="_blank" class="x y"]'), '<a href="https://sho.rt/abc" class="x y" target="_blank" rel="noopener">Teilen</a>', $results);
    t('before/after', do_shortcode('[kurl_shortlink before="<strong>Kurz:</strong> "]'), '<strong>Kurz:</strong> https://sho.rt/abc', $results);
    t('escaping of text and class', do_shortcode('[kurl_shortlink link="1" text="<script>x</script>" class="a&quot;onclick"]'), '<a href="https://sho.rt/abc" class="aquotonclick">&lt;script&gt;x&lt;/script&gt;</a>', $results);
    t('invalid target ignored', do_shortcode('[kurl_shortlink link="1" target="javascript:x"]'), '<a href="https://sho.rt/abc" class="kurl-shortlink">https://sho.rt/abc</a>', $results);
    t('kurl_get_shortlink()', kurl_get_shortlink(), 'https://sho.rt/abc', $results);
    t('kurl_get_shortlink($id)', kurl_get_shortlink($p_ylc), 'https://sho.rt/ylc', $results);
    ob_start(); kurl_the_shortlink(['link' => 1]); $o = ob_get_clean();
    t_true('kurl_the_shortlink()', in_array($o, ['<a class="kurl-shortlink" href="https://sho.rt/abc">https://sho.rt/abc</a>', '<a href="https://sho.rt/abc" class="kurl-shortlink">https://sho.rt/abc</a>'], true), $results, $o);
    go_singular($p_none);
    t('no link → empty', do_shortcode('[kurl_shortlink]'), '', $results);
    t('fallback="1" → WordPress shortlink', do_shortcode('[kurl_shortlink fallback="1"]'), home_url('?p=' . $p_none), $results);
    go_archive($p_kurl);
    t('archive outside loop → empty', do_shortcode('[kurl_shortlink]'), '', $results);
    $draft = make_post(['post_status' => 'draft'], ['_kurl_shorturl' => 'https://sho.rt/draft']);
    wp_set_current_user(0);
    t('draft link hidden from visitors', do_shortcode('[kurl_shortlink post_id="' . $draft . '"]'), '', $results);
    wp_set_current_user(1);
    t('draft link visible to editors', do_shortcode('[kurl_shortlink post_id="' . $draft . '"]'), 'https://sho.rt/draft', $results);

    echo "\n[5] Setting: keep the WordPress shortlink\n";
    update_option('kurl_replace_wp_shortlink', 0);
    remove_all_filters('pre_get_shortlink');
    Kurl_Shortlinks::init();
    go_singular($p_kurl);
    t('toggle off → core shortlink', wp_get_shortlink(), home_url('?p=' . $p_kurl), $results);
    t('toggle off → shortcode still works', do_shortcode('[kurl_shortlink]'), 'https://sho.rt/abc', $results);
    update_option('kurl_replace_wp_shortlink', 1);
    remove_all_filters('pre_get_shortlink');
    Kurl_Shortlinks::init();
    t('toggle on again', wp_get_shortlink(), 'https://sho.rt/abc', $results);
    add_filter('pre_get_shortlink', function () { return 'https://other.example/x'; }, 5);
    t('default: earlier plugin shortlink respected', wp_get_shortlink(), 'https://other.example/x', $results);
    update_option('kurl_replace_wp_shortlink', 2);
    t('mode "always kURL" overrides earlier plugin', wp_get_shortlink(), 'https://sho.rt/abc', $results);
    update_option('kurl_replace_wp_shortlink', 1);
    remove_all_filters('pre_get_shortlink');
    add_filter('pre_get_shortlink', '__return_empty_string', 5);
    t('deliberately suppressed shortlink stays empty', wp_get_shortlink(), '', $results);
    remove_all_filters('pre_get_shortlink');
    Kurl_Shortlinks::init();
    $autoload = $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'kurl_replace_wp_shortlink'");
    t_true('option is autoloaded', in_array($autoload, ['on', 'yes', 'auto-on'], true), $results, "autoload=$autoload");

    echo "\n[6] Legacy import, counts, cleanup\n";
    $l1 = make_post([], ['_yourls_url' => 'https://sho.rt/l1', '_better_yourls_short_link' => 'https://sho.rt/l1b']);
    $l2 = make_post([], ['yourls_shorturl' => 'https://sho.rt/ozh']);
    $l3 = make_post([], ['_yourls_url' => 'not a url']);
    $l4 = make_post([], ['_yourls_url' => 'https://sho.rt/l4', '_kurl_shorturl' => 'https://sho.rt/managed4']);
    $counts = Kurl_Shortlinks::count_legacy_by_key();
    t('count _yourls_url posts', $counts['_yourls_url'], 4, $results);
    t('count _better_yourls_short_link posts', $counts['_better_yourls_short_link'], 3, $results);
    t('count yourls_shorturl posts', $counts['yourls_shorturl'], 1, $results);
    $r = Kurl_Shortlinks::import_legacy();
    t('import result', $r, ['imported' => 5, 'skipped' => 2, 'conflicts' => 1, 'failed' => 0, 'error' => false], $results);
    t('garbage legacy value not imported', get_post_meta($l3, '_kurl_shorturl', true), '', $results);
    t('sanitize_http_url rejects plain text', Kurl_Helpers::sanitize_http_url('not a url'), '', $results);
    t('sanitize_http_url keeps IDN host', Kurl_Helpers::sanitize_http_url('https://bücher.de/x'), 'https://bücher.de/x', $results);
    t('priority: YOURLS Link Creator wins', get_post_meta($l1, '_kurl_shorturl', true), 'https://sho.rt/l1', $results);
    t('Ozh key imported', get_post_meta($l2, '_kurl_shorturl', true), 'https://sho.rt/ozh', $results);
    t('managed link not overwritten', get_post_meta($l4, '_kurl_shorturl', true), 'https://sho.rt/managed4', $results);
    t('keyword derived from URL', get_post_meta($l2, '_kurl_keyword', true), 'ozh', $results);
    $deleted = Kurl_Shortlinks::delete_legacy();
    t('cleanup deletes only identical links (+ YLC keyword/clicks)', $deleted, ['deleted' => 7, 'kept' => 3, 'error' => false], $results);
    t('differing / unmigrated legacy rows kept', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_yourls_url','_better_yourls_short_link','yourls_shorturl','_yourls_keyword','_yourls_clicks')"), 3, $results);
    t('conflicting Better YOURLS link of l1 kept', get_post_meta($l1, '_better_yourls_short_link', true), 'https://sho.rt/l1b', $results);
    t('legacy link of post with other managed link kept', get_post_meta($l4, '_yourls_url', true), 'https://sho.rt/l4', $results);
    t('kURL meta kept', get_post_meta($p_ylc, '_kurl_shorturl', true), 'https://sho.rt/ylc', $results);

    echo "\n[7] Helpers\n";
    $tp = make_post(['post_title' => 'Tom &amp; Jerry &#8211; <b>Test</b>  “Quote”', 'post_password' => 'pw']);
    t('post_title_for_api()', Kurl_Helpers::post_title_for_api($tp), 'Tom & Jerry – Test “Quote”', $results);
    $m = new ReflectionMethod('Kurl_Admin', 'urls_match');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    t('urls_match ignores scheme/host case', $m->invoke(null, 'HTTPS://Example.COM/Path/', 'https://example.com/Path'), true, $results);
    t('urls_match keeps path case', $m->invoke(null, 'https://example.com/path', 'https://example.com/Path'), false, $results);
}

// ---------------------------------------------------------------------------
echo "\n[8] Local cleanup (semantics must match 1.0.9)\n";
$c1 = make_post([], ['_kurl_shorturl' => 'javascript:alert(1)', '_kurl_keyword' => 'kw1', '_kurl_stats' => ['clicks' => 3]]);
$c2 = make_post([], ['_kurl_keyword' => 'stale']);
$c3 = make_post([], ['_kurl_stats' => ['clicks' => 1]]);
$c4 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/a b']);
$c5 = make_post([], ['_yourls_url' => 'https://sho.rt/legacy5', '_kurl_keyword' => 'legacy5']);
$c6 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/ok6', '_kurl_keyword' => "\x01\x02"]);
$_REQUEST['_wpnonce'] = wp_create_nonce('kurl_cleanup_local');
$redirect = '';
add_filter('wp_redirect', function ($location) use (&$redirect) { $redirect = $location; throw new RuntimeException('redirect'); }, 1);
$mem_before = memory_get_usage();
try { Kurl_Admin::cleanup_local(); } catch (RuntimeException $e) {}
parse_str((string) wp_parse_url($redirect, PHP_URL_QUERY), $q);
echo "         report: keywords_removed={$q['keywords_removed']} stats_removed={$q['stats_removed']} urls_normalized={$q['urls_normalized']} urls_removed={$q['urls_removed']}\n";
t('invalid URL removed', get_post_meta($c1, '_kurl_shorturl', true), '', $results);
t('stale keyword removed', get_post_meta($c2, '_kurl_keyword', true), '', $results);
t('orphan stats removed', get_post_meta($c3, '_kurl_stats', true), '', $results);
t('URL normalized', get_post_meta($c4, '_kurl_shorturl', true), 'https://sho.rt/a%20b', $results);
t('keyword of legacy link kept', get_post_meta($c5, '_kurl_keyword', true), 'legacy5', $results);
t('control-character keyword removed', get_post_meta($c6, '_kurl_keyword', true), '', $results);
remove_all_filters('wp_redirect');

// ---------------------------------------------------------------------------
echo "\n[9] Dashboard when YOURLS is down\n";
$GLOBALS['kurl_mock_mode'] = 'down';
delete_transient('kurl_dashboard_overview');
$GLOBALS['kurl_mock_calls'] = [];
$dash = new ReflectionMethod('Kurl_Admin', 'get_dashboard_data');
if (PHP_VERSION_ID < 80100) { $dash->setAccessible(true); }
$d = $dash->invoke(null);
$first = count(mock_calls());
$dash->invoke(null);
$second = count(mock_calls()) - $first;
echo "         requests: first load $first, second load $second\n";
t('first load: one request', $first, $is_new ? 1 : 3, $results);
t('second load: served from cache', $second, $is_new ? 0 : 3, $results);
echo "         message: " . $d['error'] . "\n";

$GLOBALS['kurl_mock_mode'] = 'array-content-type';
$before = count($php_errors);
$r = Kurl_API::db_stats();
t('repeated Content-Type header: no PHP warning', count($php_errors) - $before, 0, $results);
t('still diagnosed as HTML', strpos($r['message'] ?? '', 'HTML') !== false, true, $results);
$GLOBALS['kurl_mock_mode'] = 'ok';
delete_transient('kurl_dashboard_overview');

// ---------------------------------------------------------------------------
echo "\n[10] Admin screens render without PHP notices\n";
$before = count($php_errors);
foreach (['render_dashboard', 'render_settings', 'render_bulk', 'render_sync_cleanup', 'render_logs'] as $screen) {
    ob_start();
    Kurl_Admin::$screen();
    $html = ob_get_clean();
    t_true("$screen renders", strlen($html) > 500, $results, 'output length ' . strlen($html));
    if ($screen === 'render_settings' && $is_new) {
        t_true('settings: shortlink mode present', substr_count($html, 'name="shortlink_mode"') === 3, $results);
        t_true('settings: legacy source table present', strpos($html, 'YOURLS Link Creator') !== false, $results);
    }
}
ob_start(); Kurl_Admin::render_meta_box(get_post($p_kurl)); ob_end_clean();
t('no PHP notices from admin screens', array_slice($php_errors, $before), [], $results);


// ---------------------------------------------------------------------------
if ($is_new) {
    class KurlAjaxDie extends Exception {}
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_ajax_handler', function () { return function () { throw new KurlAjaxDie('die'); }; });
    function call_ajax(callable $cb, array $post): array {
        $_POST = $post; $_REQUEST = array_merge($post, ['nonce' => wp_create_nonce('kurl_admin')]);
        ob_start();
        try { call_user_func($cb); } catch (KurlAjaxDie $e) {}
        $out = ob_get_clean();
        Kurl_Lock::release_all();   // the end of the simulated request
        $json = json_decode((string) $out, true);
        return is_array($json) ? $json : ['raw' => $out];
    }
    function set_helper(bool $available, int $checked_at, string $version = ''): void {
        $cur = Kurl_Helpers::get_settings();
        Kurl_Helpers::save_helper_status($cur['api_url'], $cur['signature'], [
            'api_extended'        => $available ? 1 : 0,
            'helper_version'      => $available ? ($version !== '' ? $version : KURL_HELPER_VERSION) : '',
            'helper_capabilities' => $available ? ['delete', 'find_by_url', 'regenerate'] : [],
            'helper_checked_at'   => $checked_at,
        ]);
    }
    function raw_option(string $name) {
        global $wpdb;
        return maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name)));
    }
    function write_option_behind_cache(string $name, $value): void {
        // What a second PHP process does: write the row; this process keeps its cached copy.
        global $wpdb;
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($value)], ['option_name' => $name]);
    }
    function break_queries(string $needle): callable {
        global $wpdb;
        $wpdb->suppress_errors(true);
        $f = function ($q) use ($needle) { return strpos($q, $needle) !== false ? 'SELECT broken_column FROM kurl_missing_table' : $q; };
        add_filter('query', $f);
        return function () use ($f, $wpdb) { remove_filter('query', $f); $wpdb->suppress_errors(false); $wpdb->last_error = ''; };
    }

    echo "\n[11] Shortlink contexts exactly like core\n";
    go_singular($p_ylc);
    t('query context ignores an unrelated explicit ID', wp_get_shortlink($p_kurl, 'query'), 'https://sho.rt/ylc', $results);
    t('blog context: site ID is not read as post ID', wp_get_shortlink(1, 'blog'), '', $results);
    t('unknown post ID → empty like core', wp_get_shortlink(999999), '', $results);

    echo "\n[12] Delete scope (local confirmation is never widened)\n";
    $d1 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/del1']);
    set_helper(false, time() - 600);   // stale status: UI shows "Unlink locally"
    $GLOBALS['kurl_mock_calls'] = [];
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $d1]);
    t('unlink without the confirmed URL (old script) is refused', [$r['success'] ?? null, get_post_meta($d1, '_kurl_shorturl', true)], [false, 'https://sho.rt/del1'], $results);
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $d1, 'shorturl' => 'https://sho.rt/del1']);
    t('local unlink succeeds', $r['success'] ?? null, true, $results);
    t('no remote delete although helper is reachable now', count(mock_calls('kurl_delete')), 0, $results);
    t('local meta removed', get_post_meta($d1, '_kurl_shorturl', true), '', $results);

    $d2 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/del2']);
    $GLOBALS['kurl_mock_mode'] = 'no-helper';
    set_helper(true, time() - 600);
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $d2, 'delete_mode' => 'remote', 'shorturl' => 'https://sho.rt/del2']);
    t('remote delete without verified helper → refused', $r['success'] ?? null, false, $results);
    t('refusal returns new helper status for the UI', isset($r['data']['helper_status']['delete_available']), true, $results);
    t('nothing changed locally', get_post_meta($d2, '_kurl_shorturl', true), 'https://sho.rt/del2', $results);
    $GLOBALS['kurl_mock_mode'] = 'ok';
    set_helper(true, time());
    $GLOBALS['kurl_mock_calls'] = [];
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $d2, 'delete_mode' => 'remote']);
    t('remote delete without the confirmed link → refused', [$r['success'] ?? null, count(mock_calls('kurl_delete'))], [false, 0], $results);
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $d2, 'delete_mode' => 'remote', 'shorturl' => 'https://sho.rt/OTHER']);
    t('link changed since confirmation → refused', [$r['success'] ?? null, $r['data']['shorturl'] ?? '', count(mock_calls('kurl_delete'))], [false, 'https://sho.rt/del2', 0], $results);
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $d2, 'delete_mode' => 'remote', 'shorturl' => 'https://sho.rt/del2']);
    t('confirmed remote delete works', [$r['success'] ?? null, count(mock_calls('kurl_delete'))], [true, 1], $results);

    set_helper(true, time(), '1.1.5');
    $d3 = make_post([], ['_kurl_shorturl' => 'https://sho.rt/del3']);
    $GLOBALS['kurl_mock_calls'] = [];
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $d3, 'delete_mode' => 'remote', 'shorturl' => 'https://sho.rt/del3']);
    t('outdated helper 1.1.5 blocks remote deletion', [$r['success'] ?? null, count(mock_calls('kurl_delete'))], [false, 0], $results);
    set_helper(true, time());

    echo "\n[13] Per-post lock and the update/delete race\n";
    t('acquire', Kurl_Lock::acquire_post(4242), true, $results);
    t('second acquire in same request refused', Kurl_Lock::acquire_post(4242), false, $results);
    Kurl_Lock::release_post(4242);
    t('acquire after release', Kurl_Lock::acquire_post(4242), true, $results);
    Kurl_Lock::release_post(4242);
    $wpdb->insert($wpdb->options, ['option_name' => 'kurl_lock_post_4243', 'option_value' => (time() - 3600) . '|stale', 'autoload' => 'no']);
    t('stale lock of a crashed request is taken over', Kurl_Lock::acquire_post(4243), true, $results);
    Kurl_Lock::release_post(4243);
    t('released lock row is gone', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'kurl\\_lock\\_%'"), '0', $results);

    $race = make_post([], ['_kurl_shorturl' => 'https://sho.rt/race']);
    $nested = null;
    $GLOBALS['kurl_mock_hook'] = function ($action) use ($race, &$nested) {
        if ($action === 'kurl_regenerate' && $nested === null) {
            // A second tab tries to delete while the update is in flight.
            $nested = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $race, 'delete_mode' => 'remote', 'shorturl' => 'https://sho.rt/race']);
        }
    };
    $r = call_ajax(['Kurl_Admin', 'ajax_generate_post_link'], ['post_id' => (string) $race]);
    $GLOBALS['kurl_mock_hook'] = null;
    t('concurrent delete is refused while update runs', [$nested['success'] ?? null, strpos($nested['data']['message'] ?? '', 'already running') !== false], [false, true], $results);
    t('update completes', $r['success'] ?? null, true, $results);

    echo "\n[14] Disconnect button, settings race, post types, stats\n";
    $_POST = ['_wpnonce' => wp_create_nonce('kurl_save_settings'), 'disconnect_api' => 'Disconnect API', 'api_url' => 'https://sho.rt', 'signature' => ''];
    $_REQUEST = $_POST;
    add_filter('wp_redirect', function ($l) { throw new RuntimeException($l); }, 1);
    try { Kurl_Admin::save_settings(); } catch (RuntimeException $e) {}
    remove_all_filters('wp_redirect');
    Kurl_Helpers::flush_settings_cache();
    t('Disconnect (button sends its label) clears credentials', [Kurl_Helpers::get_settings()['api_url'], Kurl_Helpers::get_settings()['signature']], ['', ''], $results);
    update_option('kurl_settings', array_merge(get_option('kurl_settings'), ['api_url' => 'https://sho.rt/yourls-api.php', 'signature' => 'abc123def4'])); Kurl_Helpers::flush_settings_cache();

    $n = 0;
    $GLOBALS['kurl_mock_hook'] = function ($action) use (&$n) {
        if (++$n === 1) {  // another tab saves a new signature during the test
            $s = get_option('kurl_settings'); $s['signature'] = 'NEW-SIGNATURE-1'; update_option('kurl_settings', $s);
        }
    };
    call_ajax(['Kurl_Admin', 'ajax_test_api'], ['api_url' => 'https://sho.rt/yourls-api.php']);
    $GLOBALS['kurl_mock_hook'] = null;
    t('API test keeps a signature saved meanwhile', get_option('kurl_settings')['signature'], 'NEW-SIGNATURE-1', $results);
    $s = get_option('kurl_settings'); $s['signature'] = 'abc123def4'; update_option('kurl_settings', $s); Kurl_Helpers::flush_settings_cache();

    $backup = get_option('kurl_settings');
    update_option('kurl_settings', array_merge($backup, ['enabled_post_types' => []])); Kurl_Helpers::flush_settings_cache();
    t('legacy stored empty selection keeps post+page', Kurl_Helpers::enabled_post_types(), ['post', 'page'], $results);
    update_option('kurl_settings', array_merge($backup, ['enabled_post_types' => [], 'post_types_explicit' => 1])); Kurl_Helpers::flush_settings_cache();
    t('explicitly empty selection stays empty', Kurl_Helpers::enabled_post_types(), [], $results);
    $m = new ReflectionMethod('Kurl_Admin', 'get_enabled_post_ids_batch'); if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    t('cleanup touches no posts when none are enabled', $m->invoke(null, 0, 250), [], $results);
    update_option('kurl_settings', $backup); Kurl_Helpers::flush_settings_cache();

    $st = make_post([], ['_kurl_shorturl' => 'https://sho.rt/st1']);
    Kurl_Shortlinks::save_stats($st, ['clicks' => 999]);
    t('save_link same URL returns true', Kurl_Shortlinks::save_link($st, 'https://sho.rt/st1'), true, $results);
    t('same URL keeps click count', Kurl_Shortlinks::get_stats($st)['clicks'] ?? null, 999, $results);
    Kurl_Shortlinks::save_link($st, 'https://sho.rt/st2');
    t('new URL drops the old click count', Kurl_Shortlinks::get_stats($st), [], $results);

    echo "\n[15] Secrets, log retention, batch budget, cache\n";
    Kurl_Logger::clear();
    $GLOBALS['kurl_mock_mode'] = 'echo-secret';
    $r = Kurl_API::request('stats');
    $GLOBALS['kurl_mock_mode'] = 'ok';
    $log_files = glob(wp_get_upload_dir()['basedir'] . '/kurl-short-url-manager-yourls/*.log');
    $log = $log_files ? file_get_contents($log_files[0]) : '';
    t('signature not in returned message', strpos($r['message'] ?? '', 'abc123def4'), false, $results);
    t('signature not in log file', $log !== '' && strpos($log, 'abc123def4') === false, true, $results);

    $old = wp_json_encode(['time' => time() - 10 * DAY_IN_SECONDS, 'level' => 'info', 'message' => 'old-entry', 'context' => []]);
    file_put_contents($log_files[0], $old . PHP_EOL . file_get_contents($log_files[0]));
    Kurl_Logger::log('info', 'today');
    t('entries older than 7 days are deleted from disk', strpos(file_get_contents($log_files[0]), 'old-entry'), false, $results);
    t('current entries survive pruning', strpos(file_get_contents($log_files[0]), 'today') !== false, true, $results);
    t('daily prune event scheduled', (bool) wp_next_scheduled('kurl_prune_log'), true, $results);

    for ($i = 0; $i < 4; $i++) { make_post(['post_title' => "Budget $i"]); }
    add_filter('kurl_batch_time_budget', '__return_zero');
    $r = call_ajax(['Kurl_Bulk', 'ajax_batch'], ['post_type' => 'post', 'batch_size' => '10', 'mode' => 'skip', 'last_id' => '0']);
    remove_all_filters('kurl_batch_time_budget');
    t('exhausted time budget → one post per batch, cursor kept', count($r['data']['results'] ?? []), 1, $results);

    set_transient('kurl_dashboard_overview', ['x' => 1], 600);
    call_ajax(['Kurl_Admin', 'ajax_manual_generate_url'], ['url' => 'https://example.com/manual']);
    t('manual shortener refreshes dashboard cache', get_transient('kurl_dashboard_overview'), false, $results);

    echo "\n[16] Import when WordPress rejects the write\n";
    $rj = make_post([], ['_yourls_url' => 'https://sho.rt/rejected']);
    $block = function ($check, $object_id, $meta_key) use ($rj) { return ((int) $object_id === $rj && $meta_key === '_kurl_shorturl') ? false : $check; };
    add_filter('add_post_metadata', $block, 10, 3);
    add_filter('update_post_metadata', $block, 10, 3);
    $r = Kurl_Shortlinks::import_legacy();
    remove_filter('add_post_metadata', $block, 10);
    remove_filter('update_post_metadata', $block, 10);
    t('rejected write is counted as failed, not imported', $r['failed'], 1, $results);
    t('kURL field stays empty', get_post_meta($rj, '_kurl_shorturl', true), '', $results);
    Kurl_Shortlinks::delete_legacy();
    t('cleanup keeps the only remaining record', get_post_meta($rj, '_yourls_url', true), 'https://sho.rt/rejected', $results);
    add_filter('kurl_legacy_meta_keys', function ($k) { $k[] = '_my_custom_short'; return $k; });
    $cu = make_post([], ['_my_custom_short' => 'https://sho.rt/custom']);
    Kurl_Shortlinks::import_legacy();
    Kurl_Shortlinks::delete_legacy();
    t('custom read key is imported', get_post_meta($cu, '_kurl_shorturl', true), 'https://sho.rt/custom', $results);
    t('custom read key is not deleted without opt-in', get_post_meta($cu, '_my_custom_short', true), 'https://sho.rt/custom', $results);
    remove_all_filters('kurl_legacy_meta_keys');


    echo "\n[18] Reference check fails → action stops, link kept\n";
    set_helper(true, time());
    $rf = make_post([], ['_kurl_shorturl' => 'https://sho.rt/reffail']);
    $restore = break_queries('CAST(meta_value AS BINARY)');
    $before = count($php_errors);
    $GLOBALS['kurl_mock_calls'] = [];
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $rf, 'delete_mode' => 'remote', 'shorturl' => 'https://sho.rt/reffail']);
    $g = call_ajax(['Kurl_Admin', 'ajax_generate_post_link'], ['post_id' => (string) $rf]);
    $restore();
    t('no PHP error, clean error response', [$r['success'] ?? null, strpos($r['data']['message'] ?? '', 'could not check') !== false, count($php_errors) - $before], [false, true, 0], $results);
    t('mapping kept, nothing sent to YOURLS', [get_post_meta($rf, '_kurl_shorturl', true), count(mock_calls('kurl_delete')) + count(mock_calls('kurl_regenerate'))], ['https://sho.rt/reffail', 0], $results);
    t('update also stops', $g['success'] ?? null, false, $results);

    echo "\n[19] Second process saves settings during API test / helper check\n";
    $base = get_option('kurl_settings');   // this process has the row cached now
    $GLOBALS['kurl_mock_hook'] = function ($action) use ($base) {
        static $done = false;
        if (!$done) { $done = true; write_option_behind_cache('kurl_settings', array_merge($base, ['api_url' => 'https://other.example/yourls-api.php', 'cache_minutes' => 47])); }
    };
    call_ajax(['Kurl_Admin', 'ajax_test_api'], ['api_url' => 'https://sho.rt/yourls-api.php']);
    $GLOBALS['kurl_mock_hook'] = null;
    $db = raw_option('kurl_settings');
    t('API test leaves the other process\'s settings in place', [$db['api_url'] ?? '', (int) ($db['cache_minutes'] ?? 0)], ['https://other.example/yourls-api.php', 47], $results);
    write_option_behind_cache('kurl_settings', $base); wp_cache_delete('kurl_settings', 'options'); Kurl_Helpers::flush_settings_cache();
    $GLOBALS['kurl_mock_hook'] = function ($action) use ($base) {
        static $done = false;
        if (!$done) { $done = true; write_option_behind_cache('kurl_settings', array_merge($base, ['cache_minutes' => 48])); }
    };
    Kurl_Admin::refresh_helper_status(true);
    $GLOBALS['kurl_mock_hook'] = null;
    t('helper check never writes kurl_settings', (int) (raw_option('kurl_settings')['cache_minutes'] ?? 0), 48, $results);
    write_option_behind_cache('kurl_settings', $base); wp_cache_delete('kurl_settings', 'options'); Kurl_Helpers::flush_settings_cache();
    $st = raw_option('kurl_helper_status');
    t('helper verdict bound to the checked connection', $st['fingerprint'] ?? '', Kurl_Helpers::connection_fingerprint('https://sho.rt/yourls-api.php', 'abc123def4'), $results);
    update_option('kurl_settings', array_merge($base, ['signature' => 'changed-token-9'])); Kurl_Helpers::flush_settings_cache();
    t('verdict does not apply to other credentials', Kurl_Helpers::get_settings()['api_extended'], 0, $results);
    update_option('kurl_settings', $base); Kurl_Helpers::flush_settings_cache();

    echo "\n[20] Database error during migration\n";
    update_option('better_yourls', ['domain' => 'x']);
    $bz = make_post([], ['_better_yourls_short_link' => 'https://sho.rt/bz']);
    $restore = break_queries('SELECT DISTINCT post_id');
    $c = Kurl_Shortlinks::delete_legacy();
    $i = Kurl_Shortlinks::import_legacy();
    $restore();
    t('cleanup reports the error', $c['error'], true, $results);
    t('Better YOURLS settings kept after the error', get_option('better_yourls') !== false, true, $results);
    t('import reports the error', $i['error'], true, $results);
    t('legacy link untouched', get_post_meta($bz, '_better_yourls_short_link', true), 'https://sho.rt/bz', $results);

    echo "\n[21] Unlink keeps legacy fields that were not confirmed\n";
    add_filter('kurl_legacy_meta_keys', function ($k) { $k[] = '_my_read_only'; return $k; });
    $ul = make_post([], ['_kurl_shorturl' => 'https://sho.rt/shown', '_yourls_url' => 'https://sho.rt/shown', '_my_read_only' => 'https://sho.rt/different']);
    $r = call_ajax(['Kurl_Admin', 'ajax_delete_post_link'], ['post_id' => (string) $ul, 'shorturl' => 'https://sho.rt/shown']);
    t('unlink succeeds and mentions the kept field', [$r['success'] ?? null, strpos($r['data']['message'] ?? '', 'kept') !== false], [true, true], $results);
    t('matching legacy URL removed', get_post_meta($ul, '_yourls_url', true), '', $results);
    t('read-only field with another URL kept', get_post_meta($ul, '_my_read_only', true), 'https://sho.rt/different', $results);
    t('kept field no longer shown as the short URL', Kurl_Shortlinks::get_shorturl($ul), '', $results);
    Kurl_Shortlinks::import_legacy();
    t('import does not resurrect it', get_post_meta($ul, '_kurl_shorturl', true), '', $results);
    Kurl_Shortlinks::save_link($ul, 'https://sho.rt/new-one');
    t('a new kURL link clears the suppression', get_post_meta($ul, '_kurl_no_legacy', true), '', $results);
    remove_all_filters('kurl_legacy_meta_keys');

    echo "\n[22] Rejected URL write leaves keyword and stats untouched\n";
    $sv = make_post([], ['_kurl_shorturl' => 'https://sho.rt/before', '_kurl_keyword' => 'before', '_kurl_stats' => ['clicks' => 37]]);
    $block = function ($check, $object_id, $meta_key) use ($sv) { return ((int) $object_id === $sv && $meta_key === '_kurl_shorturl') ? false : $check; };
    add_filter('update_post_metadata', $block, 10, 3);
    $ok = Kurl_Shortlinks::save_link($sv, 'https://sho.rt/after');
    remove_filter('update_post_metadata', $block, 10);
    t('save_link reports failure', $ok, false, $results);
    t('URL, keyword and stats unchanged', [get_post_meta($sv, '_kurl_shorturl', true), get_post_meta($sv, '_kurl_keyword', true), Kurl_Shortlinks::get_stats($sv)['clicks'] ?? null], ['https://sho.rt/before', 'before', 37], $results);

    echo "\n[17] Dashboard on HTTP 503\n";
    $GLOBALS['kurl_mock_mode'] = 'http503';
    delete_transient('kurl_dashboard_overview');
    $GLOBALS['kurl_mock_calls'] = [];
    $dash->invoke(null);
    t('server error: one request on first load', count(mock_calls()), 1, $results);
    $GLOBALS['kurl_mock_mode'] = 'ok';
    delete_transient('kurl_dashboard_overview');
}

// ---------------------------------------------------------------------------
echo "\nPHP notices/warnings from kURL files during the run: " . count($php_errors) . "\n";
foreach (array_unique($php_errors) as $e) { echo "  ! $e\n"; }
echo "\nRESULT: {$results['pass']} passed, {$results['fail']} failed\n";
