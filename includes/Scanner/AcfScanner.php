<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

/**
 * Advanced Custom Fields scanner.
 * Discovers which postmeta keys are image/file/gallery fields by reading acf-field posts,
 * then scans postmeta for those keys.
 */
class AcfScanner implements ScannerInterface
{
    public function getSourceType(): SourceType
    {
        return SourceType::Acf;
    }

    public function isAvailable(): bool
    {
        return class_exists('ACF')
            || class_exists('acf')
            || defined('ACF_VERSION')
            || is_plugin_active('advanced-custom-fields/acf.php')
            || is_plugin_active('advanced-custom-fields-pro/acf.php');
    }

    public function scan(array $fileBatch): int
    {
        global $wpdb;

        if ($fileBatch === []) {
            return 0;
        }

        $mediaFields = $this->discoverMediaFields();

        if ($mediaFields === []) {
            return 0;
        }

        $idIndex   = [];
        $pathIndex = [];

        foreach ($fileBatch as $file) {
            if (! empty($file['attachment_id'])) {
                $idIndex[(int) $file['attachment_id']] = (int) $file['id'];
            }
            $pathIndex[$file['relative_path']] = (int) $file['id'];
        }

        $inserted = 0;

        foreach ($mediaFields as $field) {
            $inserted += match ($field['type']) {
                'image', 'file' => $this->scanSingleField($field, $idIndex),
                'gallery'       => $this->scanGalleryField($field, $idIndex),
                default         => 0,
            };
        }

        return $inserted;
    }

    /**
     * @return array<int, array{name: string, key: string, type: string}>
     */
    private function discoverMediaFields(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $fields = $wpdb->get_results(
            "SELECT post_name AS field_name, post_excerpt AS field_key, post_content AS field_config
             FROM {$wpdb->posts}
             WHERE post_type = 'acf-field'
               AND post_status = 'publish'",
            ARRAY_A
        );

        $mediaFields = [];

        foreach ($fields as $field) {
            try {
                $config = maybe_unserialize($field['field_config']);

                if (! is_array($config)) {
                    // Try JSON (ACF Pro 6.x stores as JSON in some cases)
                    $config = json_decode($field['field_config'], associative: true);
                }
            } catch (\Throwable) {
                continue;
            }

            if (! is_array($config) || empty($config['type'])) {
                continue;
            }

            if (in_array($config['type'], ['image', 'file', 'gallery'], strict: true)) {
                $mediaFields[] = [
                    'name' => $field['field_name'],
                    'key'  => $field['field_key'],
                    'type' => $config['type'],
                ];
            }
        }

        return $mediaFields;
    }

    private function scanSingleField(array $field, array $idIndex): int
    {
        global $wpdb;

        $refsTable = esc_sql( Database::refsTable() );
        $inserted  = 0;

        // ACF stores image/file fields as attachment ID in postmeta
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                   AND meta_value REGEXP '^[0-9]+$'",
                $field['name'],
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $attId  = (int) $row['meta_value'];
            $fileId = $idIndex[$attId] ?? null;

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$refsTable}
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                $fileId,
                SourceType::Acf->value,
                (int) $row['post_id'],
                $field['name'],
                'acf:' . $field['key'],
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $inserted++;
        }

        return $inserted;
    }

    private function scanGalleryField(array $field, array $idIndex): int
    {
        global $wpdb;

        $refsTable = esc_sql( Database::refsTable() );
        $inserted  = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = %s",
                $field['name'],
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            try {
                $ids = maybe_unserialize($row['meta_value']);
            } catch (\Throwable) {
                continue;
            }

            if (! is_array($ids) || ! array_is_list($ids)) {
                continue;
            }

            foreach ($ids as $rawId) {
                if (! is_numeric($rawId)) {
                    continue;
                }

                $attId  = (int) $rawId;
                $fileId = $idIndex[$attId] ?? null;

                if ($fileId === null) {
                    continue;
                }

                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$refsTable}
                     (file_id, source_type, source_id, meta_key, context)
                     VALUES (%d, %s, %d, %s, %s)",
                    $fileId,
                    SourceType::Acf->value,
                    (int) $row['post_id'],
                    $field['name'],
                    'acf_gallery:' . $field['key'],
                ));
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $inserted++;
            }
        }

        return $inserted;
    }
}
