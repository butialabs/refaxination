<?php

declare(strict_types=1);

namespace Refaxination;

use Refaxination\Enum\OperationType;
use Refaxination\Scanner\AcfScanner;
use Refaxination\Scanner\AttachmentScanner;
use Refaxination\Scanner\OptionsScanner;
use Refaxination\Scanner\PostContentScanner;
use Refaxination\Scanner\PostmetaScanner;
use Refaxination\Scanner\ScannerInterface;
use Refaxination\Scanner\SeoFrameworkScanner;
use Refaxination\Scanner\SspScanner;
use Refaxination\Scanner\YoastScanner;

class ReferenceScanner
{
    /** @var list<ScannerInterface> */
    private array $scanners;

    public function __construct()
    {
        $this->scanners = $this->buildScanners();
    }

    public function run(int $batchSize = 100, bool $resume = false, ?array $enabledSources = null): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );

        $activeScanners = $enabledSources !== null
            ? array_filter(
                $this->scanners,
                fn(ScannerInterface $s) => in_array($s->getSourceType()->value, $enabledSources, strict: true)
            )
            : $this->scanners;

        $activeScanners = array_values(array_filter(
            $activeScanners,
            fn(ScannerInterface $s) => $s->isAvailable()
        ));

        if ($activeScanners === []) {
            \WP_CLI::error(__('No scanners available for the selected sources.', 'refaxination'));
            return;
        }

        $scannerNames = array_map(
            fn(ScannerInterface $s) => $s->getSourceType()->value,
            $activeScanners
        );

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // translators: %s is a comma-separated list of active scanner source types.
        \WP_CLI::line( sprintf( __( 'Active scanners: %s', 'refaxination' ), implode( ', ', $scannerNames ) ) );

        $opId = Database::startOperation(OperationType::ScanRefs->value, $batchSize, [
            'resume'  => $resume,
            'sources' => $scannerNames,
        ]);

        // $countSuffix is built from hardcoded SQL fragments only — no user input interpolated.
        $countSuffix = $resume ? ' WHERE scanned_refs_at IS NULL' : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$filesTable}`{$countSuffix}" );

        Database::updateOperation($opId, ['items_total' => $total]);

        // translators: %s is the formatted number of files to be scanned for references.
        \WP_CLI::line( sprintf( __( 'Scanning references... (%s files to process)', 'refaxination' ), number_format( $total ) ) );

        $processed = 0;
        $inserted  = 0;
        $errors    = 0;
        $offset    = 0;

        $progress = \WP_CLI\Utils\make_progress_bar(__('Scanning refs', 'refaxination'), $total);

        do {
            $whereClause = $resume ? 'WHERE scanned_refs_at IS NULL' : '';

            // $whereClause is built from hardcoded SQL fragments only — no user input interpolated.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $batch = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, relative_path, attachment_id
                     FROM `{$filesTable}`
                     {$whereClause}
                     ORDER BY id ASC
                     LIMIT %d OFFSET %d",
                    $batchSize,
                    $offset,
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ($batch === []) {
                break;
            }

            foreach ($activeScanners as $scanner) {
                try {
                    $count     = $scanner->scan($batch);
                    $inserted += $count;
                } catch (\Throwable $e) {
                    $errors++;
                    Database::appendOperationError(
                        $opId,
                        "[{$scanner->getSourceType()->value}] " . $e->getMessage()
                    );
                }
            }

            // Mark batch as scanned
            $ids          = array_column($batch, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$filesTable}` SET scanned_refs_at = %s WHERE id IN ({$placeholders})",
                    current_time( 'mysql' ),
                    ...$ids,
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

            $processed += count($batch);
            $offset    += $resume ? 0 : $batchSize;

            Database::updateOperation($opId, [
                'items_processed' => $processed,
                'items_error'     => $errors,
                'resume_cursor'   => (string) end($ids),
            ]);

            $progress->tick(count($batch));

        } while (count($batch) === $batchSize);

        $progress->finish();

        \WP_CLI::line(__('Resolving statuses (inheriting thumbnails)...', 'refaxination'));
        $this->resolveStatuses();
        $this->inheritThumbnailStatuses();

        Database::completeOperation($opId);

        $this->printStatusSummary();

        \WP_CLI::success( sprintf(
            // translators: %1$s is the formatted count of references found, %2$s is the number of errors.
            __( 'Scan complete. %1$s references found. %2$s errors.', 'refaxination' ),
            number_format( $inserted ),
            $errors
        ) );
    }

    private function resolveStatuses(): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );
        $refsTable  = esc_sql( Database::refsTable() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE `{$filesTable}` f
             INNER JOIN `{$refsTable}` r ON r.file_id = f.id AND r.source_type != 'attachment'
             SET f.status = 'referenced'
             WHERE f.is_thumbnail = 0"
        );

        $wpdb->query(
            "UPDATE `{$filesTable}` f
             INNER JOIN `{$refsTable}` r ON r.file_id = f.id
             SET f.status = 'library_only'
             WHERE f.status = 'pending' AND f.is_thumbnail = 0"
        );

        $wpdb->query(
            "UPDATE `{$filesTable}`
             SET status = 'orphan'
             WHERE status = 'pending' AND is_thumbnail = 0"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private function inheritThumbnailStatuses(): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE `{$filesTable}` thumb
             INNER JOIN `{$filesTable}` parent ON parent.id = thumb.parent_id
             SET thumb.status = parent.status
             WHERE thumb.is_thumbnail = 1 AND thumb.parent_id IS NOT NULL"
        );

        $wpdb->query(
            "UPDATE `{$filesTable}`
             SET status = 'orphan'
             WHERE is_thumbnail = 1 AND status = 'pending'"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private function printStatusSummary(): void
    {
        global $wpdb;

        $filesTable = esc_sql( Database::filesTable() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM `{$filesTable}` GROUP BY status ORDER BY status",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        \WP_CLI::line('');
        \WP_CLI::line(__('Status distribution:', 'refaxination'));
        \WP_CLI\Utils\format_items('table', $rows, ['status', 'cnt']);
    }

    /** @return list<ScannerInterface> */
    private function buildScanners(): array
    {
        $scanners = [
            new AttachmentScanner(),
            new PostmetaScanner(),
            new PostContentScanner(),
            new OptionsScanner(),
            new SeoFrameworkScanner(),
            new SspScanner(),
            new AcfScanner(),
            new YoastScanner(),
        ];

        // Allow plugins to add custom scanners
        $extra = apply_filters('refaxination_reference_scanners', []);

        foreach ($extra as $scanner) {
            if ($scanner instanceof ScannerInterface) {
                $scanners[] = $scanner;
            }
        }

        return $scanners;
    }
}
