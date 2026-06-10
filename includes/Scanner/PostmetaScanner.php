<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

class PostmetaScanner implements ScannerInterface
{
    public function getSourceType(): SourceType
    {
        return SourceType::Postmeta;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function scan(array $fileBatch): int
    {
        global $wpdb;

        if ($fileBatch === []) {
            return 0;
        }

        $inserted = 0;

        $attachmentIds = [];
        $pathIndex     = [];

        foreach ($fileBatch as $file) {
            if (! empty($file['attachment_id'])) {
                $attachmentIds[(int) $file['attachment_id']] = (int) $file['id'];
            }
            $pathIndex[$file['relative_path']] = (int) $file['id'];
        }

        if ($attachmentIds !== []) {
            $inserted += $this->scanFeaturedImages($attachmentIds);
        }

        if ($attachmentIds !== []) {
            $inserted += $this->scanNumericMetaIds($attachmentIds);
        }

        $inserted += $this->scanUrlMeta($pathIndex);

        return $inserted;
    }

    private function scanFeaturedImages(array $attachmentIds): int
    {
        global $wpdb;

        $inserted  = 0;

        $placeholders = implode(',', array_fill(0, count($attachmentIds), '%d'));
        $ids          = array_keys($attachmentIds);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value AS attachment_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = '_thumbnail_id'
                   AND meta_value IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        foreach ($rows as $row) {
            $attId  = (int) $row['attachment_id'];
            $fileId = $attachmentIds[$attId] ?? null;

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i (file_id, source_type, source_id, meta_key, context) VALUES (%d, %s, %d, %s, %s)",
                Database::refsTable(),
                $fileId,
                SourceType::Postmeta->value,
                (int) $row['post_id'],
                '_thumbnail_id',
                'featured_image',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            $inserted += (int) ($result !== false);
        }

        return $inserted;
    }

    private function scanNumericMetaIds(array $attachmentIds): int
    {
        global $wpdb;

        $inserted     = 0;
        $placeholders = implode(',', array_fill(0, count($attachmentIds), '%d'));
        $ids          = array_keys($attachmentIds);

        $actionLike = $wpdb->esc_like( '_' ) . '%action%' . $wpdb->esc_like( '_' );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key, CAST(meta_value AS UNSIGNED) AS attachment_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key NOT IN ('_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata',
                                        '_wp_old_slug', '_edit_lock', '_edit_last')
                   AND meta_key NOT LIKE %s
                   AND CAST(meta_value AS UNSIGNED) IN ({$placeholders})
                   AND meta_value REGEXP '^[0-9]+$'",
                $actionLike,
                ...$ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        foreach ($rows as $row) {
            $attId  = (int) $row['attachment_id'];
            $fileId = $attachmentIds[$attId] ?? null;

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i (file_id, source_type, source_id, meta_key, context) VALUES (%d, %s, %d, %s, %s)",
                Database::refsTable(),
                $fileId,
                SourceType::Postmeta->value,
                (int) $row['post_id'],
                $row['meta_key'],
                'attachment_id_meta',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            $inserted += (int) ($result !== false);
        }

        return $inserted;
    }

    private function scanUrlMeta(array $pathIndex): int
    {
        global $wpdb;

        if ($pathIndex === []) {
            return 0;
        }

        $upload      = wp_upload_dir();
        $uploadsPath = rtrim(wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/') . '/';
        $inserted    = 0;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $maxId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(post_id) FROM {$wpdb->postmeta}
                 WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like($uploadsPath) . '%',
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if (! $maxId) {
            return 0;
        }

        $chunkSize = 500;

        for ($offset = 0; $offset <= $maxId; $offset += $chunkSize) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value
                     FROM {$wpdb->postmeta}
                     WHERE post_id BETWEEN %d AND %d
                       AND meta_value LIKE %s
                       AND meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_old_slug')",
                    $offset,
                    $offset + $chunkSize - 1,
                    '%' . $wpdb->esc_like($uploadsPath) . '%',
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            foreach ($rows as $row) {
                $paths = $this->extractPathsFromValue($row['meta_value'], $uploadsPath);

                foreach ($paths as $relativePath) {
                    if (! isset($pathIndex[$relativePath])) {
                        continue;
                    }

                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO %i (file_id, source_type, source_id, meta_key, context) VALUES (%d, %s, %d, %s, %s)",
                        Database::refsTable(),
                        $pathIndex[$relativePath],
                        SourceType::Postmeta->value,
                        (int) $row['post_id'],
                        $row['meta_key'],
                        'url_in_meta',
                    ));
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $inserted++;
                }

                unset($row);
            }
        }

        return $inserted;
    }

    private function extractPathsFromValue(string $value, string $uploadsPath): array
    {
        $paths = [];

        // Match any scheme+host combination followed by the uploads path
        preg_match_all(
            '~https?://[^/\s\'"<>]+' . preg_quote($uploadsPath, '~') . '([^\s\'"<>\)]+)~i',
            $value,
            $matches
        );

        foreach ($matches[1] as $match) {
            $paths[] = trim($match, '/');
        }

        return array_unique($paths);
    }
}
