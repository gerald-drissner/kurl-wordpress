#!/bin/bash
# Usage: setup.sh <name> <wordpress-source-dir> <kurl-plugin-dir>
set -e
NAME="$1"; SRC="$2"; PLUGIN="$3"
HERE="$(cd "$(dirname "$0")" && pwd)"
BASE="${KURL_TEST_SITES:-/home/claude/wptest}"
D="$BASE/$NAME"
WP="php ${WP_CLI_PHAR:-/home/claude/ref/wp-cli.phar} --allow-root --path=$D"
SQLITE_REPO="${SQLITE_REPO:-/home/claude/ref/sqlite-int}"
SQLITE="$SQLITE_REPO/packages/plugin-sqlite-database-integration"

rm -rf "$D"; mkdir -p "$BASE"
cp -a "$SRC" "$D"; rm -rf "$D/.git"
cp -a "$SQLITE" "$D/wp-content/plugins/sqlite-database-integration"
rm "$D/wp-content/plugins/sqlite-database-integration/wp-includes/database"
cp -a "$SQLITE_REPO/packages/mysql-on-sqlite/src" "$D/wp-content/plugins/sqlite-database-integration/wp-includes/database"
cp "$SQLITE/db.copy" "$D/wp-content/db.php"
sed -i "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" "$D/wp-content/db.php"
sed -i "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$D/wp-content/db.php"

cat > "$D/wp-config.php" <<'EOF'
<?php
define('DB_NAME', 'wp'); define('DB_USER', 'wp'); define('DB_PASSWORD', 'wp'); define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('DB_DIR', __DIR__ . '/wp-content/database/'); define('DB_FILE', 'test.sqlite');
$table_prefix = 'wp_';
define('WP_DEBUG', true); define('WP_DEBUG_LOG', __DIR__ . '/debug.log'); define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
EOF

$WP core install --url=http://kurl.test --title=Test --admin_user=admin --admin_password=secret --admin_email=admin@kurl.test --skip-email >/dev/null
$WP rewrite structure '/%category%/%postname%/' >/dev/null
mkdir -p "$D/wp-content/mu-plugins"
cp "$HERE/kurl-mock.php" "$D/wp-content/mu-plugins/"
cp -a "$PLUGIN" "$D/wp-content/plugins/kurl-short-url-manager-yourls"
$WP plugin activate kurl-short-url-manager-yourls >/dev/null
$WP option update kurl_settings '{"api_url":"https://sho.rt/yourls-api.php","signature":"abc123def4","enabled_post_types":["post","page"],"cache_minutes":30,"request_timeout":15,"auto_create_on_publish":1}' --format=json >/dev/null
echo "ready: $D ($($WP core version))"
