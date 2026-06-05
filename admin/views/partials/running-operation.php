<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// $running must be set by the caller (Admin::renderPage)
if (empty($running)) {
    return;
}

$phase = __('Running', 'refaxination'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
if ($running->operation_type === 'scan_files') {
    if ((int) $running->items_total === 0) {
        $phase = __('Counting files…', 'refaxination'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    } elseif ((int) $running->items_processed >= (int) $running->items_total) {
        $phase = __('Detecting thumbnails…', 'refaxination'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    } else {
        $phase = __('Indexing', 'refaxination'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    }
}

$pct = $running->items_total > 0 // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    ? round($running->items_processed / $running->items_total * 100, 1)
    : 0;
?>
<div class="rfx-live-op" id="rfx-live-operation" data-op-id="<?php echo (int) $running->id; ?>">
    <p>
        <strong><?php esc_html_e('Running operation:', 'refaxination'); ?></strong>
        <code><?php echo esc_html($running->operation_type); ?></code>
        <?php esc_html_e('started at', 'refaxination'); ?> <?php echo esc_html($running->started_at); ?>
    </p>
    <p>
        <strong><?php esc_html_e('Status:', 'refaxination'); ?></strong>
        <span id="rfx-phase-label"><?php echo esc_html($phase); ?></span>
    </p>
    <p>
        <span id="rfx-live-processed"><?php echo number_format((int) $running->items_processed); ?></span>
        /
        <span id="rfx-live-total"><?php echo $running->items_total > 0 ? number_format((int) $running->items_total) : '?'; ?></span>
        <?php esc_html_e('files', 'refaxination'); ?>
        <div class="rfx-progress-bar-wrap">
            <div class="rfx-progress-bar" id="rfx-progress-bar" style="width: <?php echo (float) $pct; ?>%"></div>
        </div>
        <span id="rfx-live-pct"><?php echo (float) $pct; ?>%</span>
    </p>
</div>
