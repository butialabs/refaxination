<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

global $wpdb;

$perPage     = 50; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$currentPage = max(1, intval( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$offset      = ($currentPage - 1) * $perPage; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$statusFilter = sanitize_key( wp_unslash( $_GET['status_filter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$typeFilter   = sanitize_key( wp_unslash( $_GET['type_filter'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$search       = sanitize_text_field( wp_unslash( $_GET['rfx_search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$validStatuses = ['pending', 'referenced', 'library_only', 'orphan', 'moved']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$validTypes    = ['image', 'video', 'audio', 'document', 'other']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$where     = ['is_thumbnail = 0']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$queryArgs = [\Refaxination\Database::filesTable()]; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if (in_array($statusFilter, $validStatuses, strict: true)) {
    $where[]     = 'status = %s';
    $queryArgs[] = $statusFilter;
}

if (in_array($typeFilter, $validTypes, strict: true)) {
    $typeCondition = match ($typeFilter) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        'image'    => "mime_type LIKE 'image/%%'",
        'video'    => "mime_type LIKE 'video/%%'",
        'audio'    => "mime_type LIKE 'audio/%%'",
        'document' => "mime_type IN ('application/pdf','application/msword',
                          'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                          'application/vnd.ms-excel',
                          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')",
        'other'    => "mime_type NOT LIKE 'image/%%' AND mime_type NOT LIKE 'video/%%'
                       AND mime_type NOT LIKE 'audio/%%'
                       AND mime_type NOT IN ('application/pdf','application/msword',
                          'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                          'application/vnd.ms-excel',
                          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')",
        default    => null,
    };
    if ($typeCondition) {
        $where[] = "({$typeCondition})"; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    }
}

if ($search !== '') {
    $where[]     = 'relative_path LIKE %s';
    $queryArgs[] = '%' . $wpdb->esc_like($search) . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $wpdb->prepare( "SELECT COUNT(*) FROM %i {$whereClause}", ...$queryArgs )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows = $wpdb->get_results( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $wpdb->prepare(
        "SELECT id, relative_path, filename, file_size, mime_type, status,
                attachment_id, is_thumbnail, parent_id
         FROM %i {$whereClause}
         ORDER BY relative_path ASC
         LIMIT %d OFFSET %d",
        ...[...$queryArgs, $perPage, $offset]
    ),
    ARRAY_A
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$totalPages = (int) ceil($total / $perPage); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Batch-load references and thumbnails for files on this page (avoids N+1)
$refsByFile        = []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$thumbnailsByParent = []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$fileIds           = array_column($rows ?? [], 'id'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ($fileIds !== []) {
    $placeholders = implode(',', array_fill(0, count($fileIds), '%d')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $refs         = $wpdb->get_results( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $wpdb->prepare(
            "SELECT file_id, source_type, source_id, context
             FROM %i
             WHERE file_id IN ({$placeholders}) AND source_type != 'attachment'
             ORDER BY source_type, source_id",
            \Refaxination\Database::refsTable(),
            ...$fileIds
        ),
        ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    foreach ($refs as $ref) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $refs loaded above
        $refsByFile[(int) $ref['file_id']][] = $ref; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    }

    $thumbPlaceholders = implode(',', array_fill(0, count($fileIds), '%d')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $thumbRows         = $wpdb->get_results( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $wpdb->prepare(
            "SELECT id, relative_path, file_size, mime_type, status, parent_id
             FROM %i
             WHERE is_thumbnail = 1 AND parent_id IN ({$thumbPlaceholders})
             ORDER BY parent_id, relative_path",
            \Refaxination\Database::filesTable(),
            ...$fileIds
        ),
        ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    foreach ($thumbRows as $thumb) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $thumbnailsByParent[(int) $thumb['parent_id']][] = $thumb; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    }
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function refaxination_human_bytes_view(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $pow   = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function refaxination_mime_icon(string $mime): string
{
    return match (true) {
        str_starts_with($mime, 'image/') => '🖼',
        str_starts_with($mime, 'video/') => '🎬',
        str_starts_with($mime, 'audio/') => '🎵',
        $mime === 'application/pdf'       => '📄',
        default                           => '📎',
    };
}

$baseUrl    = add_query_arg(['page' => 'refaxination', 'tab' => 'files'], admin_url('tools.php')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$uploadsUrl = trailingslashit(wp_upload_dir()['baseurl']); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>

<form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
    <input type="hidden" name="page" value="refaxination">
    <input type="hidden" name="tab" value="files">
    <div class="rfx-filters">
        <label for="rfx-status-filter"><?php esc_html_e('Status:', 'refaxination'); ?></label>
        <select name="status_filter" id="rfx-status-filter">
            <option value=""><?php esc_html_e('All', 'refaxination'); ?></option>
            <?php foreach (['pending' => __('Pending', 'refaxination'), 'referenced' => __('Referenced', 'refaxination'), 'library_only' => __('Library Only', 'refaxination'), 'orphan' => __('Orphan', 'refaxination'), 'moved' => __('Moved', 'refaxination')] as $val => $lbl) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
            <option value="<?php echo esc_attr($val); ?>" <?php selected($statusFilter, $val); ?>>
                <?php echo esc_html($lbl); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label for="rfx-type-filter"><?php esc_html_e('Type:', 'refaxination'); ?></label>
        <select name="type_filter" id="rfx-type-filter">
            <option value=""><?php esc_html_e('All', 'refaxination'); ?></option>
            <?php foreach (['image' => __('Image', 'refaxination'), 'video' => __('Video', 'refaxination'), 'audio' => __('Audio', 'refaxination'), 'document' => __('Document', 'refaxination'), 'other' => __('Other', 'refaxination')] as $val => $lbl) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
            <option value="<?php echo esc_attr($val); ?>" <?php selected($typeFilter, $val); ?>>
                <?php echo esc_html($lbl); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <input type="search" name="rfx_search" value="<?php echo esc_attr($search); ?>"
               placeholder="<?php esc_attr_e('Search by path...', 'refaxination'); ?>"
               style="min-width:200px">

        <button type="submit" class="button"><?php esc_html_e('Filter', 'refaxination'); ?></button>
        <?php if ($statusFilter || $typeFilter || $search) : ?>
        <a href="<?php echo esc_url($baseUrl); ?>" class="button button-secondary">
            <?php esc_html_e('Clear', 'refaxination'); ?>
        </a>
        <?php endif; ?>
    </div>
</form>

<p class="rfx-result-count">
    <?php
    // translators: %s is the number of files found.
    echo esc_html( sprintf( _n( '%s file found.', '%s files found.', $total, 'refaxination' ), number_format( $total ) ) );
    ?>
</p>

<?php if ($rows) : ?>
<div class="rfx-table-wrap">
<table class="rfx-table widefat">
    <thead>
        <tr>
            <th>ID</th>
            <th><?php esc_html_e('File', 'refaxination'); ?></th>
            <th><?php esc_html_e('Type', 'refaxination'); ?></th>
            <th><?php esc_html_e('Size', 'refaxination'); ?></th>
            <th><?php esc_html_e('Status', 'refaxination'); ?></th>
            <th><?php esc_html_e('References', 'refaxination'); ?></th>
            <th><?php esc_html_e('Library', 'refaxination'); ?></th>
            <th><?php esc_html_e('Thumbnails', 'refaxination'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $statusClass = match ($row['status']) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            'referenced' => 'rfx-badge--referenced',
            'library_only'    => 'rfx-badge--wp-only',
            'orphan'     => 'rfx-badge--orphan',
            'moved'      => 'rfx-badge--moved',
            default      => 'rfx-badge--pending',
        };
    ?>
    <?php $thumbs = $thumbnailsByParent[(int) $row['id']] ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
    <tr>
        <td data-label="ID"><?php echo (int) $row['id']; ?></td>
        <td data-label="File" class="rfx-path">
            <?php
            $fileUrl  = $uploadsUrl . $row['relative_path']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            $isImage  = str_starts_with($row['mime_type'], 'image/'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            ?>
            <a href="<?php echo esc_url($fileUrl); ?>"
               target="_blank"
               rel="noopener"
               title="<?php echo esc_attr($row['relative_path']); ?>"
               <?php if ($isImage) : ?>data-preview="<?php echo esc_url($fileUrl); ?>"<?php endif; ?>
               class="rfx-file-link"><?php echo esc_html(mb_strimwidth($row['relative_path'], 0, 70, '…')); ?></a>
        </td>
        <td data-label="Type" title="<?php echo esc_attr($row['mime_type']); ?>">
            <?php echo refaxination_mime_icon( $row['mime_type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns hardcoded emoji characters, no user input ?>
        </td>
        <td data-label="Size"><?php echo esc_html(refaxination_human_bytes_view((int) $row['file_size'])); ?></td>
        <td data-label="Status">
            <span class="rfx-badge <?php echo esc_attr($statusClass); ?>">
                <?php echo esc_html($row['status']); ?>
            </span>
        </td>
        <td data-label="References" class="rfx-refs-cell">
            <?php
            $fileRefs = $refsByFile[(int) $row['id']] ?? []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            if ($fileRefs === []) {
                echo '&mdash;';
            } else {
                foreach ($fileRefs as $ref) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                    $label = esc_html( $ref['source_type'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                    if ((int) $ref['source_id'] > 0) {
                        $editUrl = get_edit_post_link( (int) $ref['source_id'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        if ($editUrl) {
                            echo '<a href="' . esc_url( $editUrl ) . '" target="_blank" class="rfx-ref-tag">'
                                . esc_html( $label ) . ' <span class="rfx-ref-id">#' . (int) $ref['source_id'] . '</span>'
                                . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML structure is hardcoded; $label is double-escaped via esc_html(), source_id cast to int
                        } else {
                            echo '<span class="rfx-ref-tag">' . esc_html( $label ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML structure is hardcoded; $label is double-escaped via esc_html()
                        }
                    } else {
                        $ctx = $ref['context'] ? ' <span class="rfx-ref-id">(' . esc_html( $ref['context'] ) . ')</span>' : ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        echo '<span class="rfx-ref-tag">' . esc_html( $label ) . $ctx . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML structure is hardcoded; $label double-escaped, $ctx uses esc_html()
                    }
                }
            }
            ?>
        </td>
        <td data-label="Library">
            <?php if ($row['attachment_id']) :
                $editUrl = get_edit_post_link((int) $row['attachment_id']); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            ?>
                <a href="<?php echo esc_url((string) $editUrl); ?>" target="_blank">
                    #<?php echo (int) $row['attachment_id']; ?>
                </a>
            <?php else : ?>
                &mdash;
            <?php endif; ?>
        </td>
        <td data-label="Thumbnails">
            <?php if ($thumbs !== []) : ?>
                <button class="rfx-thumb-toggle button button-small" data-target="rfx-thumbs-<?php echo (int) $row['id']; ?>" aria-expanded="false">
                    <span class="dashicons dashicons-arrow-right"></span>
                    <?php echo count($thumbs); ?>
                </button>
            <?php else : ?>
                &mdash;
            <?php endif; ?>
        </td>
    </tr>
    <?php if ($thumbs !== []) : ?>
    <tr id="rfx-thumbs-<?php echo (int) $row['id']; ?>" class="rfx-thumb-row" hidden>
        <td colspan="8">
            <table class="rfx-thumb-table widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Path', 'refaxination'); ?></th>
                        <th><?php esc_html_e('Size', 'refaxination'); ?></th>
                        <th><?php esc_html_e('Status', 'refaxination'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($thumbs as $thumb) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                    $thumbStatusClass = match ($thumb['status']) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        'referenced' => 'rfx-badge--referenced',
                        'library_only'    => 'rfx-badge--wp-only',
                        'orphan'     => 'rfx-badge--orphan',
                        'moved'      => 'rfx-badge--moved',
                        default      => 'rfx-badge--pending',
                    };
                ?>
                <tr>
                    <td class="rfx-path" title="<?php echo esc_attr($thumb['relative_path']); ?>">
                        <?php echo esc_html(mb_strimwidth($thumb['relative_path'], 0, 70, '…')); ?>
                    </td>
                    <td><?php echo esc_html(refaxination_human_bytes_view((int) $thumb['file_size'])); ?></td>
                    <td>
                        <span class="rfx-badge <?php echo esc_attr($thumbStatusClass); ?>">
                            <?php echo esc_html($thumb['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1) : ?>
<div class="rfx-pagination">
    <?php if ($currentPage > 1) : ?>
        <a href="<?php echo esc_url(add_query_arg('paged', $currentPage - 1, $baseUrl)); ?>" class="button">
            &laquo; <?php esc_html_e('Previous', 'refaxination'); ?>
        </a>
    <?php endif; ?>

    <span><?php
    printf(
        // translators: %1$d is the current page number, %2$d is the total number of pages.
        esc_html__( 'Page %1$d of %2$d', 'refaxination' ),
        (int) $currentPage,
        (int) $totalPages
    );
    ?></span>

    <?php if ($currentPage < $totalPages) : ?>
        <a href="<?php echo esc_url(add_query_arg('paged', $currentPage + 1, $baseUrl)); ?>" class="button">
            <?php esc_html_e('Next', 'refaxination'); ?> &raquo;
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php else : ?>
<div class="notice notice-info inline"><p><?php esc_html_e('No files found with the applied filters.', 'refaxination'); ?></p></div>
<?php endif; ?>
