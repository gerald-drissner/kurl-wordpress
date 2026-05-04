=== kURL – Short URL Manager for YOURLS ===
Contributors: geralddrissner
Tags: yourls, shortlinks, url shortener, custom links, affiliate links
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.0
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

If your server uses Nginx instead of Apache, add a deny rule for the `wp-content/uploads/kurl/` directory in your Nginx configuration to protect the log folder.

= Features =

* Create short URLs from the WordPress editor.
* Automatically create short URLs when content is published.
* Use custom keywords when creating short URLs.
* Generate short URLs manually for any target URL from the dashboard.
* Refresh and store click statistics from YOURLS.
* View saved links, latest links, top links, and recent YOURLS activity in the dashboard.
* Bulk-generate short URLs for existing content in AJAX batches.
* Import legacy data from the Better YOURLS plugin.
* Optional YOURLS helper extension for remote deletion and safe long-URL lookup.
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

If you want true remote deletion and safe reverse lookup, install the optional kURL Helper extension on your YOURLS server as described on the settings page.

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

== Changelog ==

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

= 1.0.0 =
Initial public release.
