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
            $wpdb->update(Database::filesTable(), ['attachment_id' => $attachmentId], ['id' => $fileId]);

            // Insert reference
            $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                'INSERT IGNORE INTO %i (file_id, source_type, source_id, meta_key, context) VALUES (%d, %s, %d, %s, %s)',
                Database::refsTable(),
                $fileId,
                SourceType::Attachment->value,
                $attachmentId,
                '_wp_attached_file',
                'wp_attachment',
            ));

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

        $attachments = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT f.id AS file_id, f.relative_path, f.attachment_id,
                        pm.meta_value AS metadata
                 FROM %i f
                 INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = f.attachment_id AND pm.meta_key = '_wp_attachment_metadata'
                 WHERE f.attachment_id IS NOT NULL
                   AND mime_type LIKE %s",
                Database::filesTable(),
                'image/%'
            ),
            ARRAY_A
        );

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

                $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    'UPDATE %i SET is_thumbnail = 1, parent_id = %d WHERE relative_path = %s AND is_thumbnail = 0',
                    Database::filesTable(),
                    (int) $row['file_id'],
                    $thumbPath,
                ));
            }
        }
    }
}
