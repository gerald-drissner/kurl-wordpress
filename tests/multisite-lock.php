<?php
$GLOBALS["ok"] = 0; $GLOBALS["fail"] = 0;
function c($l, $a, $e) { global $ok, $fail; if ($a === $e) { $ok++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l: got " . var_export($a, true) . "\n"; } }
global $wpdb;
if (!is_plugin_active_for_network('kurl-short-url-manager-yourls/kurl.php')) { activate_plugin('kurl-short-url-manager-yourls/kurl.php', '', true); }
require_once WP_PLUGIN_DIR . '/kurl-short-url-manager-yourls/kurl.php';
$t1 = $wpdb->get_blog_prefix(1) . 'options'; $t2 = $wpdb->get_blog_prefix(2) . 'options';
$wpdb->query("DELETE FROM $t1 WHERE option_name LIKE 'kurl_lock_%'"); $wpdb->query("DELETE FROM $t2 WHERE option_name LIKE 'kurl_lock_%'");
switch_to_blog(1);
c('site1 acquire post 5', Kurl_Lock::acquire_post(5), true);
c('site1 second acquire blocked', Kurl_Lock::acquire_post(5), false);
switch_to_blog(2);
c('site2 same post ID independent', Kurl_Lock::acquire_post(5), true);
c('row in site2 table', (int) $wpdb->get_var("SELECT COUNT(*) FROM $t2 WHERE option_name='kurl_lock_post_5'"), 1);
c('row in site1 table', (int) $wpdb->get_var("SELECT COUNT(*) FROM $t1 WHERE option_name='kurl_lock_post_5'"), 1);
Kurl_Lock::release_post(5); // releases only site 2
c('site2 released', (int) $wpdb->get_var("SELECT COUNT(*) FROM $t2 WHERE option_name='kurl_lock_post_5'"), 0);
c('site1 untouched by site2 release', (int) $wpdb->get_var("SELECT COUNT(*) FROM $t1 WHERE option_name='kurl_lock_post_5'"), 1);
restore_current_blog();
// Now on site 2? restore returns to 1 (stack). Switch to 2 and call release_all — must delete in site1 table.
switch_to_blog(2);
Kurl_Lock::release_all();
c('release_all from site2 frees site1 lock', (int) $wpdb->get_var("SELECT COUNT(*) FROM $t1 WHERE option_name='kurl_lock_post_5'"), 0);
c('still on site2 after release_all', get_current_blog_id(), 2);
restore_current_blog();
restore_current_blog();
c('back on site1', get_current_blog_id(), 1);
// Stale takeover
$wpdb->query($wpdb->prepare("INSERT INTO $t1 (option_name, option_value, autoload) VALUES ('kurl_lock_post_9', %s, 'no')", (time() - 10000) . '|old'));
c('stale lock taken over', Kurl_Lock::acquire_post(9), true);
Kurl_Lock::release_post(9);
$wpdb->query($wpdb->prepare("INSERT INTO $t1 (option_name, option_value, autoload) VALUES ('kurl_lock_post_10', %s, 'no')", time() . '|fresh'));
c('fresh foreign lock respected', Kurl_Lock::acquire_post(10), false);
$wpdb->query("DELETE FROM $t1 WHERE option_name='kurl_lock_post_10'");
echo "RESULT: {$GLOBALS["ok"]} passed, {$GLOBALS["fail"]} failed\n";
