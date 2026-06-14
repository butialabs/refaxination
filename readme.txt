=== Refaxination ===
Contributors: butialabs
Tags: media, files, uploads, orphaned, audit
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audits and manages orphaned files in the Uploads folder. All operations via WP-CLI.

== Description ==

Refaxination scans `wp-content/uploads` and cross-references every file against the database.
Attachments, post content, post meta, options, and third-party plugins, to identify files that are no longer referenced anywhere.

**Features:**

* Full filesystem scan of wp-content/uploads
* Database reference detection across:
  * WordPress attachments and post content
  * Post meta fields
  * WordPress options
  * ACF (Advanced Custom Fields)
  * Yoast SEO
  * The SEO Framework
* Safe quarantine/move operations with full audit log
* Admin dashboard with file and operation history
* All heavy operations run via WP-CLI to avoid timeouts

**This plugin does not auto-delete anything.** Every file operation is logged and reversible.

== Installation ==

1. Upload the `refaxination` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Use the **Tools → Refaxination** menu to view the dashboard
4. Run scans via WP-CLI (see below)

**Requirements:**

* PHP 8.1 or higher
* WordPress 6.0 or higher
* WP-CLI (for scan operations)

== WP-CLI Commands ==

Scan the filesystem for files:

`wp refaxination scan files`

Scan the database for references:

`wp refaxination scan refs`

Generate a report:

`wp refaxination report`

Run with `--batch=100` to process in batches (recommended for large sites):

`wp refaxination scan files --batch=100`

== Frequently Asked Questions ==

= Does this plugin delete files automatically? =

No. Refaxination only reads and reports. File moves/quarantines are initiated manually through the admin dashboard or CLI.

= Is it safe to run on a live site? =

Yes. Scans are read-only. No changes are made to files or the database during a scan.

= What happens to moved files? =

All file moves are logged in the `wp_refaxination_moves` table, which is preserved even after uninstall (unless you explicitly opt out).

= Can I reverse a quarantine operation? =

The audit log in the database records the original path of every moved file, so files can be manually restored.

== Changelog ==

= 1.0.0 =
* Initial release
* Filesystem and database scanning
* Admin dashboard with tabs for Dashboard, Files, and Operations
* WP-CLI integration for scan and report commands
* Support for ACF, Yoast SEO, and The SEO Framework
* Quarantine manager with full audit log
