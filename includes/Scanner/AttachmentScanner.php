<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

/**
 * Vincula arquivos a posts do tipo attachment via _wp_attached_file.
 * Deve ser o PRIMEIRO scanner a rodar pois popula attachment_id em refaxination_files.
 */
class AttachmentScanner implements ScannerInterface
{
    public function getSourceType(): SourceType
    {
        return SourceType::Attachment;
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

        $filesTable = esc_sql( Database::filesTable() );
        $refsTable  = esc_sql( Database::refsTable() );
        $inserted   = 0;

        // Build an index of relative_path => file_id from the batch
        $pathIndex = [];
        foreach ($fileBatch as $file) {
            $pathIndex[$file['relative_path']] = (int) $file['id'];
        }

        $paths = array_keys($pathIndex);

        // Fetch matching attachments via _wp_attached_file postmeta
        $placeholders = implode(',', array_fill(0, count($paths), '%s'));
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID AS attachment_id, pm.meta_value AS file_path
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
                 WHERE p.post_type = 'attachment'
                   AND pm.meta_value IN ({$placeholders})",
                ...$paths
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        foreach ($rows as $row) {
            $path         = $row['file_path'];
            $attachmentId = (int) $row['attachment_id'];

            if (! isset($pathIndex[$path])) {
                continue;
            }

            $fileId = $pathIndex[$path];

            // Update attachment_id on the files table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update($filesTable, ['attachment_id' => $attachmentId], ['id' => $fileId]);

            // Insert reference
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$refsTable}
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                $fileId,
                SourceType::Attachment->value,
                $attachmentId,
                '_wp_attached_file',
                'wp_attachment',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            $inserted++;
            unset($pathIndex[$path]);
        }

        // Second pass: confirm thumbnails via _wp_attachment_metadata
        $this->confirmMetaThumbnails();

        return $inserted;
    }

    /**
     * Reads _wp_attachment_metadata to confirm thumbnails that WP registered.
     * This catches thumbnails that don't follow the WxH naming pattern.
     */
    private function confirmMetaThumbnails(): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $attachments = $wpdb->get_results(
            "SELECT f.id AS file_id, f.relative_path, f.attachment_id,
                    pm.meta_value AS metadata
             FROM {$filesTable} f
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = f.attachment_id AND pm.meta_key = '_wp_attachment_metadata'
             WHERE f.attachment_id IS NOT NULL
               AND mime_type LIKE 'image/%'",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ($attachments as $row) {
            $meta = maybe_unserialize($row['metadata']);

            if (! is_array($meta) || empty($meta['sizes']) || ! is_array($meta['sizes'])) {
                continue;
            }

            $dir = dirname($row['relative_path']);
            $dir = $dir === '.' ? '' : $dir . '/';

            foreach ($meta['sizes'] as $sizeData) {
                if (empty($sizeData['file'])) {
                    continue;
                }

                $thumbPath = $dir . $sizeData['file'];

                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$filesTable}
                     SET is_thumbnail = 1, parent_id = %d
                     WHERE relative_path = %s AND is_thumbnail = 0",
                    (int) $row['file_id'],
                    $thumbPath,
                ));
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }
    }
}
