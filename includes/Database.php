<?php

declare(strict_types=1);

namespace Refaxination;

class Database
{
    public const DB_VERSION    = '1.0.0';
    public const OPTION_KEY    = 'refaxination_db_version';

    public static function tableName(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . 'refaxination_' . $suffix;
    }

    public static function filesTable(): string    { return self::tableName('files'); }
    public static function refsTable(): string     { return self::tableName('references'); }
    public static function opsTable(): string      { return self::tableName('operations'); }
    public static function movesTable(): string    { return self::tableName('moves'); }

    public static function install(): void
    {
        global $wpdb;

        $installed   = get_option(self::OPTION_KEY, '0.0.0');
        $tablesExist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::filesTable() ) ) !== null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if (version_compare($installed, self::DB_VERSION, '>=') && $tablesExist) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $files = self::filesTable();
        dbDelta("CREATE TABLE {$files} (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  relative_path   VARCHAR(767)    NOT NULL,
  filename        VARCHAR(255)    NOT NULL DEFAULT '',
  extension       VARCHAR(20)     NOT NULL DEFAULT '',
  file_size       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  mime_type       VARCHAR(127)    NOT NULL DEFAULT '',
  is_thumbnail    TINYINT(1)      NOT NULL DEFAULT 0,
  parent_id       BIGINT UNSIGNED          DEFAULT NULL,
  attachment_id   BIGINT UNSIGNED          DEFAULT NULL,
  status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
  scanned_refs_at DATETIME                 DEFAULT NULL,
  moved_at        DATETIME                 DEFAULT NULL,
  first_seen_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_relative_path (relative_path(191)),
  KEY idx_status (status),
  KEY idx_attachment_id (attachment_id),
  KEY idx_parent_id (parent_id),
  KEY idx_is_thumbnail (is_thumbnail),
  KEY idx_extension (extension),
  KEY idx_scanned_refs_at (scanned_refs_at)
) {$charset};");

        $refs = self::refsTable();
        dbDelta("CREATE TABLE {$refs} (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id     BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(20)     NOT NULL,
  source_id   BIGINT UNSIGNED          DEFAULT NULL,
  meta_key    VARCHAR(255)             DEFAULT NULL,
  context     VARCHAR(255)             DEFAULT NULL,
  found_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_file_id (file_id),
  KEY idx_source_type (source_type),
  KEY idx_source_id (source_id),
  UNIQUE KEY uq_ref (file_id, source_type, source_id, meta_key(191))
) {$charset};");

        $ops = self::opsTable();
        dbDelta("CREATE TABLE {$ops} (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operation_type  VARCHAR(64)     NOT NULL,
  status          VARCHAR(20)     NOT NULL DEFAULT 'running',
  batch_size      INT UNSIGNED    NOT NULL DEFAULT 100,
  items_total     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  items_processed BIGINT UNSIGNED NOT NULL DEFAULT 0,
  items_skipped   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  items_error     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  resume_cursor   TEXT                     DEFAULT NULL,
  options_json    TEXT                     DEFAULT NULL,
  error_log       MEDIUMTEXT               DEFAULT NULL,
  started_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME                 DEFAULT NULL,
  duration_secs   INT UNSIGNED             DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_operation_type (operation_type),
  KEY idx_status (status),
  KEY idx_started_at (started_at)
) {$charset};");

        $moves = self::movesTable();
        dbDelta("CREATE TABLE {$moves} (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id        BIGINT UNSIGNED NOT NULL,
  operation_id   BIGINT UNSIGNED NOT NULL,
  direction      VARCHAR(20)     NOT NULL,
  source_path    VARCHAR(767)    NOT NULL DEFAULT '',
  dest_path      VARCHAR(767)    NOT NULL DEFAULT '',
  file_size      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_dry_run     TINYINT(1)      NOT NULL DEFAULT 0,
  moved_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_file_id (file_id),
  KEY idx_operation_id (operation_id),
  KEY idx_direction (direction),
  KEY idx_is_dry_run (is_dry_run),
  KEY idx_moved_at (moved_at)
) {$charset};");

        update_option(self::OPTION_KEY, self::DB_VERSION);
    }

    public static function uninstall(bool $dropMoves = false): void
    {
        global $wpdb;

        foreach ([self::filesTable(), self::refsTable(), self::opsTable()] as $table) {
            $escapedTable = esc_sql( $table );
            $wpdb->query( "DROP TABLE IF EXISTS `{$escapedTable}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        }

        if ($dropMoves) {
            $movesTable = esc_sql( self::movesTable() );
            $wpdb->query( "DROP TABLE IF EXISTS `{$movesTable}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        }

        delete_option(self::OPTION_KEY);
    }

    public static function startOperation(string $type, int $batchSize, array $options = []): int
    {
        global $wpdb;

        $opsTable = esc_sql( self::opsTable() );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "UPDATE `{$opsTable}` SET status = 'interrupted', completed_at = %s WHERE status = 'running' AND operation_type = %s",
            current_time('mysql'),
            $type,
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $wpdb->insert(self::opsTable(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'operation_type' => $type,
            'status'         => 'running',
            'batch_size'     => $batchSize,
            'options_json'   => wp_json_encode($options),
            'started_at'     => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }

    public static function updateOperation(int $opId, array $data): void
    {
        global $wpdb;
        $wpdb->update(self::opsTable(), $data, ['id' => $opId]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    public static function completeOperation(int $opId, string $status = 'completed'): void
    {
        global $wpdb;

        $opsTable = esc_sql( self::opsTable() );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $op = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT started_at FROM `{$opsTable}` WHERE id = %d",
            $opId
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $duration = $op ? (int) (time() - strtotime($op->started_at)) : 0;

        $wpdb->update(self::opsTable(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'status'       => $status,
            'completed_at' => current_time('mysql'),
            'duration_secs' => $duration,
        ], ['id' => $opId]);
    }

    public static function appendOperationError(int $opId, string $message): void
    {
        global $wpdb;

        $opsTable = esc_sql( self::opsTable() );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $current = (string) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT error_log FROM `{$opsTable}` WHERE id = %d",
            $opId
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $wpdb->update(self::opsTable(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'error_log' => $current . '[' . current_time('mysql') . '] ' . $message . "\n",
        ], ['id' => $opId]);
    }

    public static function getRunningOperation(): ?object
    {
        global $wpdb;

        $opsTable = esc_sql( self::opsTable() );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM `{$opsTable}` WHERE status = 'running' ORDER BY started_at DESC LIMIT 1"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function getLastOperation(): ?object
    {
        global $wpdb;

        $opsTable = esc_sql( self::opsTable() );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM `{$opsTable}` WHERE status != 'running' ORDER BY completed_at DESC LIMIT 1"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
