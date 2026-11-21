# kURL – Short URL Manager for YOURLS

Connect WordPress to your self-hosted [YOURLS](https://yourls.org/) instance and create, sync, manage and track short URLs from the WordPress admin.

* WordPress.org: https://wordpress.org/plugins/kurl-short-url-manager-yourls/
* Current version: **1.1.0** (requires WordPress 6.0+, PHP 7.4+, tested up to WordPress 7.2)
* License: GPLv2 or later

## Features

* Short URL box in the post editor: create, copy, regenerate, sync, unlink locally or delete remotely
* Automatic creation on publish for the post types you choose
* `wp_get_shortlink()`, the `<link rel="shortlink">` tag and the admin bar use the YOURLS URL
* `[kurl_shortlink]` shortcode and the template tags `kurl_get_shortlink()` / `kurl_the_shortlink()`
* Dashboard with click statistics, bulk creation, reconciliation and a log
* Migration from YOURLS Link Creator, Better YOURLS and Ozh's YOURLS plugin
* Optional YOURLS-side helper (`yourls-helper/`) for remote deletion, safe reverse lookup, regeneration and overwrite

## What's new in 1.1.0

### ⚠️ Replace the YOURLS helper

kURL Helper **1.1.6** fixes a security issue. Helper 1.1.5 and older relied on YOURLS's API access control. On YOURLS installations with a public API (`YOURLS_PRIVATE` false, or `YOURLS_PRIVATE_API` false while the admin area stays private) anyone could delete links or point existing keywords to other targets. The new helper requires valid YOURLS authentication for every action.

1. In WordPress open **kURL → Settings** and download the bundled `plugin.php` (or copy the code shown there).
2. Replace `user/plugins/kurl-api/plugin.php` on your YOURLS server.
3. Click **Test API**. Until the new helper is installed, kURL disables every helper action.

Installations without the helper only need the WordPress update.

### Theme templates and the WordPress shortlink

A bare `echo wp_get_shortlink();` in a theme footer or header now prints the YOURLS URL, exactly like YOURLS Link Creator did. Links stored by YOURLS Link Creator (`_yourls_url`), Better YOURLS and Ozh's plugin work immediately, without migration.

The new setting **WordPress shortlink** offers three modes:

| Mode | Behaviour |
| --- | --- |
| Use kURL unless another plugin provides one (default) | Respects shortlinks set by other plugins, including a deliberately empty one |
| Always use kURL | Overrides other shortlink plugins |
| Keep the WordPress shortlink | kURL does not touch `wp_get_shortlink()` |

In a theme:

```php
<?php kurl_the_shortlink( [ 'link' => true, 'before' => 'Short URL: ' ] ); ?>
```

In content: `[kurl_shortlink link="1" target="_blank"]` (attributes: `post_id`, `link`, `text`, `class`, `target`, `rel`, `before`, `after`, `fallback`).

### Safer changes

* An unlink confirmed as local can no longer turn into a remote deletion.
* Overlapping actions on the same post are serialized with a short database lock (per site on multisite).
* If kURL cannot check whether other posts use a short URL, the action stops instead of guessing.
* The short URL is written and verified before keyword and statistics change.
* The helper status is stored separately from the settings, so a connection test can no longer overwrite settings saved in another tab.

### Migration

* Reads and imports `_yourls_url`, `_better_yourls_short_link` and `yourls_shorturl`; reports imported, skipped, conflicting and failed posts.
* "Delete legacy plugin data" deletes a legacy link only where kURL stores the identical URL.
* A database error stops the migration instead of reporting "nothing to migrate".

### Performance and stability

* Auto-create runs on `wp_after_insert_post`, so `%category%` permalinks are shortened with the right category.
* Migration and cleanup work in batches of 500 posts; bulk runs stop after 20 seconds and continue in the next request.
* The dashboard backs off for two minutes when YOURLS is down.
* Secrets are redacted in messages and logs; logs older than seven days are deleted.

The full list is in the changelog in [`readme.txt`](readme.txt).

### Developer hooks

Filters: `kurl_replace_wp_shortlink`, `kurl_override_existing_shortlink`, `kurl_auto_create_on_publish`, `kurl_legacy_meta_keys`, `kurl_legacy_cleanup_meta_keys`, `kurl_get_shortlink`, `kurl_shortlink_html`, `kurl_batch_time_budget`.
Action: `kurl_shortlink_changed( $post_id )`.

## Installation

Install from WordPress.org (**Plugins → Add New → "kURL"**), or upload the ZIP from the [Releases](../../releases) page. Then open **kURL → Settings** and enter your YOURLS API URL (`https://your-domain/yourls-api.php`) and signature token.

## Development

The tests in [`tests/`](tests/) run against real WordPress installations on SQLite; see [`tests/README.md`](tests/README.md). They are excluded from the WordPress.org package by `.distignore`.

## Releasing

Pushing a tag `X.Y.Z` deploys to WordPress.org (`.github/workflows/deploy-wordpress.yml`):

1. Set the same version in `kurl.php` (plugin header **and** `KURL_VERSION`) and in `readme.txt` (`Stable tag`), and add the changelog.
2. Merge into `main`.
3. Tag the merge commit and push the tag:

   ```bash
   git checkout main && git pull
   git tag 1.1.0
   git push origin 1.1.0
   ```

The workflow refuses to deploy if the tag and the three version numbers differ. It then copies the plugin (without the files in `.distignore`) to SVN `trunk` and `tags/X.Y.Z`, updates the assets from `.wordpress-org/`, and attaches the ZIP to a GitHub release with the changelog as release notes.

`wp-assets.yml` only publishes readme and asset changes when the `Stable tag` already exists on WordPress.org. A new readme therefore never goes live before its code.

Repository secrets: `SVN_USERNAME`, `SVN_PASSWORD` (WordPress.org account with commit access).
