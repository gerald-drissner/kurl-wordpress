#!/bin/bash
# Runs the integration suite on fresh SQLite-backed WordPress sites.
# Paths can be overridden with environment variables (see README.md).
cd "$(dirname "$0")"
WP="php ${WP_CLI_PHAR:-/home/claude/ref/wp-cli.phar} --allow-root"
SITES="${KURL_TEST_SITES:-/home/claude/wptest}"
NEW="${KURL_PLUGIN_DIR:-$(cd .. && pwd)}"
[ -f "$NEW/kurl.php" ] || NEW=/home/claude/build/kurl-short-url-manager-yourls
SPECS="wp72-new:${WP_SRC_72:-/home/claude/ref/wpmaster}:$NEW wp71-new:${WP_SRC_71:-/home/claude/ref/wp71}:$NEW"
# Optional baseline: the unchanged 1.0.9 shows which bugs 1.1.0 fixes (its failures are expected).
[ -n "${KURL_BASELINE_DIR:-}" ] && SPECS="$SPECS wp72-old:${WP_SRC_72:-/home/claude/ref/wpmaster}:$KURL_BASELINE_DIR"
for spec in $SPECS; do
  IFS=: read name src plugin <<< "$spec"
  ./setup.sh "$name" "$src" "$plugin" >/dev/null 2>&1 || { echo "setup failed: $name"; continue; }
  echo "=================== $name"
  $WP --path="$SITES/$name" eval-file run.php 2>&1 | grep -v "^  PASS"
  [ -s "$SITES/$name/debug.log" ] && { echo "--- debug.log"; grep -i "kurl" "$SITES/$name/debug.log" | head -10; }
done
