# Changelog

## [1.0.0] - 2026-05-30

### Added
- Filesystem scan of `wp-content/uploads` using `league/flysystem`
- Database reference detection: attachments, post content, post meta, options
- Third-party plugin scanners: ACF, Yoast SEO, The SEO Framework, SSP
- Admin dashboard with Dashboard, Files, and Operations tabs
- File grouping: thumbnails listed under their original file
- WP-CLI commands: `wp refaxination scan files`, `wp refaxination scan refs`, `wp refaxination report`
- Batch processing support (`--batch=N`) for large sites
- Quarantine/move operations with full audit log in `wp_refaxination_moves`
- AJAX polling for real-time operation status in admin

[1.0.0]: https://github.com/butialabs/refaxination/releases/tag/0.0.1
