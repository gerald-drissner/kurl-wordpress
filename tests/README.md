# kURL test suite

These tests are for development only. `.distignore` keeps them out of the
plugin package on WordPress.org.

## What is covered

| File | What it checks |
| --- | --- |
| `run.php` | Integration suite (142 checks) inside a real WordPress: `wp_get_shortlink()` contexts and modes, shortcode and template tags, auto-create on `wp_after_insert_post`, unlink/delete safety, locks, migration from YOURLS Link Creator / Better YOURLS / Ozh, legacy cleanup, database-error paths, logger, dashboard back-off, uninstall. |
| `kurl-mock.php` | MU plugin that answers all YOURLS API calls through `pre_http_request` (modes: `ok`, `down`, `http503`, `no-helper`, `echo-secret`, `array-content-type`). |
| `multisite-lock.php` | Per-site locks on multisite (`switch_to_blog()`, release in the right table, stale-lock takeover). |
| `helper-test.php` | Authentication gate of the YOURLS helper in private, public and public-API mode. |
| `footer-markus.php` + `router.php` | End-to-end check over real HTTP: a theme footer calling `echo wp_get_shortlink()`. |

## Requirements

* PHP 7.4 or newer with `pdo_sqlite`
* [WP-CLI](https://wp-cli.org/) as a phar
* WordPress sources (for example tags `7.1.5` and `7.2` of https://github.com/WordPress/WordPress)
* https://github.com/WordPress/sqlite-database-integration (no MySQL needed)

## Run

```bash
export WP_CLI_PHAR=/path/to/wp-cli.phar
export SQLITE_REPO=/path/to/sqlite-database-integration
export WP_SRC_72=/path/to/wordpress-7.2
export WP_SRC_71=/path/to/wordpress-7.1.5
export KURL_TEST_SITES=/tmp/kurl-sites      # throw-away test sites
./tests/runall.sh                          # tests the plugin in the parent folder
```

Optional: `KURL_BASELINE_DIR=/path/to/kurl-1.0.9` adds a run against the old
version. Its failures are expected and show the bugs that 1.1.0 fixes.

Helper:

```bash
for s in private public public-api; do php tests/helper-test.php . $s; done
```

In `private` mode YOURLS itself requires login before any API action runs, so
the helper accepts the request; in `public` and `public-api` mode the helper
must reject anonymous calls and wrong tokens.

Multisite (needs a multisite test site with two sites):

```bash
php "$WP_CLI_PHAR" --allow-root --path=/path/to/multisite eval-file tests/multisite-lock.php
```
