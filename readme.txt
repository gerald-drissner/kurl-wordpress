=== kURL – Short URL Manager for YOURLS ===
Contributors: geralddrissner
Tags: yourls, shortlinks, url shortener, custom links
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to a self-hosted YOURLS instance to create, sync, manage, and track short URLs from the admin area.

== Description ==

kURL connects your WordPress site to your self-hosted YOURLS installation.

The name kURL combines **kurz URL** — “short URL” in German — with the familiar term URL. In other words, kURL is built for creating and managing short URLs from WordPress.

It lets you create short URLs for posts, pages, and supported custom post types directly from the WordPress admin area. You can generate links manually, create them automatically when content is published, assign custom keywords, refresh click statistics, and process older content in bulk.

kURL also includes migration tools for older Better YOURLS data, local logging for troubleshooting, and optional helper code for true remote deletion and safe reverse lookup on the YOURLS side.

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
* Import legacy data from the Better YOURLS plugin.
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

Yes. When a kURL short URL is saved for a post, the plugin filters WordPress shortlink output so the saved YOURLS URL can be used as the WordPress shortlink.

= Can I create short URLs for URLs that are not WordPress posts? =

Yes. The dashboard includes a manual shortener where you can create or update a short URL for any target URL.

= Can I delete short URLs remotely from WordPress? =

Only if the optional kURL Helper extension is installed on your YOURLS server. Without it, kURL can unlink the short URL locally in WordPress, but it cannot delete the original entry from YOURLS.

= Can I migrate from Better YOURLS? =

Yes. kURL includes migration tools for importing existing Better YOURLS data. Import first, verify a few posts, and only delete the old Better YOURLS data once everything looks correct.

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
