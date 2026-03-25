=== kURL - YOURLS ===
Contributors: geralddrissner
Tags: yourls, shortlinks, url shortener, custom links, affiliate links
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to a self-hosted YOURLS instance to create, sync, manage, and track short URLs from the admin area.

== Description ==

kURL connects your WordPress site to your self-hosted YOURLS installation.

It lets you create short URLs for posts, pages, and supported custom post types directly from the WordPress admin area. You can generate links manually, create them automatically on publish, assign custom keywords, refresh click statistics, and process older content in bulk.

kURL also includes migration tools for older Better YOURLS data, local logging for troubleshooting, and optional helper code for true remote deletion and safe reverse lookup on the YOURLS side.

Requires a self-hosted YOURLS installation with API access. No third-party shortening service is included.

If your server uses Nginx instead of Apache, add a deny rule for the `wp-content/uploads/kurl/` directory in your Nginx configuration to protect the log folder.

= Features =

* Create short URLs from the WordPress editor.
* Automatically create short URLs when content is published.
* Use custom keywords when creating short URLs.
* Refresh and store click statistics from YOURLS.
* Bulk-generate short URLs for existing content in AJAX batches.
* Import legacy data from the Better YOURLS plugin.
* Optional YOURLS helper extension for remote deletion and safe long-URL lookup.
* Activity and error logging for troubleshooting.
* Optional short URL column in WordPress content list screens.
* Experimental sync and cleanup tools.

== Installation ==

1. Upload the `kurl` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress admin.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **kURL → Settings** in the WordPress admin menu.
4. Enter your YOURLS domain or API endpoint and your YOURLS signature token.
5. Save the settings.
6. Click **Test API** to verify the connection.

If you want true remote deletion and safe reverse lookup, install the optional kURL Helper extension on your YOURLS server as described on the settings page.

== Frequently Asked Questions ==

= What do I need to use this plugin? =

You need a self-hosted YOURLS installation with API access enabled and a valid signature token.

= Where do I find my YOURLS signature token? =

Log in to your YOURLS admin area, open **Tools**, and look for the secure passwordless API call section.

= Does kURL work with posts, pages, and custom post types? =

Yes. You can choose which public post types are enabled in **kURL → Settings**.

= Does this plugin replace the normal WordPress shortlink? =

Yes. When a kURL short URL is saved for a post, the plugin filters WordPress shortlink output so the saved YOURLS URL can be used as the WordPress shortlink.

= Can I delete short URLs remotely from WordPress? =

Only if the optional kURL Helper extension is installed on your YOURLS server. Without it, kURL can unlink the short URL locally in WordPress, but it cannot delete the original entry from YOURLS.

= What are the experimental sync and cleanup tools? =

They let you compare WordPress content with YOURLS, import matching short URLs into WordPress, and clean stale local meta data. On larger sites, create a database backup before applying reconciliation changes.

== Screenshots ==

1. The kURL dashboard with saved-link and click statistics.
2. The editor meta box for creating and managing short URLs.
3. The bulk generator for processing older content.
4. The settings screen with API connection test and migration tools.
5. The experimental sync and cleanup screen.

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
