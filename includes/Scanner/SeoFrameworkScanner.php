<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

/**
 * The SEO Framework (autodescription) scanner.
 * Keys: _social_image_id (postmeta), social_image_id (termmeta),
 *       homepage_social_image_id inside autodescription-site-settings option.
 */
class SeoFrameworkScanner implements ScannerInterface
{
    public function getSourceType(): SourceType
    {
        return SourceType::Tsf;
    }

    public function isAvailable(): bool
    {
        return is_plugin_active('autodescription/autodescription.php')
            || defined('THE_SEO_FRAMEWORK_VERSION');
    }

    public function scan(array $fileBatch): int
    {
        global $wpdb;

        if ($fileBatch === []) {
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

        $inserted += $this->scanPostmeta($idIndex, $pathIndex);
        $inserted += $this->scanTermmeta($idIndex, $pathIndex);
        $inserted += $this->scanSiteSettings($idIndex, $pathIndex);

        return $inserted;
    }

    private function scanPostmeta(array $idIndex, array $pathIndex): int
    {
        global $wpdb;

        $refsTable   = esc_sql( Database::refsTable() );
        $inserted    = 0;
        $upload      = wp_upload_dir();
        $uploadsPath = rtrim(wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/') . '/';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_social_image_id', '_social_image_url',
                                '_genesis_og_image_id', '_genesis_og_image_url')",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $fileId = $this->resolveFileId(
                $row['meta_value'],
                $row['meta_key'],
                $idIndex,
                $pathIndex,
                $uploadsPath,
            );

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$refsTable}
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                $fileId,
                SourceType::Tsf->value,
                (int) $row['post_id'],
                $row['meta_key'],
                'tsf_post_image',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $inserted++;
        }

        return $inserted;
    }

    private function scanTermmeta(array $idIndex, array $pathIndex): int
    {
        global $wpdb;

        if (! isset($wpdb->termmeta)) {
            return 0;
        }

        $refsTable   = esc_sql( Database::refsTable() );
        $upload      = wp_upload_dir();
        $uploadsPath = rtrim(wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/') . '/';
        $inserted    = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT term_id, meta_key, meta_value
             FROM {$wpdb->termmeta}
             WHERE meta_key IN ('social_image_id', 'social_image_url',
                                'wpseo_opengraph-image-id', 'wpseo_opengraph-image')",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $fileId = $this->resolveFileId(
                $row['meta_value'],
                $row['meta_key'],
                $idIndex,
                $pathIndex,
                $uploadsPath,
            );

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$refsTable}
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                $fileId,
                SourceType::Tsf->value,
                (int) $row['term_id'],
                $row['meta_key'],
                'tsf_term_image',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $inserted++;
        }

        return $inserted;
    }

    private function scanSiteSettings(array $idIndex, array $pathIndex): int
    {
        global $wpdb;

        $refsTable   = esc_sql( Database::refsTable() );
        $upload      = wp_upload_dir();
        $uploadsPath = rtrim(wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/') . '/';
        $inserted    = 0;

        $raw = get_option('autodescription-site-settings');

        if (! $raw) {
            return 0;
        }

        try {
            $settings = is_array($raw) ? $raw : maybe_unserialize($raw);
        } catch (\Throwable) {
            return 0;
        }

        if (! is_array($settings)) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $optionId = (int) $wpdb->get_var(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'autodescription-site-settings'"
        );

        $idKeys  = ['homepage_social_image_id', 'social_image_fb_id', 'social_image_tw_id'];
        $urlKeys = ['homepage_social_image_url', 'social_image_fb_url', 'social_image_tw_url'];

        foreach ($idKeys as $key) {
            if (empty($settings[$key])) {
                continue;
            }

            $fileId = $idIndex[(int) $settings[$key]] ?? null;

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$refsTable}
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                $fileId,
                SourceType::Tsf->value,
                $optionId,
                $key,
                'tsf_site_setting',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $inserted++;
        }

        foreach ($urlKeys as $key) {
            if (empty($settings[$key])) {
                continue;
            }

            $fileId = $this->resolveFileId($settings[$key], $key, $idIndex, $pathIndex, $uploadsPath);

            if ($fileId === null) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$refsTable}
                 (file_id, source_type, source_id, meta_key, context)
                 VALUES (%d, %s, %d, %s, %s)",
                $fileId,
                SourceType::Tsf->value,
                $optionId,
                $key,
                'tsf_site_setting',
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $inserted++;
        }

        return $inserted;
    }

    private function resolveFileId(
        string $value,
        string $key,
        array  $idIndex,
        array  $pathIndex,
        string $uploadsPath,
    ): ?int {
        if (str_ends_with($key, '_id') && ctype_digit($value)) {
            return $idIndex[(int) $value] ?? null;
        }

        $urlPath = wp_parse_url($value, PHP_URL_PATH) ?? '';
        if ($urlPath !== '' && str_contains($urlPath, $uploadsPath)) {
            return $pathIndex[trim(str_replace($uploadsPath, '', $urlPath), '/')] ?? null;
        }

        return null;
    }
}
