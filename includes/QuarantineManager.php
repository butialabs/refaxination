<?php

declare(strict_types=1);

namespace Refaxination;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Refaxination\Enum\MoveDirection;
use Refaxination\Enum\OperationType;

class QuarantineManager
{
    private Filesystem $uploadsFs;
    private Filesystem $orphansFs;
    private string     $uploadsDir;
    private string     $orphansDir;

    public function __construct()
    {
        $upload           = wp_upload_dir();
        $this->uploadsDir = trailingslashit($upload['basedir']);
        $this->orphansDir = trailingslashit($upload['basedir']) . 'refaxination-orphans/';

        $this->uploadsFs = new Filesystem(new LocalFilesystemAdapter($this->uploadsDir));
        $this->orphansFs = new Filesystem(new LocalFilesystemAdapter($this->orphansDir));
    }

    public function quarantine(int $batchSize = 100, bool $dryRun = false, bool $includeLibraryOnly = false): void
    {
        global $wpdb;

        $statusValues        = $includeLibraryOnly ? ['orphan', 'library_only'] : ['orphan'];
        $statusPlaceholders  = implode(',', array_fill(0, count($statusValues), '%s'));

        $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE status IN ({$statusPlaceholders}) AND status != 'moved'",
                Database::filesTable(),
                ...$statusValues
            )
        );

        if ($total === 0) {
            \WP_CLI::success(__('No files to quarantine.', 'refaxination'));
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $label = $dryRun ? __('[DRY RUN] ', 'refaxination') : '';
        // translators: %1$s is an optional "[DRY RUN] " prefix; %2$s is the number of files to quarantine.
        \WP_CLI::line( sprintf( __( '%1$sQuarantining %2$s files...', 'refaxination' ), $label, number_format( $total ) ) );

        $opId = Database::startOperation(OperationType::Quarantine->value, $batchSize, [
            'dry_run'         => $dryRun,
            'include_library_only' => $includeLibraryOnly,
        ]);
        Database::updateOperation($opId, ['items_total' => $total]);

        $this->ensureOrphansIndex();

        $processed = 0;
        $moved     = 0;
        $errors    = 0;

        $progress = $dryRun ? null : \WP_CLI\Utils\make_progress_bar('Quarantining', $total);

        do {
            $batch = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT id, relative_path, file_size
                     FROM %i
                     WHERE status IN ({$statusPlaceholders}) AND status != 'moved'
                     ORDER BY id ASC
                     LIMIT %d",
                    Database::filesTable(),
                    ...$statusValues,
                    $batchSize,
                ),
                ARRAY_A
            );

            if ($batch === []) {
                break;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('START TRANSACTION');

            foreach ($batch as $file) {
                $sourcePath = $file['relative_path'];
                $absSource  = $this->uploadsDir . $sourcePath;
                $absDest    = $this->orphansDir . $sourcePath;

                if ($dryRun) {
                    // translators: %1$s is the relative file path being quarantined (used for both source and destination).
                    \WP_CLI::line( sprintf( __( '  [DRY] uploads/%1$s → refaxination-orphans/%1$s', 'refaxination' ), $sourcePath ) );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->insert(Database::movesTable(), [
                        'file_id'      => (int) $file['id'],
                        'operation_id' => $opId,
                        'direction'    => MoveDirection::Quarantine->value,
                        'source_path'  => $absSource,
                        'dest_path'    => $absDest,
                        'file_size'    => (int) $file['file_size'],
                        'is_dry_run'   => 1,
                        'moved_at'     => current_time('mysql'),
                    ]);
                    $moved++;
                } else {
                    try {
                        $this->ensureParentDir($sourcePath);
                        $this->moveFile($sourcePath);

                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->insert(Database::movesTable(), [
                            'file_id'      => (int) $file['id'],
                            'operation_id' => $opId,
                            'direction'    => MoveDirection::Quarantine->value,
                            'source_path'  => $absSource,
                            'dest_path'    => $absDest,
                            'file_size'    => (int) $file['file_size'],
                            'is_dry_run'   => 0,
                            'moved_at'     => current_time('mysql'),
                        ]);

                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->update(Database::filesTable(), [
                            'status'   => 'moved',
                            'moved_at' => current_time('mysql'),
                        ], ['id' => (int) $file['id']]);

                        $moved++;
                    } catch (\Throwable $e) {
                        $errors++;
                        Database::appendOperationError($opId, "Failed to move {$sourcePath}: " . $e->getMessage());
                        // translators: %1$s is the relative file path that failed; %2$s is the error message.
                        \WP_CLI::warning( sprintf( __( 'Failed: %1$s - %2$s', 'refaxination' ), $sourcePath, $e->getMessage() ) );
                    }
                }

                $processed++;
                $progress?->tick();
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query('COMMIT');

            Database::updateOperation($opId, [
                'items_processed' => $processed,
                'items_error'     => $errors,
            ]);

        } while (count($batch) === $batchSize);

        $progress?->finish();

        Database::completeOperation($opId);

        if ($dryRun) {
            // translators: %1$s is the number of files that would be moved; %2$s is the error count.
            \WP_CLI::success( sprintf( __( '%1$s files would be moved to refaxination-orphans/. %2$s errors.', 'refaxination' ), number_format( $moved ), $errors ) );
        } else {
            // translators: %1$s is the number of files moved; %2$s is the error count.
            \WP_CLI::success( sprintf( __( '%1$s files moved to refaxination-orphans/. %2$s errors.', 'refaxination' ), number_format( $moved ), $errors ) );
        }
    }

    public function restore(bool $all = false, ?int $fileId = null, bool $dryRun = false): void
    {
        global $wpdb;

        if ($all) {
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT f.id, f.file_size, m.source_path, m.dest_path
                     FROM %i f
                     INNER JOIN %i m ON m.file_id = f.id AND m.direction = 'quarantine' AND m.is_dry_run = 0
                     WHERE f.status = 'moved'
                     ORDER BY m.moved_at DESC",
                    Database::filesTable(),
                    Database::movesTable()
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT f.id, f.file_size, m.source_path, m.dest_path
                     FROM %i f
                     INNER JOIN %i m ON m.file_id = f.id AND m.direction = 'quarantine' AND m.is_dry_run = 0
                     WHERE f.id = %d AND f.status = 'moved'
                     ORDER BY m.moved_at DESC",
                    Database::filesTable(),
                    Database::movesTable(),
                    $fileId
                ),
                ARRAY_A
            );
        }

        if ($rows === []) {
            \WP_CLI::error(__('No files to restore.', 'refaxination'));
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $opId = Database::startOperation(OperationType::Restore->value, count($rows), [
            'dry_run' => $dryRun,
            'file_id' => $fileId,
            'all'     => $all,
        ]);
        Database::updateOperation($opId, ['items_total' => count($rows)]);

        $processed = 0;
        $errors    = 0;

        $progress = $dryRun ? null : \WP_CLI\Utils\make_progress_bar('Restoring', count($rows));

        foreach ($rows as $row) {
            $relativePath = str_replace($this->uploadsDir, '', $row['source_path']);

            if ($dryRun) {
                // translators: %1$s is the relative file path being restored (used for both source and destination).
                \WP_CLI::line( sprintf( __( '  [DRY] refaxination-orphans/%1$s → uploads/%1$s', 'refaxination' ), $relativePath ) );
                $processed++;
                continue;
            }

            try {
                if (! file_exists($row['dest_path'])) {
                    throw new \RuntimeException( 'File not found in orphans/ directory.' );
                }

                global $wp_filesystem;
                if ( ! $wp_filesystem ) {
                    require_once ABSPATH . '/wp-admin/includes/file.php';
                    WP_Filesystem();
                }

                $this->ensureUploadsParentDir($relativePath);
                if ( ! $wp_filesystem->move( $row['dest_path'], $row['source_path'], true ) ) {
                    throw new \RuntimeException( 'Failed to move file during restore.' );
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->insert(Database::movesTable(), [
                    'file_id'      => (int) $row['id'],
                    'operation_id' => $opId,
                    'direction'    => MoveDirection::Restore->value,
                    'source_path'  => $row['dest_path'],
                    'dest_path'    => $row['source_path'],
                    'file_size'    => (int) $row['file_size'],
                    'is_dry_run'   => 0,
                    'moved_at'     => current_time('mysql'),
                ]);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(Database::filesTable(), [
                    'status'   => 'orphan',
                    'moved_at' => null,
                ], ['id' => (int) $row['id']]);

                $processed++;
                $progress?->tick();
            } catch (\Throwable $e) {
                $errors++;
                Database::appendOperationError($opId, 'Failed to restore: ' . $e->getMessage());
                // translators: %s is the error message describing why the file restore failed.
                \WP_CLI::warning( sprintf( __( 'Failed: %s', 'refaxination' ), $e->getMessage() ) );
            }
        }

        $progress?->finish();

        Database::completeOperation($opId);

        // translators: %1$s is the number of files successfully restored; %2$s is the error count.
        \WP_CLI::success( sprintf( __( '%1$s files restored. %2$s errors.', 'refaxination' ), number_format( $processed ), $errors ) );
    }

    private function moveFile(string $sourcePath): void
    {
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $absSource = $this->uploadsDir . $sourcePath;
        $absDest   = $this->orphansDir . $sourcePath;

        if ( ! $wp_filesystem->move( $absSource, $absDest, true ) ) {
            // Fallback: copy + verify + delete (cross-device move)
            if ( ! copy( $absSource, $absDest ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal runtime exception, not rendered to the browser.
                throw new \RuntimeException( 'copy() failed for: ' . $sourcePath );
            }

            if ( filesize( $absDest ) !== filesize( $absSource ) ) {
                wp_delete_file( $absDest );
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal runtime exception, not rendered to the browser.
                throw new \RuntimeException( 'Size mismatch after copy for: ' . $sourcePath );
            }

            wp_delete_file( $absSource );
        }
    }

    private function ensureParentDir(string $relativePath): void
    {
        $dir = $this->orphansDir . dirname($relativePath);

        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
            $index = $dir . '/index.php';
            if (! file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
    }

    private function ensureUploadsParentDir(string $relativePath): void
    {
        $dir = $this->uploadsDir . dirname($relativePath);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    private function ensureOrphansIndex(): void
    {
        if (! is_dir($this->orphansDir)) {
            wp_mkdir_p($this->orphansDir);
        }

        $index = $this->orphansDir . 'index.php';
        if (! file_exists($index)) {
            file_put_contents($index, '<?php // Silence is golden');
        }
    }
}
