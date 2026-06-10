<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

/**
 * Yoast SEO scanner.
 * Postmeta: _yoast_wpseo_opengraph-image-id, _yoast_wpseo_twitter-image-id (+ URL variants)
 * Termmeta: wpseo_opengraph-image-id, wpseo_opengraph-image (URL)
 * Options: wpseo_social -> og_default_image_id, og_default_image
 */
class YoastScanner implements ScannerInterface
{
    public function getSourceType(): SourceType
    {
        return SourceType::Yoast;
    }

    public function isAvailable(): bool
    {
        return class_exists('WPSEO_Options')
            || is_plugin_active('wordpress-seo/wp-seo.php')
            || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php');
    }

    public function scan(array $fileBatch): int
    {
        if ($fileBatch === []) {
            return 0;
        }

        $upload      = wp_upload_dir();
        $uploadsPath = rtrim(wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/') . '/';

        $idIndex   = [];
        $pathIndex = [];

        foreach ($fileBatch as $file) {
            if (! empty($file['attachment_id'])) {
                $idIndex[(int) $file['attachment_id']] = (int) $file['id'];
            }
            $pathIndex[$file['relative_path']] = (int) $file['id'];
        }

        $inserted = 0;
        $inserted += $this->scanPostmeta($idIndex, $pathIndex, $uploadsPath);
        $inserted += $this->scanTermmeta($idIndex, $pathIndex, $uploadsPath);
        $inserted += $this->scanSocialOptions($idIndex, $pathIndex, $uploadsPath);

        return $inserted;
    }

    private function scanPostmeta(array $idIndex, array $pathIndex, string $uploadsPath): int
    {
        global $wpdb;

        $inserted  = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key IN (
                 '_yoast_wpseo_opengraph-image-id',
                 '_yoast_wpseo_opengraph-image',
                 '_yoast_wpseo_twitter-image-id',
                 '_yoast_wpseo_twitter-image'
             )
             AND meta_value != '' AND meta_value != '0'",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $fileId = $this->resolveFileId($row['meta_key'], $row['meta_value'], $idIndex, $pathIndex, $uploadsPath);

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                Database::refsTable(),
                $fileId,
                SourceType::Yoast->value,
                (int) $row['post_id'],
                $row['meta_key'],
                'yoast_post_image',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $inserted++;
        }

        return $inserted;
    }

    private function scanTermmeta(array $idIndex, array $pathIndex, string $uploadsPath): int
    {
        global $wpdb;

        if (! isset($wpdb->termmeta)) {
            return 0;
        }

        $inserted  = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT term_id, meta_key, meta_value
             FROM {$wpdb->termmeta}
             WHERE meta_key IN (
                 'wpseo_opengraph-image-id',
                 'wpseo_opengraph-image',
                 'wpseo_twitter-image-id',
                 'wpseo_twitter-image'
             )
             AND meta_value != '' AND meta_value != '0'",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $fileId = $this->resolveFileId($row['meta_key'], $row['meta_value'], $idIndex, $pathIndex, $uploadsPath);

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                Database::refsTable(),
                $fileId,
                SourceType::Yoast->value,
                (int) $row['term_id'],
                $row['meta_key'],
                'yoast_term_image',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $inserted++;
        }

        return $inserted;
    }

    private function scanSocialOptions(array $idIndex, array $pathIndex, string $uploadsPath): int
    {
        global $wpdb;

        $inserted  = 0;

        $raw = get_option('wpseo_social');

        if (! $raw || ! is_array($raw)) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $optionId = (int) $wpdb->get_var(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'wpseo_social'"
        );

        $idKeys  = ['og_default_image_id', 'twitter_image_id'];
        $urlKeys = ['og_default_image', 'twitter_image'];

        foreach ($idKeys as $key) {
            if (empty($raw[$key])) {
                continue;
            }

            $fileId = $idIndex[(int) $raw[$key]] ?? null;

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                Database::refsTable(),
                $fileId,
                SourceType::Yoast->value,
                $optionId,
                $key,
                'yoast_global_image',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $inserted++;
        }

        foreach ($urlKeys as $key) {
            if (empty($raw[$key])) {
                continue;
            }

            $urlPath = wp_parse_url($raw[$key], PHP_URL_PATH) ?? '';
            if ($urlPath === '' || ! str_contains($urlPath, $uploadsPath)) {
                continue;
            }

            $relativePath = trim(str_replace($uploadsPath, '', $urlPath), '/');
            $fileId       = $pathIndex[$relativePath] ?? null;

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO %i
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                Database::refsTable(),
                $fileId,
                SourceType::Yoast->value,
                $optionId,
                $key,
                'yoast_global_image',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $inserted++;
        }

        return $inserted;
    }

    private function resolveFileId(
        string $key,
        string $value,
        array  $idIndex,
        array  $pathIndex,
        string $uploadsPath,
    ): ?int {
        if (str_ends_with($key, '-id') && ctype_digit($value)) {
            return $idIndex[(int) $value] ?? null;
        }

        $urlPath = wp_parse_url($value, PHP_URL_PATH) ?? '';
        if ($urlPath !== '' && str_contains($urlPath, $uploadsPath)) {
            return $pathIndex[trim(str_replace($uploadsPath, '', $urlPath), '/')] ?? null;
        }

        return null;
    }
}
