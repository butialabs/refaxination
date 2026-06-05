<?php

declare(strict_types=1);

namespace Refaxination\Cli;

use Refaxination\Database;
use Refaxination\Enum\FileStatus;
use Refaxination\Enum\OperationType;
use Refaxination\QuarantineManager;
use Refaxination\Reporter;

class Cli extends \WP_CLI_Command
{
    /**
     * Display a file status report.
     *
     * ## OPTIONS
     *
     * [--format=<fmt>]
     * : Output format: table, json, csv. Default: table.
     *
     * [--status=<status>]
     * : Filter by status: pending, referenced, library_only, orphan, moved.
     *
     * [--group-by=<field>]
     * : Group results. Supported value: type (groups by MIME type).
     *
     * ## EXAMPLES
     *
     *     wp refaxination report
     *     wp refaxination report --status=orphan --format=csv
     *     wp refaxination report --group-by=type
     *
     * @when after_wp_load
     */
    public function report(array $args, array $assoc_args): void
    {
        $format  = $assoc_args['format']   ?? 'table';
        $status  = $assoc_args['status']   ?? null;
        $groupBy = $assoc_args['group-by'] ?? null;

        $reporter = new Reporter();

        if ($groupBy === 'type') {
            $reporter->reportByType(format: $format);
            return;
        }

        $reporter->report(
            status: $status !== null ? FileStatus::from($status) : null,
            format: $format,
        );
    }

    /**
     * Move orphaned files to wp-content/orphans/.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be moved without moving anything.
     *
     * [--batch=<n>]
     * : Files per iteration. Default: 100.
     *
     * [--include-wp-only]
     * : Also include files with status=library_only.
     *
     * ## EXAMPLES
     *
     *     wp refaxination quarantine --dry-run
     *     wp refaxination quarantine --batch=50 --include-wp-only
     *
     * @when after_wp_load
     */
    public function quarantine(array $args, array $assoc_args): void
    {
        set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged

        $dryRun        = isset($assoc_args['dry-run']);
        $batch         = (int) ($assoc_args['batch'] ?? 100);
        $includeLibraryOnly = isset($assoc_args['include-wp-only']);

        if (! $dryRun) {
            $count = $this->countQuarantineCandidates($includeLibraryOnly);
            \WP_CLI::confirm(sprintf(
                // translators: %s is the number of files formatted with thousands separator.
                __('Move %s files to orphans/? This is reversible with `wp refaxination restore`.', 'refaxination'),
                number_format($count)
            ));
        }

        $manager = new QuarantineManager();
        $manager->quarantine(
            batchSize:     $batch,
            dryRun:        $dryRun,
            includeLibraryOnly: $includeLibraryOnly,
        );
    }

    /**
     * Restore files from wp-content/orphans/ back to uploads/.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Restore all files with status=moved.
     *
     * [--file-id=<id>]
     * : Restore a specific file by its refaxination_files ID.
     *
     * [--dry-run]
     * : Show what would be restored without moving anything.
     *
     * ## EXAMPLES
     *
     *     wp refaxination restore --all --dry-run
     *     wp refaxination restore --file-id=4821
     *
     * @when after_wp_load
     */
    public function restore(array $args, array $assoc_args): void
    {
        set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged

        $all    = isset($assoc_args['all']);
        $fileId = isset($assoc_args['file-id']) ? (int) $assoc_args['file-id'] : null;
        $dryRun = isset($assoc_args['dry-run']);

        if ($all && $fileId !== null) {
            \WP_CLI::error(__('--all and --file-id are mutually exclusive.', 'refaxination'));
            return;
        }

        if (! $all && $fileId === null) {
            \WP_CLI::error(__('Use --all to restore everything or --file-id=<id> for a specific file.', 'refaxination'));
            return;
        }

        $manager = new QuarantineManager();
        $manager->restore(
            all:    $all,
            fileId: $fileId,
            dryRun: $dryRun,
        );
    }

    /**
     * Show the current and last completed operation.
     *
     * ## EXAMPLES
     *
     *     wp refaxination status
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assoc_args): void
    {
        $running = Database::getRunningOperation();
        $last    = Database::getLastOperation();

        if ($running) {
            $pct = $running->items_total > 0
                ? round($running->items_processed / $running->items_total * 100, 1)
                : 0;

            \WP_CLI::line(__('Running operation:', 'refaxination'));
            \WP_CLI\Utils\format_items('table', [
                [
                    'ID'        => $running->id,
                    'Type'      => $running->operation_type,
                    'Processed' => number_format((int) $running->items_processed),
                    'Total'     => $running->items_total > 0 ? number_format((int) $running->items_total) : '?',
                    'Progress'  => $pct . '%',
                    'Started'   => $running->started_at,
                ],
            ], ['ID', 'Type', 'Processed', 'Total', 'Progress', 'Started']);
        } else {
            \WP_CLI::line(__('No operation running.', 'refaxination'));
        }

        if ($last) {
            \WP_CLI::line('');
            \WP_CLI::line(__('Last completed operation:', 'refaxination'));
            \WP_CLI\Utils\format_items('table', [
                [
                    'ID'        => $last->id,
                    'Type'      => $last->operation_type,
                    'Status'    => $last->status,
                    'Processed' => number_format((int) $last->items_processed),
                    'Errors'    => (int) $last->items_error,
                    'Duration'  => $last->duration_secs ? gmdate('H:i:s', (int) $last->duration_secs) : '-',
                    'Completed' => $last->completed_at ?? '-',
                ],
            ], ['ID', 'Type', 'Status', 'Processed', 'Errors', 'Duration', 'Completed']);
        }
    }

    /**
     * Reset scan data.
     *
     * ## OPTIONS
     *
     * [--tables]
     * : Drop and recreate all tables (including moves).
     *
     * [--confirm]
     * : Skip the interactive confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp refaxination reset --confirm
     *     wp refaxination reset --tables --confirm
     *
     * @when after_wp_load
     */
    public function reset(array $args, array $assoc_args): void
    {
        $tables  = isset($assoc_args['tables']);
        $confirm = isset($assoc_args['confirm']);

        if (! $confirm) {
            \WP_CLI::confirm(
                $tables
                    ? __('Drop and recreate ALL refaxination_* tables?', 'refaxination')
                    : __('Truncate refaxination_files, refaxination_references and refaxination_operations?', 'refaxination')
            );
        }

        global $wpdb;

        $opId = Database::startOperation(OperationType::Reset->value, 0);

        if ($tables) {
            Database::uninstall(dropMoves: true);
            Database::install();
            \WP_CLI::success(__('All tables recreated.', 'refaxination'));
        } else {
            foreach ([Database::filesTable(), Database::refsTable(), Database::opsTable()] as $table) {
                $escapedTable = esc_sql($table);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$escapedTable}`");
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query("TRUNCATE TABLE `{$escapedTable}`");
                // translators: %1$s is the table name, %2$d is the number of rows removed.
                \WP_CLI::line(sprintf(__('Truncated: %1$s (%2$d rows removed)', 'refaxination'), $table, $count));
            }
            \WP_CLI::line(__('Moves table preserved (audit log).', 'refaxination'));
        }

        Database::completeOperation($opId);
    }

    /**
     * Mark all stale running operations as interrupted.
     *
     * ## EXAMPLES
     *
     *     wp refaxination cleanup
     *
     * @when after_wp_load
     */
    public function cleanup(array $args, array $assoc_args): void
    {
        global $wpdb;

        $opsTable = esc_sql( Database::opsTable() );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "UPDATE `{$opsTable}`
                 SET status = 'interrupted', completed_at = %s
                 WHERE status = 'running'",
                current_time('mysql'),
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        \WP_CLI::success(sprintf(
            // translators: %d is the number of stale operations that were marked as interrupted.
            _n('%d stale operation marked as interrupted.', '%d stale operations marked as interrupted.', (int) $updated, 'refaxination'),
            (int) $updated
        ));
    }

    private function countQuarantineCandidates(bool $includeLibraryOnly): int
    {
        global $wpdb;

        $statuses = ["'orphan'"];
        if ($includeLibraryOnly) {
            $statuses[] = "'library_only'";
        }

        $filesTable = esc_sql( Database::filesTable() );
        // $statusIn is built exclusively from hardcoded status string literals — no user input.
        $statusIn   = implode(',', $statuses);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(*) FROM `{$filesTable}` WHERE status IN ({$statusIn})"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }
}
