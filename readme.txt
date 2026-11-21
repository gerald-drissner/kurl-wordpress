=== kURL – Short URL Manager for YOURLS ===
Contributors: geralddrissner
Tags: yourls, shortlinks, url shortener, custom links
Requires at least: 6.0
Tested up to: 7.2
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to a self-hosted YOURLS instance to create, sync, manage, and track short URLs from the admin area.

== Description ==

kURL connects your WordPress site to your self-hosted YOURLS installation.

The name kURL combines **kurz URL** — “short URL” in German — with the familiar term URL. In other words, kURL is built for creating and managing short URLs from WordPress.

It lets you create short URLs for posts, pages, and supported custom post types directly from the WordPress admin area. You can generate links manually, create them automatically when content is published, assign custom keywords, refresh click statistics, and process older content in bulk.

kURL also includes migration tools for links created with YOURLS Link Creator, Better YOURLS, and Ozh's YOURLS plugin, local logging for troubleshooting, and optional helper code for true remote deletion and safe reverse lookup on the YOURLS side.

Requires a self-hosted YOURLS installation with API access. No third-party shortening service is included.

= External service and data sent =

kURL communicates only with the self-hosted YOURLS API endpoint configured by a WordPress administrator. Depending on the action, WordPress sends the YOURLS signature token, the target URL, an optional title or keyword, or an existing short URL. These data are used to create, inspect, edit, regenerate, or delete links and to retrieve click statistics. The plugin developer does not operate an intermediary service, receive these requests, or add tracking. Storage and processing on the YOURLS server are controlled by that server's operator and configuration.

kURL stores logs under `wp-content/uploads/kurl-short-url-manager-yourls/` with a non-guessable filename and Apache/IIS deny files. If your server uses Nginx, add a deny rule for that directory because Nginx does not read `.htaccess`.

= Features =

* Create short URLs from the WordPress editor.
* Automatically create short URLs when content is published.
* Use custom keywords when creating short URLs.
* Generate short URLs manually for any target URL from the dashboard.
* Refresh and store click statistics from YOURLS.
* View saved links, latest links, top links, and recent YOURLS activity in the dashboard.
* Bulk-generate short URLs for existing content in AJAX batches.
* Use and import links from YOURLS Link Creator, Better YOURLS, and YOURLS: WordPress to Twitter.
* Show the short URL anywhere with `wp_get_shortlink()`, the `[kurl_shortlink]` shortcode, or the `kurl_get_shortlink()` template tag.
* Optional versioned YOURLS helper extension for remote deletion, safe long-URL lookup, and non-destructive regeneration.
* Activity and error logging for troubleshooting.
* Optional short URL column in WordPress content list screens.
* Experimental sync and cleanup tools.

== Installation ==

1. Upload the `kurl-short-url-manager-yourls` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress admin.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **kURL → Settings** in the WordPress admin menu.
4. Enter your YOURLS domain or API endpoint and your YOURLS signature token.
5. Choose the post types for which kURL should be enabled.
6. Save the settings.
7. Click **Test API** to verify the connection.

For remote deletion, safe reverse lookup, and safe regeneration, use the helper installation section under **kURL → Settings**. The helper is bundled inside the WordPress plugin: download its `plugin.php` directly from that page or copy the displayed code. No separate helper ZIP is required. kURL automatically verifies the installed helper after a plugin update and disables helper-dependent actions until the bundled version and capabilities are detected.

By default, kURL uses WordPress's safe HTTP client, which rejects private and loopback API addresses. If WordPress and YOURLS intentionally communicate over a private network, an administrator can opt in to that endpoint with the `kurl_allow_private_api_url` filter after reviewing the SSRF implications.

== Frequently Asked Questions ==

= What does kURL mean? =

The “k” stands for **kurz**, the German word for “short”. So kURL means “short URL”.

= What do I need to use this plugin? =

You need a self-hosted YOURLS installation with API access enabled and a valid signature token.

= Where do I find my YOURLS signature token? =

Log in to your YOURLS admin area, open **Tools**, and look for the secure passwordless API call section.

= Does kURL work with posts, pages, and custom post types? =

Yes. You can choose which public post types are enabled in **kURL → Settings**.

= Does this plugin replace the normal WordPress shortlink? =

Yes, by default. When a kURL short URL is saved for a post, `wp_get_shortlink()`, the `rel="shortlink"` tag, the `Link` HTTP header, and the admin bar return the YOURLS URL. Posts without a kURL link keep the regular WordPress shortlink. If another plugin already provides a shortlink (or deliberately suppresses it), kURL respects that by default. Under **kURL → Settings → WordPress shortlink** you can let kURL always win, or keep the WordPress shortlink.

= How do I show the short URL in my theme or footer? =

Any of these works:

* `<?php echo esc_url( wp_get_shortlink() ); ?>` – the WordPress function; uses the current post.
* `<?php echo esc_url( kurl_get_shortlink() ); ?>` – returns only a kURL link, or an empty string.
* `<?php kurl_the_shortlink( [ 'link' => 1, 'before' => 'Short URL: ' ] ); ?>` – prints it, optionally as a link.
* `[kurl_shortlink]` – shortcode for widgets, page builders, and block themes.

The shortcode accepts `post_id`, `link`, `text`, `class`, `target`, `rel`, `before`, `after`, and `fallback` (use the WordPress shortlink when no kURL link exists). The shortcode and `kurl_get_shortlink()` use the current post inside a loop and the displayed post on single views; on archives outside a loop they print nothing.

= I am switching from YOURLS Link Creator. Do I lose my links? =

No. kURL reads the links stored by YOURLS Link Creator (`_yourls_url`), Better YOURLS (`_better_yourls_short_link`), and Ozh's YOURLS: WordPress to Twitter (`yourls_shorturl`) as a fallback right away. Use **kURL → Settings → Migration tools → Import legacy links** to copy them into kURL. "Delete legacy plugin data" only removes legacy links that kURL stores identically, so it never deletes the last record of a link. If your theme calls `get_yourls_shortlink()` from YOURLS Link Creator, replace it with `kurl_get_shortlink()` before you deactivate the old plugin.

= Can I create short URLs for URLs that are not WordPress posts? =

Yes. The dashboard includes a manual shortener where you can create or update a short URL for any target URL.

= Can I delete short URLs remotely from WordPress? =

Only if the optional kURL Helper extension is installed on your YOURLS server. Without it, kURL can unlink the short URL locally in WordPress, but it cannot delete the original entry from YOURLS.

= Can I migrate from Better YOURLS? =

Yes. The migration tools import links from Better YOURLS, YOURLS Link Creator, and YOURLS: WordPress to Twitter. Import first, verify a few posts, and only delete the legacy plugin data once everything looks correct. Developers can add further meta keys with the `kurl_legacy_meta_keys` filter.

= The connection test says "A valid URL was not provided." =

kURL uses WordPress's safe HTTP client. It only connects to host names that resolve to a public IP address, on ports 80, 443, or 8080, so a typo in the host name, a YOURLS installation on another port, or a private address all end in this message. Use a public HTTPS address, or opt in with the `kurl_allow_private_api_url` filter after reviewing the SSRF implications.

= What are the experimental sync and cleanup tools? =

They let you compare WordPress content with YOURLS, import matching short URLs into WordPress, and clean stale local meta data. On larger sites, create a database backup before applying reconciliation changes.

== Screenshots ==

1. kURL dashboard with connection status, manual shortener, YOURLS statistics, recent activity, and saved shortlinks.
2. kURL settings page for connecting WordPress to a self-hosted YOURLS installation and selecting supported post types.
3. Bulk generator for creating short URLs in AJAX batches.
4. kURL Shortlink box in the WordPress editor for custom slugs, generation, sync, and statistics refresh.

== Credits ==

French translation: Norbert Jung.

== Changelog ==

= 1.1.0 =
**Security – replace the YOURLS helper.** kURL Helper 1.1.6 requires valid YOURLS authentication (signature token, or username and password) for every action. Helper 1.1.5 and older relied on YOURLS's API access control; on installations with a public API (`YOURLS_PRIVATE` false, or `YOURLS_PRIVATE_API` false while the admin area stays private) anyone could delete links or point existing keywords to other targets. Download the new `plugin.php` under **kURL → Settings**. Until then kURL disables all helper actions.

Shortlinks and output
* Fix: A bare `wp_get_shortlink()` call in a theme template (for example a footer) now returns the kURL short URL. The filter resolves posts exactly like WordPress: `query` uses the displayed post, `post` with ID 0 uses the current post, and other contexts (for example a site ID) are left alone.
* New: Setting **WordPress shortlink** with three modes: use the kURL URL unless another plugin already provides a shortlink (default, also respects a deliberately empty shortlink), always use the kURL URL, or keep the WordPress shortlink. It is read per call (correct per site on multisite) from a small autoloaded option, so front-end requests need no extra query.
* New: `[kurl_shortlink]` shortcode and the template tags `kurl_get_shortlink()` and `kurl_the_shortlink()`.
* New: After user-triggered changes kURL purges the post in WP Rocket (`rocket_clean_post()`) and fires `kurl_shortlink_changed` for other caches.

Safety of changes
* Fix: A confirmed "Unlink locally" could turn into a remote deletion when a background check had found the helper in the meantime. The editor now sends the confirmed scope and the short URL that was shown, for remote and local unlinking alike; the server refuses if either is missing or no longer matches and never widens a local unlink.
* Fix: Unlinking removed only kURL's own field, so a link from YOURLS Link Creator or Better YOURLS reappeared as the post's short URL. Legacy fields holding the same URL are now deleted; legacy fields with a different link are kept for the migration tools but are no longer shown as the post's short URL.
* Fix: Two overlapping actions on the same post (two tabs, an editor action during a bulk run, auto-create during a manual update) could store a link the other action had just deleted. Every action on a post now holds a short database lock; on multisite the lock belongs to the site it was taken on.
* Fix: When the check for other posts using the same short URL fails because of a database error, the action stops with a message instead of treating the URL as unused; bulk runs skip the post.
* Fix: kURL writes the short URL first, verifies that it was actually stored, and only then updates keyword and statistics. If WordPress could not save it, kURL reports the YOURLS link instead of leaving half-updated data.
* Fix: "Disconnect API" saved the settings instead of disconnecting (`submit_button()` sends its label as the value).
* Fix: "Test API" and the background helper check could overwrite settings saved in another tab. The helper status now lives in its own option bound to the saved API URL and signature, so it never touches the settings and is discarded automatically when the credentials change.

Migration from YOURLS Link Creator, Better YOURLS, and Ozh's plugin
* Fix: The migration labelled "Better YOURLS" only read YOURLS Link Creator's `_yourls_url`. kURL now reads and imports `_yourls_url`, `_better_yourls_short_link`, and `yourls_shorturl`, and shows how many posts each source holds.
* Fix: The import counted a write that WordPress had rejected as "imported". It now reports imported, skipped, conflicting (several different legacy links), and failed posts separately, and works in blocks of 500 posts.
* Fix: "Delete legacy plugin data" removed all legacy rows. It now deletes a legacy link only where kURL stores exactly the same URL, keeps everything else, and reports how many posts were kept. Custom keys added for reading (`kurl_legacy_meta_keys`) are only deleted when also added to `kurl_legacy_cleanup_meta_keys`. The Better YOURLS settings are deleted only when no Better YOURLS link is left.
* Fix: If a database query fails during import or cleanup, the migration stops and says so instead of reporting "nothing to migrate" or deleting settings.

Publishing, performance, stability
* Fix: Automatic creation on publish now runs on `wp_after_insert_post`. With the block editor, `transition_post_status` fired before categories were saved, so `%category%` permalinks could be shortened with the wrong term.
* Performance: Local cleanup and migration read only the relevant meta keys in batches instead of loading every meta row of every post (prevents memory exhaustion on page-builder sites).
* Performance: Bulk and reconciliation batches stop after 20 seconds of wall-clock time and continue in the next request, below proxy limits such as Cloudflare's 100 seconds (`kurl_batch_time_budget`).
* Performance: When YOURLS is unreachable or answers with a server error, the dashboard stops after the first request and remembers the failure for two minutes. New "Refresh YOURLS data" button; manual dashboard actions refresh the statistics.
* Fix: Secrets echoed back by YOURLS or a proxy are redacted in all messages and log entries, also on display of older entries.
* Fix: Log entries older than seven days are deleted from disk (daily and when the Logs screen opens). Rotation and appending run under one file lock.
* Fix: On multisite, settings no longer leak between sites after `switch_to_blog()`; uninstall cleans every site on which data deletion is enabled.
* Fix: Deselecting all post types now really disables kURL for all of them; settings saved by earlier versions keep their behavior.
* Fix: A cached click count is removed when a post switches to a different short URL.
* Fix: Shortlink and publish callbacks accept loosely typed arguments from third-party code instead of throwing a TypeError; repeated Content-Type headers no longer turn into "Array".
* Titles are sent to YOURLS as plain text (no `&#8211;` or "Protected:"), URL comparisons ignore scheme and host case, binary comparisons use `CAST(... AS BINARY)` instead of the `BINARY` operator deprecated in MySQL 8.0.27, and text that is not a URL is no longer accepted as one.
* Warns when the API URL uses unencrypted HTTP and explains when WordPress's safe HTTP client rejected the address.
* Admin status colors are scoped to kURL screens; long bulk logs keep the newest 1,000 rows in the browser.
* New filters and actions: `kurl_replace_wp_shortlink`, `kurl_override_existing_shortlink`, `kurl_auto_create_on_publish`, `kurl_legacy_meta_keys`, `kurl_legacy_cleanup_meta_keys`, `kurl_get_shortlink`, `kurl_shortlink_html`, `kurl_batch_time_budget`, `kurl_shortlink_changed`.
* Completed the German translation, switched it consistently to the informal address used by WordPress's de_DE locale, and added missing translator comments.

= 1.0.9 =
* WordPress 7.1 compatibility maintenance; no functional behavior was changed.
* Reviewed against the WordPress 7.1 editor iframe, client-side image processing, and jQuery UI changes; those code paths are not used by the plugin.

= 1.0.8 =
* Performance: Load the large admin and bulk classes only for WordPress admin, AJAX, and admin-post requests.
* Performance: Helper-version verification now runs through protected background AJAX after admin pages render instead of blocking the page-load path.
* Safety: Helper-dependent mutations keep their server-side verification gate; background status changes update the visible controls without reloading or discarding unsaved editor/settings changes.

= 1.0.7 =
* Treats YOURLS `error:url` responses containing a valid existing short URL as a usable result whether YOURLS delivers them with HTTP 200, 400, or 409. This improves compatibility across YOURLS releases and prevents false creation failures when a long URL already exists.
* Completes the bundled German translation for the current administration interface.
* The bundled YOURLS helper remains version 1.1.5; no helper update is required for this WordPress-side API fix.

= 1.0.6 =
* Prevents a saved YOURLS signature from being reused or transmitted when the API endpoint is changed without entering the new endpoint's token.
* Normalizes all API requests to a query-free `yourls-api.php` endpoint so URL parameters cannot override the authenticated POST action or response format.
* Requires the exact bundled helper version and capabilities before helper-dependent actions run; helper 1.1.5 is included in this release.
* Blocks dashboard deletion, regeneration, and in-place updates while a short URL is referenced by WordPress posts.
* Validates target and short URLs as complete HTTP(S) URLs throughout the plugin and rejects ambiguous encoded keywords before remote mutations.
* Makes helper deletion and regeneration check the exact extracted keyword rather than a potentially different full-URL host alias.
* Adds stricter helper URL validation and GPL metadata, retains the complete bounded log window, removes invalid local URL metadata atomically, and guarantees temporary query filters are removed.
* Keeps editor keyword fields synchronized with the actual keyword returned by YOURLS and uses an accurate confirmation message for dashboard-only deletion.
* Documents the administrator-configured YOURLS service and the data sent to it, handles common pasted YOURLS admin/API URLs, preserves a working connection when a malformed replacement endpoint is submitted, validates regeneration and dashboard statistics URLs through the same central HTTP(S) rules, and reports invalid URL records removed by cleanup.
* Makes helper 1.1.5 refuse delete, preferred-lookup, and regeneration requests whose supplied short URL is not recognized by the configured YOURLS installation, preventing an unrelated host with the same final path segment from identifying a destructive target.
* Locks bulk post type, batch size, and overwrite mode while a batch job is running so one job cannot switch data sets or mutation policy between requests.
* Rejects array-shaped request fields and malformed capability lists cleanly on both WordPress and YOURLS, instead of allowing PHP warnings or type errors to corrupt an API response.
* Removes the WordPress site URL from the outbound API user agent, invalidates legacy metadata caches through the WordPress metadata API, and refuses symlinked log paths during cleanup.
* Detects equivalent stored short-URL spellings before mutations, waits for an in-flight AJAX batch to finish before a stopped job can restart, bounds helper verification requests, and prevents cookies or malformed stored/remote values from influencing helper actions.

= 1.0.5 =
* Bundles the YOURLS helper source inside the single WordPress plugin package and serves it from Settings as an installable `plugin.php` file.
* Adds a protected **Download bundled plugin.php** button and retains the copy-to-clipboard option on the Settings page; no separate helper ZIP is needed.
* Automatically rechecks the helper immediately after every kURL update and at least once per day.
* Revalidates the helper within a short window before helper-dependent actions, instead of trusting an old saved detection flag.
* Compares the installed helper version and advertised capabilities with the version bundled in the current WordPress release.
* Disables remote deletion, reverse lookup, regeneration, overwrite, and helper-based reconciliation when the helper is outdated, unversioned, incomplete, or no longer reachable.
* Shows an administrator notice with a direct link to the built-in helper installation/update instructions when an outdated helper is detected.

= 1.0.4 =
* Reworked the YOURLS helper as version 1.1.0. It reports its version and capabilities, uses YOURLS-native keyword and URL handling, preserves case-sensitive base-62 keywords, checks whether a database deletion actually succeeded, and uses official YOURLS lookup functions.
* Replaced delete-then-create regeneration with `yourls_edit_link()`. A failed replacement now leaves the existing short URL intact and preserves its click count.
* Detects outdated or unversioned helpers and always shows the replacement helper code until the required version is installed.
* Tests unsaved API credentials without overwriting a working saved connection. The signature token is no longer rendered into the settings-page HTML.
* Uses WordPress safe HTTP requests by default to reduce SSRF risk. Private-network YOURLS installations can explicitly opt in through the `kurl_allow_private_api_url` filter.
* Improved empty, HTML, and otherwise invalid JSON response diagnostics.
* Verifies a saved short URL before reverse lookup so duplicate long URLs in YOURLS do not silently replace a correct local link.
* Fixed Better YOURLS bulk import mode: legacy links are now distinguished from links already managed by kURL, so the import mode can actually migrate them. Bulk overwrite now uses safe helper-based regeneration.
* Scoped the bulk and reconciliation cursor filters to their own `WP_Query` instances, preventing unrelated nested queries from inheriting the batch cursor.
* Prevented sync and reconciliation from creating replacements or reporting mismatches when verification failed because of a transient API, network, proxy, or authentication error.
* Distinguishes a YOURLS “short URL not found” response from an HTML or empty endpoint 404, so a wrong API path is not treated as a missing link.
* Preserves legacy Better YOURLS links during automatic publish handling, clears the legacy fallback after an explicit unlink, and stores the actual returned keyword for generated links.
* Keeps a successfully created link when YOURLS adjusts a requested keyword, instead of leaving an untracked remote link after reporting a local error.
* Fixed both “Generate / Update” controls so an existing short URL is edited in place through the safe helper instead of calling the create endpoint again.
* Prevents post-specific deletion and overwrite from breaking another WordPress post that references the same short URL; shared links are retained or blocked for review.
* Makes deletion idempotent: a missing remote entry can be unlinked cleanly, and an outdated helper no longer blocks the editor’s explicitly local-unlink fallback. Shared-link checks preserve case-sensitive base-62 keywords even on case-insensitive WordPress database collations.
* Prevents API request parameters from overriding the configured signature, action, or JSON format.
* Corrected the editor keyword field’s malformed readonly attribute and aligned remote deletion with WordPress’s destructive post capability.
* Hardened log storage with a non-guessable filename, removed the old predictable log after migration, redacted API secrets from captured error bodies, added IIS protection, corrected the documented Nginx path, and made the dashboard count match the seven-day retention window.
* Added explicit bundled translation loading, safer endpoint normalization, a bounded API timeout, structured AJAX permission errors, and several smaller consistency checks for enabled post types and API responses.
* Random helper regeneration no longer changes YOURLS's shared next-ID counter, avoiding a counter rollback race between concurrent requests.

= 1.0.3 =
* Fixed remote deletion failing with "Invalid JSON response from YOURLS" when the kURL Helper extension was installed. The helper used a WordPress-only function (`wp_parse_url`) that does not exist on the YOURLS server; it now uses the native `parse_url`. Users who already installed the helper must replace its `plugin.php` file on the YOURLS server; updating the WordPress plugin alone does not update the separate YOURLS installation.
* Corrected the internal version constant, which was still reporting 1.0.0. This fixes the version shown on the dashboard and ensures the admin CSS/JS cache-buster advances with each release.

= 1.0.2 =
* Added bundled starter translations for additional languages and cleaned existing German and Spanish translation files.


= 1.0.1 =
* Updated the bundled French translation. Thanks to Norbert Jung for the contribution.


= 1.0.0 =
* Initial public release.
* Manual and automatic short URL creation.
* Free-form manual shortener for any URL from the dashboard.
* Bulk generation for existing content.
* YOURLS statistics refresh and dashboard overview.
* Latest and top-link dashboard lists with caching.
* Better YOURLS migration tools.
* Optional helper extension for remote deletion and safe reverse lookup.
* Experimental sync, reconcile, and cleanup tools with AJAX batching.
* Bundled starter translations for German, French, and Spanish.

== Upgrade Notice ==

= 1.1.0 =
Security update for the optional YOURLS helper: replace `plugin.php` on your YOURLS server with version 1.1.6 from kURL → Settings. Helper actions stay disabled until then. Also fixes `wp_get_shortlink()` in themes and the migration from YOURLS Link Creator and Better YOURLS.

= 1.0.9 =
WordPress 7.1 compatibility maintenance only. The bundled YOURLS Helper remains version 1.1.5 and does not need to be replaced.

= 1.0.8 =
Admin pages no longer wait for helper verification. The bundled YOURLS Helper remains version 1.1.5, so users already on helper 1.1.5 do not need to replace it.

= 1.0.7 =
Improves compatibility when YOURLS returns an already-existing short URL. The bundled helper remains version 1.1.5 and does not need to be replaced when updating from 1.0.6.

= 1.0.6 =
After updating WordPress, replace the optional YOURLS helper with bundled version 1.1.5 from **kURL → Settings**. Helper-dependent actions remain disabled until that exact helper version and its capabilities are verified.

= 1.0.5 =
The helper remains optional and is included in the WordPress plugin package. If an older helper is detected on YOURLS, kURL blocks helper-dependent actions and directs the administrator to **kURL → Settings**, where the current `plugin.php` can be downloaded or copied.

= 1.0.4 =
Install the bundled kURL Helper 1.1.0 on the YOURLS server after updating WordPress. Unversioned helpers and versions older than 1.0.1 are blocked from remote deletion. Version 1.1.0 is required for non-destructive regeneration and all current safety checks.

= 1.0.3 =
Fixes remote deletion when using the kURL Helper. If you already installed the helper, replace its `plugin.php` file on the YOURLS server as well.

= 1.0.1 =
Updated bundled French translation.


= 1.0.0 =
Initial public release.
