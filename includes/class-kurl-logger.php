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
    private const MAX_ENTRIES_AFTER_ROTATION = 250;

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
            'message' => Kurl_Helpers::redact_secrets(sanitize_text_field($message)),
            'context' => self::sanitize_context($context),
        ];

        $line = wp_json_encode($entry);
        if (!is_string($line) || $line === '') {
            return;
        }
        $line .= PHP_EOL;

        // 'c+' opens for reading and writing without truncating, so pruning,
        // rotation, and the append all happen inside one exclusive lock. A
        // concurrent request can no longer append between our read and rewrite.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file access with locking is intentional for the log.
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return;
        }
        if (@flock($handle, LOCK_EX)) {
            self::compact_locked($handle, false);
            @fseek($handle, 0, SEEK_END);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file write with locking is intentional for the log.
            @fwrite($handle, $line);
            @fflush($handle);
            @flock($handle, LOCK_UN);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct log handle.
        @fclose($handle);
    }

    /**
     * Physically remove entries older than the retention window. Runs daily
     * through WP-Cron and before the Logs screen is rendered.
     */
    public static function prune(): void {
        $file = self::get_log_file();
        if ($file === '' || is_link($file) || !file_exists($file)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file access with locking is intentional for the log.
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return;
        }
        if (@flock($handle, LOCK_EX)) {
            self::compact_locked($handle, true);
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
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct log read is intentional here.
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        $content = '';
        if (@flock($handle, LOCK_SH)) {
            $content = self::read_tail_locked($handle);
            @flock($handle, LOCK_UN);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct log handle.
        @fclose($handle);

        $entries = [];
        foreach (self::parse_retained_lines($content) as $line) {
            $entry = json_decode($line, true);
            $entries[] = [
                'time'    => Kurl_Helpers::scalar_int($entry['time'] ?? 0),
                'level'   => self::normalize_level(Kurl_Helpers::scalar_string($entry['level'] ?? 'info', 'info')),
                // Entries written by older versions were not fully redacted.
                'message' => Kurl_Helpers::redact_secrets(sanitize_text_field(Kurl_Helpers::scalar_string($entry['message'] ?? ''))),
                'context' => is_array($entry['context'] ?? null) ? self::redact_deep($entry['context']) : [],
            ];
        }
        return array_reverse($entries);
    }

    /**
     * Rewrite the locked file when it holds expired entries or exceeds the
     * size cap. Cheap when nothing is due: only the first line is read.
     *
     * @param resource $handle Handle opened with 'c+b' and locked with LOCK_EX.
     * @param bool     $force  Always rewrite (used by prune()).
     */
    private static function compact_locked($handle, bool $force): void {
        $stat = @fstat($handle);
        $size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;
        if ($size <= 0) {
            return;
        }

        $too_big = $size > self::MAX_FILE_BYTES;
        if (!$force && !$too_big) {
            @rewind($handle);
            $first = @fgets($handle);
            $first_entry = is_string($first) ? json_decode(trim($first), true) : null;
            $first_time = is_array($first_entry) ? Kurl_Helpers::scalar_int($first_entry['time'] ?? 0) : 0;
            if ($first_time >= time() - self::RETENTION_SECONDS) {
                return;
            }
        }

        $lines = self::parse_retained_lines(self::read_tail_locked($handle));
        if ($too_big) {
            $lines = array_slice($lines, -self::MAX_ENTRIES_AFTER_ROTATION);
        }

        @ftruncate($handle, 0);
        @rewind($handle);
        if (!empty($lines)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file write with locking is intentional for the log.
            @fwrite($handle, implode(PHP_EOL, $lines) . PHP_EOL);
        }
    }

    /**
     * Read at most READ_BYTES from the end of a locked file, starting at a
     * complete line.
     *
     * @param resource $handle Locked file handle.
     */
    private static function read_tail_locked($handle): string {
        $stat = @fstat($handle);
        $size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;
        if ($size <= 0) {
            return '';
        }
        $read = min(self::READ_BYTES, $size);
        @fseek($handle, $size - $read, SEEK_SET);
        $content = stream_get_contents($handle);
        if (!is_string($content) || $content === '') {
            return '';
        }
        if ($size > $read) {
            $newline_pos = strpos($content, "\n");
            $content = $newline_pos !== false ? substr($content, $newline_pos + 1) : '';
        }
        return $content;
    }

    /**
     * JSON lines whose timestamp lies inside the retention window.
     *
     * @return string[]
     */
    private static function parse_retained_lines(string $content): array {
        $cutoff = time() - self::RETENTION_SECONDS;
        $lines  = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            $time = Kurl_Helpers::scalar_int($entry['time'] ?? 0);
            if ($time > 0 && $time >= $cutoff) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * @param array $data Decoded context.
     */
    private static function redact_deep(array $data): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact_deep($value);
            } elseif (is_string($value)) {
                $data[$key] = Kurl_Helpers::redact_secrets($value);
            }
        }
        return $data;
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
                $clean[$key] = self::truncate_string(Kurl_Helpers::redact_secrets(sanitize_text_field((string) $value)));
                continue;
            }
            $json = wp_json_encode($value);
            $clean[$key] = is_string($json) && $json !== ''
                ? self::truncate_string(Kurl_Helpers::redact_secrets(self::redact_serialized_secrets($json)))
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
