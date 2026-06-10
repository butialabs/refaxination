<?php

declare(strict_types=1);

namespace Refaxination;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Refaxination\Enum\OperationType;

class FileScanner
{
    private Filesystem $fs;
    private string     $uploadsDir;

    public function __construct()
    {
        $upload         = wp_upload_dir();
        $this->uploadsDir = trailingslashit($upload['basedir']);

        $adapter   = new LocalFilesystemAdapter($this->uploadsDir);
        $this->fs  = new Filesystem($adapter);
    }

    public function run(int $batchSize = 100, bool $resume = false): void
    {
        global $wpdb;

        $opId = Database::startOperation(OperationType::ScanFiles->value, $batchSize, [
            'resume' => $resume,
        ]);

        $cursor     = null;
        $processed  = 0;
        $errors     = 0;
        $batch      = [];

        if ($resume) {
            $lastOp = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT resume_cursor FROM %i WHERE operation_type = %s AND status = %s ORDER BY started_at DESC LIMIT 1",
                    Database::opsTable(),
                    'scan_files',
                    'interrupted'
                )
            );
            $cursor = $lastOp?->resume_cursor;

            if ($cursor) {
                // translators: %s is the file path cursor used to resume the scan.
                \WP_CLI::line( sprintf( __( 'Resuming from: %s', 'refaxination' ), $cursor ) );
            }
        }

        // Drain any output buffers WordPress opened so that WP_CLI::line() reaches
        // the terminal immediately instead of being held until script exit.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Count total first so progress shows X / N from the start
        \WP_CLI::line(__('Counting files...', 'refaxination'));
        $total = 0;
        foreach ($this->fs->listContents('', true)->filter(fn(StorageAttributes $attrs) => $attrs->isFile()) as $item) {
            if (! $this->shouldIgnore($item->path())) {
                $total++;
            }
        }
        // translators: %s is the formatted number of files found in the uploads directory.
        \WP_CLI::line( sprintf( __( '  %s files to index.', 'refaxination' ), number_format( $total ) ) );
        Database::updateOperation($opId, ['items_total' => $total]);

        $progress = \WP_CLI\Utils\make_progress_bar(__('Indexing', 'refaxination'), $total);

        $listing = $this->fs->listContents('', true)
            ->filter(fn(StorageAttributes $attrs) => $attrs->isFile())
            ->sortByPath();

        $skipping = $cursor !== null;

        foreach ($listing as $item) {
            $path = $item->path();

            if ($this->shouldIgnore($path)) {
                continue;
            }

            if ($skipping) {
                if ($path <= $cursor) {
                    $progress->tick(); // advance bar past already-processed files
                    continue;
                }
                $skipping = false;
            }

            try {
                $batch[] = $this->buildRow($path);
            } catch (\Throwable $e) {
                $errors++;
                Database::appendOperationError($opId, "Error on {$path}: " . $e->getMessage());
            }

            $progress->tick();

            if (count($batch) >= $batchSize) {
                $this->insertBatch($batch);
                $processed += count($batch);
                $cursor = end($batch)['relative_path'];
                $batch  = [];

                Database::updateOperation($opId, [
                    'items_processed' => $processed,
                    'items_error'     => $errors,
                    'resume_cursor'   => $cursor,
                ]);
            }
        }

        $progress->finish();

        if ($batch !== []) {
            $this->insertBatch($batch);
            $processed += count($batch);
        }

        Database::updateOperation($opId, [
            'items_processed' => $processed,
            'items_total'     => $total,
            'items_error'     => $errors,
            'resume_cursor'   => null,
        ]);

        \WP_CLI::line(__('Detecting thumbnails...', 'refaxination'));
        [$thumbCount, $parentCount] = $this->detectThumbnails();
        // translators: %1$d is the number of thumbnails found, %2$d is the number of parent images.
        \WP_CLI::line( sprintf( __( '%1$d thumbnails linked to %2$d parent images.', 'refaxination' ), $thumbCount, $parentCount ) );

        Database::completeOperation($opId);

        \WP_CLI::success( sprintf(
            // translators: %1$s is the formatted count of indexed files, %2$s is the number of errors.
            __( 'Scan complete. %1$s files indexed. %2$s errors.', 'refaxination' ),
            number_format( $processed ),
            $errors
        ) );
    }

    private function buildRow(string $path): array
    {
        $absolutePath = $this->uploadsDir . $path;
        $filename     = basename($path);
        $extension    = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $fileSize     = filesize($absolutePath) ?: 0;
        $mimeType     = mime_content_type($absolutePath) ?: 'application/octet-stream';

        return [
            'relative_path' => $path,
            'filename'      => $filename,
            'extension'     => $extension,
            'file_size'     => $fileSize,
            'mime_type'     => $mimeType,
            'is_thumbnail'  => 0,
            'status'        => 'pending',
            'first_seen_at' => current_time('mysql'),
        ];
    }

    private function insertBatch(array $rows): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'START TRANSACTION' );

        $table = Database::filesTable();
        foreach ($rows as $row) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO %i
                 (relative_path, filename, extension, file_size, mime_type, is_thumbnail, status, first_seen_at)
                 VALUES (%s, %s, %s, %d, %s, %d, %s, %s)",
                $table,
                $row['relative_path'],
                $row['filename'],
                $row['extension'],
                $row['file_size'],
                $row['mime_type'],
                $row['is_thumbnail'],
                $row['status'],
                $row['first_seen_at'],
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query('COMMIT');
    }

    private function detectThumbnails(): array
    {
        global $wpdb;

        $candidates = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT id, relative_path, filename, extension FROM %i WHERE is_thumbnail = 0 AND mime_type LIKE 'image/%%'",
                Database::filesTable()
            ),
            ARRAY_A
        );

        $thumbCount  = 0;
        $parentPaths = [];

        foreach ($candidates as $row) {
            $parent = $this->parseParentPath($row['relative_path'], $row['filename'], $row['extension']);
            if ($parent !== null) {
                $parentPaths[$row['id']] = $parent;
            }
        }

        foreach (array_chunk($parentPaths, 200, preserve_keys: true) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $paths        = array_values($chunk);

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $parents = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, relative_path FROM %i WHERE relative_path IN ({$placeholders})",
                    Database::filesTable(),
                    ...$paths
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

            $parentIndex = array_column($parents, 'id', 'relative_path');

            foreach ($chunk as $thumbId => $parentPath) {
                if (isset($parentIndex[$parentPath])) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update(Database::filesTable(), [
                        'is_thumbnail' => 1,
                        'parent_id'    => $parentIndex[$parentPath],
                    ], ['id' => $thumbId]);
                    $thumbCount++;
                }
            }

            if (count($parentIndex) > 0) {
                // Unique parent count is set size of parentIndex values
            }
        }

        $parentCount = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT parent_id) FROM %i WHERE parent_id IS NOT NULL',
                Database::filesTable()
            )
        );

        return [$thumbCount, $parentCount];
    }

    private function parseParentPath(string $relativePath, string $filename, string $ext): ?string
    {
        $dir      = dirname($relativePath);
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        // Pattern: name-WxH.ext  (e.g. image-300x200.jpg)
        if (preg_match('/^(.+)-(\d+)x(\d+)$/', $baseName, $m)) {
            $parentFilename = $m[1] . '.' . $ext;
            return ($dir !== '.' ? $dir . '/' : '') . $parentFilename;
        }

        // Pattern: name-scaled.ext or name-rotated.ext
        if (preg_match('/^(.+)-(scaled|rotated)$/', $baseName, $m)) {
            $parentFilename = $m[1] . '.' . $ext;
            return ($dir !== '.' ? $dir . '/' : '') . $parentFilename;
        }

        return null;
    }

    private function shouldIgnore(string $path): bool
    {
        $basename = basename($path);

        if (in_array($basename, ['index.php', '.DS_Store', 'Thumbs.db', '.gitkeep'], strict: true)) {
            return true;
        }

        if (str_contains($path, '/orphans/') || str_starts_with($path, 'orphans/')) {
            return true;
        }

        return false;
    }
}
