<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

global $wpdb;

$opsTable = esc_sql( \Refaxination\Database::opsTable() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$ops = $wpdb->get_results(
    "SELECT * FROM `{$opsTable}` ORDER BY started_at DESC LIMIT 50",
    ARRAY_A
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function rfx_duration_str(?int $secs): string
{
    if ($secs === null) return '-';
    return gmdate('H:i:s', $secs);
}
?>

<h2><?php esc_html_e('Operation history', 'refaxination'); ?></h2>

<?php if ($ops) : ?>
<div class="rfx-table-wrap">
<table class="rfx-ops-table widefat">
    <thead>
        <tr>
            <th>ID</th>
            <th><?php esc_html_e('Type', 'refaxination'); ?></th>
            <th><?php esc_html_e('Status', 'refaxination'); ?></th>
            <th><?php esc_html_e('Processed', 'refaxination'); ?></th>
            <th><?php esc_html_e('Total', 'refaxination'); ?></th>
            <th><?php esc_html_e('Errors', 'refaxination'); ?></th>
            <th><?php esc_html_e('Duration', 'refaxination'); ?></th>
            <th><?php esc_html_e('Started at', 'refaxination'); ?></th>
            <th><?php esc_html_e('Completed at', 'refaxination'); ?></th>
            <th><?php esc_html_e('Options', 'refaxination'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($ops as $op) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $statusClass = match ($op['status']) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            'running'     => 'rfx-status-running',
            'completed'   => 'rfx-status-completed',
            'failed'      => 'rfx-status-failed',
            default       => 'rfx-status-interrupted',
        };
        $pct = $op['items_total'] > 0 // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            ? round($op['items_processed'] / $op['items_total'] * 100, 1)
            : 0;
        $options = $op['options_json'] ? json_decode($op['options_json'], true) : []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    ?>
    <tr>
        <td data-label="ID"><?php echo (int) $op['id']; ?></td>
        <td data-label="Type"><code><?php echo esc_html($op['operation_type']); ?></code></td>
        <td data-label="Status">
            <span class="<?php echo esc_attr($statusClass); ?>">
                <?php echo esc_html($op['status']); ?>
            </span>
            <?php if ($op['status'] === 'running') : ?>
            <span class="rfx-progress-wrap">
                <span class="rfx-progress-fill" style="width: <?php echo (float) $pct; ?>%"></span>
            </span>
            <?php endif; ?>
        </td>
        <td data-label="Processed"><?php echo number_format((int) $op['items_processed']); ?></td>
        <td data-label="Total"><?php echo $op['items_total'] > 0 ? number_format((int) $op['items_total']) : '?'; ?></td>
        <td data-label="Errors">
            <?php if ((int) $op['items_error'] > 0) : ?>
                <span style="color:#c62828"><?php echo (int) $op['items_error']; ?></span>
                <?php if ($op['error_log']) : ?>
                    <span class="rfx-error-toggle" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'block' ? 'none' : 'block'">
                        <?php esc_html_e('[ver]', 'refaxination'); ?>
                    </span>
                    <pre class="rfx-error-log"><?php echo esc_html($op['error_log']); ?></pre>
                <?php endif; ?>
            <?php else : ?>
                0
            <?php endif; ?>
        </td>
        <td data-label="Duration"><?php echo esc_html(rfx_duration_str(isset($op['duration_secs']) ? (int) $op['duration_secs'] : null)); ?></td>
        <td data-label="Started"><?php echo esc_html($op['started_at']); ?></td>
        <td data-label="Completed"><?php echo esc_html($op['completed_at'] ?? '-'); ?></td>
        <td data-label="Options">
            <?php if (is_array($options) && $options !== []) :
                $flags = []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                if (! empty($options['dry_run']))         $flags[] = 'dry-run'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                if (! empty($options['include_library_only'])) $flags[] = 'include-wp-only'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                if (! empty($options['resume']))          $flags[] = 'resume'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                if (! empty($options['sources']))         $flags[] = implode(',', (array) $options['sources']); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
            ?>
                <small><?php echo esc_html(implode(' · ', $flags)); ?></small>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php else : ?>
<div class="notice notice-info inline">
    <p><?php esc_html_e('No operations recorded yet. Run a WP-CLI command to get started.', 'refaxination'); ?></p>
</div>
<?php endif; ?>

