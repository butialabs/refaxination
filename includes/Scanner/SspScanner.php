<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

/**
 * Seriously Simple Podcasting scanner.
 * Scans audio_file (full URL) and enclosure (multi-line: URL\nfilesize\nmimetype).
 */
class SspScanner implements ScannerInterface
{
    public function getSourceType(): SourceType
    {
        return SourceType::Ssp;
    }

    public function isAvailable(): bool
    {
        return is_plugin_active('seriously-simple-podcasting/seriously-simple-podcasting.php')
            || defined('SSP_VERSION');
    }

    public function scan(array $fileBatch): int
    {
        global $wpdb;

        if ($fileBatch === []) {
            return 0;
        }

        $upload      = wp_upload_dir();
        $uploadsPath = rtrim(wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/') . '/';
        $pathIndex = [];
        foreach ($fileBatch as $file) {
            $pathIndex[$file['relative_path']] = (int) $file['id'];
        }

        $inserted = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('audio_file', 'enclosure')
                   AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($uploadsPath) . '%',
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $rawValue = $row['meta_value'];

            // enclosure meta is multi-line: URL\nfilesize\nmimetype
            $url = $row['meta_key'] === 'enclosure'
                ? trim(explode("\n", $rawValue)[0])
                : trim($rawValue);

            $urlPath = wp_parse_url($url, PHP_URL_PATH) ?? '';

            if ($urlPath === '' || ! str_contains($urlPath, $uploadsPath)) {
                continue;
            }

            $relativePath = trim(str_replace($uploadsPath, '', $urlPath), '/');

            if (! isset($pathIndex[$relativePath])) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                Database::refsTable(),
                $pathIndex[$relativePath],
                SourceType::Ssp->value,
                (int) $row['post_id'],
                $row['meta_key'],
                'ssp_audio',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $inserted++;
        }

        return $inserted;
    }
}
