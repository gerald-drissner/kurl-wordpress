<?php

defined('ABSPATH') || exit;

final class Kurl_Logger {

    private const RELATIVE_DIR       = 'kurl-yourls';
    private const FILE_NAME          = 'kurl.log';
    private const RETENTION_SECONDS  = 604800;
    private const MAX_FILE_BYTES     = 1048576;
    private const READ_BYTES         = 262144;
    private const MAX_CONTEXT_LENGTH = 500;

    public static function log(string $level, string $message, array $context = []): void {
        $file = self::get_log_file();
        if ($file === '') {
            return;
        }

        self::ensure_dir_files();

        $entry = [
            'time'    => time(),
            'level'   => self::normalize_level($level),
            'message' => sanitize_text_field($message),
            'context' => self::sanitize_context($context),
        ];

        $line = wp_json_encode($entry);
        if (!is_string($line) || $line === '') {
            return;
        }
        $line .= PHP_EOL;

        if (file_exists($file)) {
            $size = @filesize($file);
            if (is_int($size) && $size > self::MAX_FILE_BYTES) {
                self::rotate_if_needed($file);
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file handle required for append mode and file locking.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file append with locking is intentional for the log.
        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            return;
        }
        if (@flock($handle, LOCK_EX)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file handle required for append mode and file locking.
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file write with locking is intentional for the log.
            @fwrite($handle, $line);
            @fflush($handle);
            @flock($handle, LOCK_UN);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file handle required for append mode and file locking.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct log handle.
        @fclose($handle);
    }

    public static function get_entries(): array {
        $file = self::get_log_file();
        if ($file === '' || !file_exists($file)) {
            return [];
        }
        $size = @filesize($file);
        if (!is_int($size) || $size <= 0) {
            return [];
        }
        $read = min(self::READ_BYTES, $size);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file handle required for efficient sequential reads.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct log read is intentional here.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct log read is intentional here.
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($size > $read) {
            @fseek($handle, -$read, SEEK_END);
        }
        $content = stream_get_contents($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file handle required for append mode and file locking.
        @fclose($handle);
        if (!is_string($content) || $content === '') {
            return [];
        }
        if ($size > $read) {
            $newline_pos = strpos($content, PHP_EOL);
            if ($newline_pos !== false) {
                $content = substr($content, $newline_pos + strlen(PHP_EOL));
            }
        }
        $cutoff = time() - self::RETENTION_SECONDS;
        $entries = [];
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            $time = isset($entry['time']) ? (int) $entry['time'] : 0;
            if ($time <= 0 || $time < $cutoff) {
                continue;
            }
            $entries[] = [
                'time'    => $time,
                'level'   => self::normalize_level((string) ($entry['level'] ?? 'info')),
                'message' => sanitize_text_field((string) ($entry['message'] ?? '')),
                'context' => is_array($entry['context'] ?? null) ? $entry['context'] : [],
            ];
        }
        return array_reverse($entries);
    }

    public static function clear(): void {
        $file = self::get_log_file();
        if ($file !== '' && file_exists($file)) {
            wp_delete_file($file);
        }
    }

    public static function count_entries(): int {
        return self::count_entries_fast();
    }

    public static function count_entries_fast(): int {
        $file = self::get_log_file();
        if ($file === '' || !file_exists($file)) {
            return 0;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file handle required for efficient sequential reads.
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file handle required for append mode and file locking.
        @fclose($handle);

        return $count;
    }

    public static function delete_all_files(): void {
        $dir = self::get_log_dir();
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = trailingslashit($dir) . $item;
            if (is_file($path) || is_link($path)) {
                wp_delete_file($path);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's dedicated empty log directory during cleanup.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Logger directory cleanup.
        @rmdir($dir);
    }

    private static function sanitize_context(array $context): array {
        $clean = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            if (self::is_sensitive_key($key)) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = self::truncate_string(sanitize_text_field((string) $value));
                continue;
            }
            $json = wp_json_encode($value);
            $clean[$key] = is_string($json) && $json !== '' ? self::truncate_string($json) : '[unserializable]';
        }
        return $clean;
    }

    private static function get_log_dir(): string {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir']) || !is_string($uploads['basedir'])) {
            return '';
        }
        return trailingslashit($uploads['basedir']) . self::RELATIVE_DIR;
    }

    private static function get_log_file(): string {
        $dir = self::get_log_dir();
        return $dir === '' ? '' : trailingslashit($dir) . self::FILE_NAME;
    }

    private static function ensure_dir_files(): void {
        $dir = self::get_log_dir();
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir)) {
            return;
        }
        $index_php = trailingslashit($dir) . 'index.php';
        if (!file_exists($index_php)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny protection file written once when creating the plugin log directory.
            @file_put_contents($index_php, "<?php\n// Silence is golden.\n");
        }
        $index_html = trailingslashit($dir) . 'index.html';
        if (!file_exists($index_html)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny protection file written once when creating the plugin log directory.
            @file_put_contents($index_html, '');
        }
        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny protection file written once when creating the plugin log directory.
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    }

    private static function rotate_if_needed(string $file): void {
        $entries = self::get_entries();
        if (empty($entries)) {
            wp_delete_file($file);
            return;
        }
        $entries = array_reverse(array_slice($entries, 0, 250));
        $lines = '';
        foreach ($entries as $entry) {
            $json = wp_json_encode($entry);
            if (is_string($json) && $json !== '') {
                $lines .= $json . PHP_EOL;
            }
        }
        if ($lines === '') {
            wp_delete_file($file);
            return;
        }
        @file_put_contents($file, $lines);
    }

    private static function normalize_level(string $level): string {
        $level = sanitize_key($level);
        if (!in_array($level, ['debug', 'info', 'warning', 'error'], true)) {
            $level = 'info';
        }
        return $level;
    }

    private static function is_sensitive_key(string $key): bool {
        if ($key === 'signature') {
            return true;
        }
        foreach (['token', 'secret', 'password', 'passwd', 'auth', 'authorization', 'cookie', 'apikey', 'api_key'] as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function truncate_string(string $value): string {
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) <= self::MAX_CONTEXT_LENGTH) {
                return $value;
            }
            return mb_substr($value, 0, self::MAX_CONTEXT_LENGTH) . '…';
        }
        if (strlen($value) <= self::MAX_CONTEXT_LENGTH) {
            return $value;
        }
        return substr($value, 0, self::MAX_CONTEXT_LENGTH) . '…';
    }
}
