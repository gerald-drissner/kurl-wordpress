<?php

defined('ABSPATH') || exit;

final class Kurl_Logger {

    private const RELATIVE_DIR       = 'kurl-short-url-manager-yourls';
    private const LEGACY_FILE_NAME   = 'kurl.log';
    private const TOKEN_OPTION       = 'kurl_log_token';
    private const RETENTION_SECONDS  = 604800;
    private const MAX_FILE_BYTES     = 1048576;
    private const READ_BYTES         = self::MAX_FILE_BYTES;
    private const MAX_CONTEXT_LENGTH = 500;

    public static function log(string $level, string $message, array $context = []): void {
        $file = self::get_log_file();
        if ($file === '' || is_link($file)) {
            return;
        }

        self::ensure_dir_files();
        if (is_link($file)) {
            return;
        }

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

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file append with locking is intentional for the log.
        $handle = @fopen($file, 'ab');
        if ($handle === false) {
            return;
        }
        if (@flock($handle, LOCK_EX)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file write with locking is intentional for the log.
            @fwrite($handle, $line);
            @fflush($handle);
            @flock($handle, LOCK_UN);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct log handle.
        @fclose($handle);
    }

    public static function get_entries(): array {
        self::ensure_dir_files();
        $file = self::get_log_file();
        if ($file === '' || is_link($file) || !file_exists($file)) {
            return [];
        }
        $size = @filesize($file);
        if (!is_int($size) || $size <= 0) {
            return [];
        }
        $read = min(self::READ_BYTES, $size);
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
            $time = Kurl_Helpers::scalar_int($entry['time'] ?? 0);
            if ($time <= 0 || $time < $cutoff) {
                continue;
            }
            $entries[] = [
                'time'    => $time,
                'level'   => self::normalize_level(Kurl_Helpers::scalar_string($entry['level'] ?? 'info', 'info')),
                'message' => sanitize_text_field(Kurl_Helpers::scalar_string($entry['message'] ?? '')),
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
        $dir = self::get_log_dir();
        $legacy = $dir !== '' ? trailingslashit($dir) . self::LEGACY_FILE_NAME : '';
        if ($legacy !== '' && file_exists($legacy)) {
            wp_delete_file($legacy);
        }
    }

    public static function count_entries(): int {
        return self::count_entries_fast();
    }

    public static function count_entries_fast(): int {
        // The dashboard describes this number as the retained seven-day log.
        // Count parsed retained entries rather than every historical line.
        return count(self::get_entries());
    }

    public static function delete_all_files(): void {
        $dir = self::get_log_dir();
        if ($dir === '' || is_link($dir) || !is_dir($dir)) {
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
            $clean[$key] = is_string($json) && $json !== ''
                ? self::truncate_string(self::redact_serialized_secrets($json))
                : '[unserializable]';
        }
        return $clean;
    }

    private static function get_log_dir(): string {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['basedir']) || !is_string($uploads['basedir'])) {
            return '';
        }
        $dir = trailingslashit($uploads['basedir']) . self::RELATIVE_DIR;
        return is_link($dir) ? '' : $dir;
    }

    private static function get_log_file(): string {
        $dir = self::get_log_dir();
        if ($dir === '') {
            return '';
        }

        $token = Kurl_Helpers::scalar_string(get_option(self::TOKEN_OPTION, ''));
        if (!preg_match('/^[A-Za-z0-9_-]{24,128}$/', $token)) {
            $token = wp_generate_password(48, false, false);
            update_option(self::TOKEN_OPTION, $token, false);
        }

        return trailingslashit($dir) . 'kurl-' . substr(hash('sha256', $token), 0, 24) . '.log';
    }

    private static function ensure_dir_files(): void {
        $dir = self::get_log_dir();
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (is_link($dir) || !is_dir($dir)) {
            return;
        }
        $current_file = self::get_log_file();
        $legacy_file  = trailingslashit($dir) . self::LEGACY_FILE_NAME;
        if ($current_file !== '' && file_exists($legacy_file)) {
            if (!file_exists($current_file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- One-time migration to a non-guessable log filename.
                @rename($legacy_file, $current_file);
            }
            if (file_exists($legacy_file)) {
                // Never leave the old predictable filename reachable, even if
                // migration failed because of filesystem permissions.
                wp_delete_file($legacy_file);
            }
        }

        $index_php = trailingslashit($dir) . 'index.php';
        if (!file_exists($index_php) && !is_link($index_php)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny protection file written once when creating the plugin log directory.
            @file_put_contents($index_php, "<?php\n// Silence is golden.\n");
        }
        $index_html = trailingslashit($dir) . 'index.html';
        if (!file_exists($index_html) && !is_link($index_html)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny protection file written once when creating the plugin log directory.
            @file_put_contents($index_html, '');
        }
        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess) && !is_link($htaccess)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny protection file written once when creating the plugin log directory.
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $web_config = trailingslashit($dir) . 'web.config';
        if (!file_exists($web_config) && !is_link($web_config)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- IIS protection file written once with the log directory.
            @file_put_contents($web_config, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>\n");
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
        @file_put_contents($file, $lines, LOCK_EX);
    }

    private static function redact_serialized_secrets(string $text): string {
        $redacted = preg_replace(
            '~([\"](?:signature|password|passwd|token|secret|authorization|api[_-]?key)[\"]\s*:\s*[\"])[^\"]*([\"])~i',
            '$1[redacted]$2',
            $text
        );
        return is_string($redacted) ? $redacted : $text;
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
