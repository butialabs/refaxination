<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

class OptionsScanner implements ScannerInterface
{
    private const SKIP_OPTIONS = [
        'active_plugins', 'siteurl', 'blogurl', 'home', 'cron', 'widget_block',
        'rewrite_rules', 'user_roles', 'auth_key', 'secure_auth_key',
        'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt',
        'logged_in_salt', 'nonce_salt',
    ];

    private const MAX_DEPTH = 5;

    public function getSourceType(): SourceType
    {
        return SourceType::Options;
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

        $upload      = wp_upload_dir();
        $uploadsPath = rtrim(wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/') . '/';
        $refsTable   = esc_sql( Database::refsTable() );

        $pathIndex = [];
        $idIndex   = [];

        foreach ($fileBatch as $file) {
            $pathIndex[$file['relative_path']] = (int) $file['id'];
            if (! empty($file['attachment_id'])) {
                $idIndex[(int) $file['attachment_id']] = (int) $file['id'];
            }
        }

        $inserted = 0;

        $skipPlaceholders = implode(',', array_fill(0, count(self::SKIP_OPTIONS), '%s'));

        $prepareArgs = array_merge(
            [
                '%' . $wpdb->esc_like($uploadsPath) . '%',
                '%attachment_id%',
            ],
            self::SKIP_OPTIONS,
            [
                $wpdb->esc_like('_transient_') . '%',
                $wpdb->esc_like('_site_transient_') . '%',
            ]
        );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, option_value
                 FROM {$wpdb->options}
                 WHERE (
                     option_value LIKE %s
                     OR option_value LIKE %s
                 )
                 AND option_name NOT IN ({$skipPlaceholders})
                 AND option_name NOT LIKE %s
                 AND option_name NOT LIKE %s",
                ...$prepareArgs
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        foreach ($options as $option) {
            $raw = $option['option_value'];

            try {
                $value = maybe_unserialize($raw);
            } catch (\Throwable) {
                $value = $raw;
            }

            $found = $this->extractRefs($value, $uploadsPath, $pathIndex, $idIndex, depth: 0);

            foreach ($found as $fileId) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$refsTable}
                     (file_id, source_type, source_id, meta_key, context)
                     VALUES (%d, %s, %d, %s, %s)",
                    $fileId,
                    SourceType::Options->value,
                    (int) $option['option_id'],
                    $option['option_name'],
                    'option_value',
                ));
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $inserted++;
            }

            unset($raw, $value, $found);
        }

        return $inserted;
    }

    private function extractRefs(
        mixed  $value,
        string $uploadsPath,
        array  $pathIndex,
        array  $idIndex,
        int    $depth,
    ): array {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $found = [];

        if (is_string($value)) {
            // Match any scheme+host combination followed by the uploads path
            preg_match_all(
                '~https?://[^/\s\'"<>]+' . preg_quote($uploadsPath, '~') . '([^\s\'"<>\)\\\\]+)~i',
                $value,
                $m
            );
            foreach ($m[1] as $path) {
                $path = rtrim($path, '/');
                if (isset($pathIndex[$path])) {
                    $found[] = $pathIndex[$path];
                }
            }

            if (ctype_digit($value) && isset($idIndex[(int) $value])) {
                $found[] = $idIndex[(int) $value];
            }

            return array_unique($found);
        }

        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                $found = [...$found, ...$this->extractRefs($item, $uploadsPath, $pathIndex, $idIndex, $depth + 1)];
            }
            return array_unique($found);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && str_contains(strtolower($k), 'id') && is_numeric($v)) {
                    if (isset($idIndex[(int) $v])) {
                        $found[] = $idIndex[(int) $v];
                    }
                }
                $found = [...$found, ...$this->extractRefs($v, $uploadsPath, $pathIndex, $idIndex, $depth + 1)];
            }
            return array_unique($found);
        }

        return [];
    }
}
