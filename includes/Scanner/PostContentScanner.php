<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Database;
use Refaxination\Enum\SourceType;

class PostContentScanner implements ScannerInterface
{
    public function getSourceType(): SourceType
    {
        return SourceType::PostContent;
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
        foreach ($fileBatch as $file) {
            $pathIndex[$file['relative_path']] = (int) $file['id'];
        }

        $idIndex = [];
        foreach ($fileBatch as $file) {
            if (! empty($file['attachment_id'])) {
                $idIndex[(int) $file['attachment_id']] = (int) $file['id'];
            }
        }

        $inserted  = 0;
        $chunkSize = 200;
        $idLike = '%' . $wpdb->esc_like( '"id":' ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $maxPostId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(ID) FROM {$wpdb->posts}
                 WHERE post_type NOT IN ('attachment','revision','auto-draft')
                   AND post_content LIKE %s
                    OR post_content LIKE %s",
                '%' . $wpdb->esc_like($uploadsPath) . '%',
                $idLike,
            )
        );

        for ($offset = 1; $offset <= $maxPostId; $offset += $chunkSize) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                     WHERE ID BETWEEN %d AND %d
                       AND post_type NOT IN ('attachment', 'revision', 'auto-draft')
                       AND post_status NOT IN ('auto-draft')
                       AND (post_content LIKE %s OR post_content LIKE %s)",
                    $offset,
                    $offset + $chunkSize - 1,
                    '%' . $wpdb->esc_like($uploadsPath) . '%',
                    '%"id":%',
                ),
                ARRAY_A
            );

            foreach ($posts as $post) {
                $postId  = (int) $post['ID'];
                $content = $post['post_content'];

                // Match any host + uploads path to capture cross-domain URLs (e.g. production imports)
                preg_match_all(
                    '~https?://[^/\s\'"<>]+' . preg_quote($uploadsPath, '~') . '([^\s\'"<>\)\\\\]+)~i',
                    $content,
                    $urlMatches
                );

                foreach (array_unique($urlMatches[1]) as $relativePath) {
                    $relativePath = rtrim($relativePath, '/');

                    if (! isset($pathIndex[$relativePath])) {
                        continue;
                    }

                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$refsTable}
                         (file_id, source_type, source_id, context)
                         VALUES (%d, %s, %d, %s)",
                        $pathIndex[$relativePath],
                        SourceType::PostContent->value,
                        $postId,
                        'url_in_content',
                    ));
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $inserted++;
                }

                // Gutenberg block attachment IDs: {"id":NNN}
                preg_match_all('/"id"\s*:\s*(\d+)/', $content, $blockMatches);

                foreach (array_unique($blockMatches[1]) as $blockId) {
                    $attId  = (int) $blockId;
                    $fileId = $idIndex[$attId] ?? null;

                    if ($fileId === null) {
                        continue;
                    }

                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$refsTable}
                         (file_id, source_type, source_id, context)
                         VALUES (%d, %s, %d, %s)",
                        $fileId,
                        SourceType::PostContent->value,
                        $postId,
                        'block_id',
                    ));
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $inserted++;
                }

                unset($post, $content, $urlMatches, $blockMatches);
            }

            unset($posts);
        }

        return $inserted;
    }
}
