<?php

defined('ABSPATH') || exit;

/**
 * Short-lived, database-backed mutex for operations on one post's short URL.
 *
 * Two overlapping requests (two browser tabs, an editor action during a bulk
 * run, or auto-create during a manual update) could otherwise interleave a
 * remote YOURLS change with a local metadata write and leave WordPress pointing
 * to a link that YOURLS no longer has.
 *
 * Uses INSERT IGNORE on the unique option_name column, the same pattern as
 * WP_Upgrader::create_lock(). add_option() is not atomic because it runs
 * INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * Locks are per site: post IDs and options tables are per site on multisite.
 * Each held lock remembers the site it was taken on and is always released
 * there, also after switch_to_blog() and on shutdown.
 */
final class Kurl_Lock {

    private const PREFIX = 'kurl_lock_';

    /** @var array<string,array{blog_id:int,option:string,value:string}> Held locks by "blog_id:option". */
    private static array $held = [];

    private static bool $shutdown_registered = false;

    public static function acquire_post(int $post_id): bool {
        return $post_id > 0 && self::acquire('post_' . $post_id);
    }

    public static function release_post(int $post_id): void {
        if ($post_id > 0) {
            self::release('post_' . $post_id);
        }
    }

    public static function acquire(string $name, int $ttl = 0): bool {
        global $wpdb;

        $option = self::option_name($name);
        $key    = self::key($option);
        // A nested call inside the same request is a conflict as well.
        if ($option === '' || isset(self::$held[$key])) {
            return false;
        }
        $ttl = $ttl > 0 ? $ttl : self::default_ttl();

        $value = time() . '|' . wp_generate_password(20, false, false);
        if (!self::insert($option, $value)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock state must bypass the options cache.
            $current = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
            if ($current !== null && (int) strtok((string) $current, '|') > time() - $ttl) {
                return false;
            }
            // Expired lock of a request that died. Remove exactly that value,
            // so a lock taken by someone else meanwhile survives.
            if ($current !== null) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete of an expired lock.
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, (string) $current));
            }
            if (!self::insert($option, $value)) {
                return false;
            }
        }

        self::$held[$key] = ['blog_id' => self::blog_id(), 'option' => $option, 'value' => $value];
        wp_cache_delete($option, 'options');
        if (!self::$shutdown_registered) {
            // wp_send_json_*() ends the request with die(), which skips
            // finally blocks; shutdown functions still run.
            register_shutdown_function([__CLASS__, 'release_all']);
            self::$shutdown_registered = true;
        }
        return true;
    }

    /**
     * Release a lock that the current site holds.
     */
    public static function release(string $name): void {
        $key = self::key(self::option_name($name));
        if (isset(self::$held[$key])) {
            self::release_entry($key);
        }
    }

    public static function release_all(): void {
        foreach (array_keys(self::$held) as $key) {
            self::release_entry($key);
        }
    }

    private static function release_entry(string $key): void {
        global $wpdb;

        $entry = self::$held[$key];
        $switched = false;
        if (is_multisite() && $entry['blog_id'] > 0 && $entry['blog_id'] !== self::blog_id()) {
            switch_to_blog($entry['blog_id']);
            $switched = true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the lock this request owns, in the table it was taken in.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $entry['option'], $entry['value']));
        wp_cache_delete($entry['option'], 'options');
        if ($switched) {
            restore_current_blog();
        }
        unset(self::$held[$key]);
    }

    /**
     * Long enough for the slowest action (sync: up to four YOURLS requests),
     * short enough that a crashed request does not block a post for long.
     */
    private static function default_ttl(): int {
        $settings = Kurl_Helpers::get_settings();
        $timeout  = max(5, Kurl_Helpers::scalar_int($settings['request_timeout'] ?? 15, 15));
        return $timeout * 5 + 60;
    }

    private static function insert(string $option, string $value): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock creation through the unique option_name index.
        return (int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $value)) === 1;
    }

    private static function option_name(string $name): string {
        $name = sanitize_key($name);
        return $name !== '' ? self::PREFIX . $name : '';
    }

    private static function key(string $option): string {
        return self::blog_id() . ':' . $option;
    }

    private static function blog_id(): int {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    }
}
