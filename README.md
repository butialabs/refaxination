# Refaxination

Audits and manages orphaned files in the WordPress Uploads folder.

## Description

Refaxination scans `wp-content/uploads` and cross-references every file against the database.
Attachments, post content, post meta, options, and third-party plugins, to identify files that are no longer referenced anywhere.

**All operations run via WP-CLI** to avoid HTTP timeouts on large sites.
The admin dashboard provides a read-only view of scan results and operation history.

## Requirements

- PHP 8.1+
- WordPress 6.0+ or ClassicPress
- WP-CLI (for scan and report commands)

## WP-CLI Commands

```bash
# Scan the filesystem
wp refaxination scan files
wp refaxination scan files --batch=100

# Scan the database for references
wp refaxination scan refs
wp refaxination scan refs --batch=100

# Generate a report
wp refaxination report
wp refaxination report --format=json
wp refaxination report --format=csv
```

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_refaxination_files` | Indexed file entries with status and MIME type |
| `wp_refaxination_references` | Cross-reference tracking (file ↔ source) |
| `wp_refaxination_operations` | Operation history and progress |
| `wp_refaxination_moves` | Audit log of all file moves (preserved on uninstall) |

## Scanners

- WordPress Attachments
- Post Content
- Post Meta
- WordPress Options
- ACF (Advanced Custom Fields)
- Yoast SEO
- The SEO Framework
- SSP (Seriously Simple Podcasting)