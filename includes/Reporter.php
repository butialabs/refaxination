<?php

declare(strict_types=1);

namespace Refaxination;

use League\Csv\Writer;
use Refaxination\Enum\FileStatus;

class Reporter
{
    public function report(?FileStatus $status, string $format): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );

        if ($status === null) {
            $this->printSummary($format);
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT id, relative_path, file_size, mime_type, status,
                        attachment_id, is_thumbnail, parent_id, first_seen_at
                 FROM `{$filesTable}`
                 WHERE status = %s
                 ORDER BY relative_path ASC",
                $status->value,
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        match ($format) {
            'json' => $this->outputJson($rows),
            'csv'  => $this->outputCsv($rows),
            default => $this->outputTable($rows, ['id', 'relative_path', 'file_size', 'mime_type', 'status']),
        };
    }

    public function reportByType(string $format): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT
                CASE
                    WHEN mime_type LIKE 'image/%'             THEN 'image'
                    WHEN mime_type LIKE 'video/%'             THEN 'video'
                    WHEN mime_type LIKE 'audio/%'             THEN 'audio'
                    WHEN mime_type IN (
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-powerpoint',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                    )                                         THEN 'document'
                    ELSE 'other'
                END AS type,
                COUNT(*)        AS total,
                SUM(file_size)  AS total_bytes
             FROM {$filesTable}
             GROUP BY type
             ORDER BY total_bytes DESC",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $rows = array_map(function (array $row): array {
            $row['size'] = $this->humanBytes((int) $row['total_bytes']);
            unset($row['total_bytes']);
            return $row;
        }, $rows);

        match ($format) {
            'json'  => $this->outputJson($rows),
            'csv'   => $this->outputCsv($rows),
            default => $this->outputTable($rows, ['type', 'total', 'size']),
        };
    }

    private function printSummary(string $format): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT status, COUNT(*) AS total, SUM(file_size) AS total_bytes
             FROM `{$filesTable}`
             GROUP BY status",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $grandTotal      = 0;
        $grandTotalBytes = 0;
        $rows            = [];

        foreach ($stats as $row) {
            $grandTotal      += (int) $row['total'];
            $grandTotalBytes += (int) $row['total_bytes'];
            $rows[]           = [
                'status' => $row['status'],
                'total'  => number_format((int) $row['total']),
                'size'   => $this->humanBytes((int) $row['total_bytes']),
            ];
        }

        $rows[] = [
            'status' => __('--- TOTAL ---', 'refaxination'),
            'total'  => number_format($grandTotal),
            'size'   => $this->humanBytes($grandTotalBytes),
        ];

        match ($format) {
            'json'  => $this->outputJson($stats),
            'csv'   => $this->outputCsv($rows),
            default => $this->outputTable($rows, ['status', 'total', 'size']),
        };

        $lastOp = Database::getLastOperation();
        if ($lastOp) {
            \WP_CLI::line('');
            // translators: %s is the datetime string of the last completed scan operation.
            \WP_CLI::line( sprintf( __( 'Last scan: %s', 'refaxination' ), $lastOp->completed_at ?? '-' ) );
        }
    }

    private function outputTable(array $rows, array $columns): void
    {
        if ($rows === []) {
            \WP_CLI::line(__('No results.', 'refaxination'));
            return;
        }

        \WP_CLI\Utils\format_items('table', $rows, $columns);
    }

    private function outputJson(array $rows): void
    {
        \WP_CLI::line(wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function outputCsv(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $writer = Writer::createFromString();
        $writer->insertOne(array_keys($rows[0]));

        $writer->insertAll((function () use ($rows) {
            foreach ($rows as $row) {
                yield array_values($row);
            }
        })());

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV output from League\Csv\Writer; contains only internal DB data, not user-supplied HTML.
        echo $writer->toString();
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow   = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }
}
