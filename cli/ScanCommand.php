<?php

declare(strict_types=1);

namespace Refaxination\Cli;

use Refaxination\Database;
use Refaxination\FileScanner;
use Refaxination\ReferenceScanner;

class ScanCommand extends \WP_CLI_Command
{
    /**
     * Index all files in wp-content/uploads/.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Files per transaction. Default: 100.
     *
     * [--reset]
     * : Truncate refaxination_files and refaxination_references before scanning.
     *
     * [--resume]
     * : Continue from the cursor of the last interrupted operation.
     *
     * ## EXAMPLES
     *
     *     wp refaxination scan files --batch=100 --reset
     *     wp refaxination scan files --resume
     *
     * @when after_wp_load
     */
    public function files(array $args, array $assoc_args): void
    {
        set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        wp_raise_memory_limit('admin');

        $batch  = (int) ($assoc_args['batch'] ?? 100);
        $reset  = isset($assoc_args['reset']);
        $resume = isset($assoc_args['resume']);

        if ($reset && $resume) {
            \WP_CLI::error(__('--reset and --resume are mutually exclusive.', 'refaxination'));
            return;
        }

        if ($reset) {
            global $wpdb;
            $filesTable = esc_sql( Database::filesTable() );
            $refsTable  = esc_sql( Database::refsTable() );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query("TRUNCATE TABLE `{$filesTable}`");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query("TRUNCATE TABLE `{$refsTable}`");
            \WP_CLI::line(__('Tables truncated.', 'refaxination'));
        }

        (new FileScanner())->run(batchSize: $batch, resume: $resume);
    }

    /**
     * Scan references for all indexed files.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Files per iteration. Default: 100.
     *
     * [--source=<list>]
     * : Comma-separated list of scanners to run. Default: all.
     * Valid values: attachment,post_content,postmeta,options,tsf,ssp,acf,yoast,custom
     *
     * [--resume]
     * : Skip files where scanned_refs_at IS NOT NULL.
     *
     * ## EXAMPLES
     *
     *     wp refaxination scan refs --batch=100
     *     wp refaxination scan refs --source=attachment,postmeta --resume
     *
     * @when after_wp_load
     */
    public function refs(array $args, array $assoc_args): void
    {
        set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        wp_raise_memory_limit('admin');

        $batch   = (int) ($assoc_args['batch'] ?? 100);
        $resume  = isset($assoc_args['resume']);
        $sources = isset($assoc_args['source'])
            ? array_map('trim', explode(',', $assoc_args['source']))
            : null;

        (new ReferenceScanner())->run(
            batchSize:      $batch,
            resume:         $resume,
            enabledSources: $sources,
        );
    }
}
