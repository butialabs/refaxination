<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

global $wpdb;

$stats = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->prepare(
        'SELECT status, COUNT(*) AS cnt, SUM(file_size) AS total_bytes FROM %i GROUP BY status',
        \Refaxination\Database::filesTable()
    ),
    ARRAY_A
);

$byStatus   = array_column($stats, null, 'status'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$grandTotal = array_sum(array_column($stats, 'cnt')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$grandBytes = array_sum(array_column($stats, 'total_bytes')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$lastOp = \Refaxination\Database::getLastOperation(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function refaxination_human_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow   = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
}

$statuses = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    'referenced'   => ['label' => __('Referenced', 'refaxination'),   'color' => '#4CAF50', 'desc' => __('Used in posts, pages or options', 'refaxination')],
    'library_only' => ['label' => __('Library Only', 'refaxination'), 'color' => '#FF9800', 'desc' => __('In the Media Library but not referenced in content', 'refaxination')],
    'orphan'       => ['label' => __('Orphan', 'refaxination'),       'color' => '#F44336', 'desc' => __('No references found', 'refaxination')],
    'moved'        => ['label' => __('Moved', 'refaxination'),        'color' => '#9E9E9E', 'desc' => __('Already sent to orphans/', 'refaxination')],
    'pending'      => ['label' => __('Pending', 'refaxination'),      'color' => '#2196F3', 'desc' => __('Waiting for reference scan', 'refaxination')],
];
?>

<h2><?php esc_html_e('File summary', 'refaxination'); ?></h2>

<div class="rfx-stats-grid">
    <?php foreach ($statuses as $key => $info) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $cnt   = (int) ($byStatus[$key]['cnt']        ?? 0); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $bytes = (int) ($byStatus[$key]['total_bytes'] ?? 0); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    ?>
    <div class="rfx-stat-card" style="border-left-color: <?php echo esc_attr($info['color']); ?>">
        <h3><?php echo esc_html($info['label']); ?></h3>
        <div class="rfx-count"><?php echo number_format($cnt); ?></div>
        <div class="rfx-size"><?php echo esc_html(refaxination_human_bytes($bytes)); ?></div>
        <div class="rfx-desc"><?php echo esc_html($info['desc']); ?></div>
    </div>
    <?php endforeach; ?>
    <div class="rfx-stat-card" style="border-left-color: #23282d">
        <h3><?php esc_html_e('Total', 'refaxination'); ?></h3>
        <div class="rfx-count"><?php echo number_format((int) $grandTotal); ?></div>
        <div class="rfx-size"><?php echo esc_html(refaxination_human_bytes((int) $grandBytes)); ?></div>
        <div class="rfx-desc"><?php esc_html_e('All files in uploads/', 'refaxination'); ?></div>
    </div>
</div>

<?php if ($lastOp) : ?>
<h2><?php esc_html_e('Last completed operation', 'refaxination'); ?></h2>
<div class="rfx-info-grid">
    <div class="rfx-info-box">
        <dt><?php esc_html_e('Type', 'refaxination'); ?></dt>
        <dd><code><?php echo esc_html($lastOp->operation_type); ?></code></dd>
    </div>
    <div class="rfx-info-box">
        <dt><?php esc_html_e('Status', 'refaxination'); ?></dt>
        <dd><?php echo esc_html($lastOp->status); ?></dd>
    </div>
    <div class="rfx-info-box">
        <dt><?php esc_html_e('Duration', 'refaxination'); ?></dt>
        <dd><?php echo $lastOp->duration_secs ? esc_html(gmdate('H:i:s', (int) $lastOp->duration_secs)) : '-'; ?></dd>
    </div>
    <div class="rfx-info-box">
        <dt><?php esc_html_e('Processed', 'refaxination'); ?></dt>
        <dd><?php echo number_format((int) $lastOp->items_processed); ?></dd>
    </div>
    <div class="rfx-info-box">
        <dt><?php esc_html_e('Errors', 'refaxination'); ?></dt>
        <dd><?php echo (int) $lastOp->items_error; ?></dd>
    </div>
    <div class="rfx-info-box">
        <dt><?php esc_html_e('Completed at', 'refaxination'); ?></dt>
        <dd><?php echo esc_html($lastOp->completed_at ?? '-'); ?></dd>
    </div>
</div>
<?php endif; ?>

<h2><?php esc_html_e('WP-CLI command builder', 'refaxination'); ?></h2>
<p style="color:#555;font-size:13px;margin-bottom:0"><?php esc_html_e('Select a command, configure its options, and copy the result to your terminal. All actions run via SSH/WP-CLI, this interface is read-only.', 'refaxination'); ?></p>

<div class="rfx-cli-gen" id="rfx-cli-gen">

    <div class="rfx-cmd-tabs" role="tablist">
        <button class="rfx-cmd-tab active" data-cmd="scan-files"  role="tab"><?php esc_html_e('Scan files', 'refaxination'); ?></button>
        <button class="rfx-cmd-tab"        data-cmd="scan-refs"   role="tab"><?php esc_html_e('Scan references', 'refaxination'); ?></button>
        <button class="rfx-cmd-tab"        data-cmd="quarantine"  role="tab"><?php esc_html_e('Quarantine', 'refaxination'); ?></button>
        <button class="rfx-cmd-tab"        data-cmd="restore"     role="tab"><?php esc_html_e('Restore', 'refaxination'); ?></button>
        <button class="rfx-cmd-tab"        data-cmd="report"      role="tab"><?php esc_html_e('Report', 'refaxination'); ?></button>
        <button class="rfx-cmd-tab"        data-cmd="reset"       role="tab"><?php esc_html_e('Reset', 'refaxination'); ?></button>
        <button class="rfx-cmd-tab"        data-cmd="status"      role="tab"><?php esc_html_e('Status', 'refaxination'); ?></button>
    </div>

    <div class="rfx-cmd-body">

        <!-- SCAN FILES -->
        <div class="rfx-cmd-panel" data-panel="scan-files">
            <p class="rfx-cmd-desc">
                <?php
                /* translators: HTML tags <strong> and <code> must be preserved */
                echo wp_kses_post(__('<strong>Step 1 of 2.</strong> Recursively walks <code>wp-content/uploads/</code>, indexes every file (path, size, MIME type) and automatically detects WordPress-generated thumbnails. Must run before scanning references.', 'refaxination'));
                ?>
            </p>
            <div class="rfx-options-grid">

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="sf-batch-chk">
                        <div>
                            <label for="sf-batch-chk"><?php esc_html_e('Batch size', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--batch=&lt;n&gt;</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <strong> must be preserved */
                        echo wp_kses_post(__('Files inserted per database transaction. Smaller batches use less RAM; larger batches are faster. <strong>Default: 100.</strong> Use 50 or less on low-memory servers.', 'refaxination'));
                        ?>
                    </p>
                    <div class="rfx-option-input-row" id="sf-batch-row" style="display:none">
                        <label for="sf-batch-val"><?php esc_html_e('Files per batch:', 'refaxination'); ?></label>
                        <input type="number" id="sf-batch-val" value="100" min="1" max="1000">
                    </div>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="sf-reset-chk">
                        <div>
                            <label for="sf-reset-chk"><?php esc_html_e('Start fresh', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--reset</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <strong> must be preserved */
                        echo wp_kses_post(__('Clears all previous scan data before starting. Use for a clean re-index. <strong>Cannot be combined with --resume.</strong>', 'refaxination'));
                        ?>
                    </p>
                    <p class="rfx-option-warn" id="sf-reset-warn" style="display:none">
                        <?php esc_html_e('⚠️ All previous scan data will be deleted.', 'refaxination'); ?>
                    </p>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="sf-resume-chk">
                        <div>
                            <label for="sf-resume-chk"><?php esc_html_e('Resume interrupted scan', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--resume</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tags <code> and <strong> must be preserved */
                        echo wp_kses_post(__('Continues from where it stopped after a crash or interruption. Uses the cursor saved in the last <code>interrupted</code> operation. <strong>Cannot be combined with --reset.</strong>', 'refaxination'));
                        ?>
                    </p>
                </div>

            </div>
        </div>

        <!-- SCAN REFS -->
        <div class="rfx-cmd-panel" data-panel="scan-refs" style="display:none">
            <p class="rfx-cmd-desc">
                <?php
                /* translators: HTML tags <strong>, <em> must be preserved */
                echo wp_kses_post(__('<strong>Step 2 of 2.</strong> Searches the database for every place each indexed file is referenced: posts, post meta, theme options, SEO plugins, podcast, and ACF/Yoast fields. Classifies each file as <em>referenced</em>, <em>library only</em>, or <em>orphan</em>. Requires the file scan to have run first.', 'refaxination'));
                ?>
            </p>
            <div class="rfx-options-grid">

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="sr-batch-chk">
                        <div>
                            <label for="sr-batch-chk"><?php esc_html_e('Batch size', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--batch=&lt;n&gt;</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <strong> must be preserved */
                        echo wp_kses_post(__('Files processed per iteration. <strong>Default: 100.</strong> Reduce on high-post-count sites to avoid query timeouts.', 'refaxination'));
                        ?>
                    </p>
                    <div class="rfx-option-input-row" id="sr-batch-row" style="display:none">
                        <label for="sr-batch-val"><?php esc_html_e('Files per batch:', 'refaxination'); ?></label>
                        <input type="number" id="sr-batch-val" value="100" min="1" max="1000">
                    </div>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="sr-resume-chk">
                        <div>
                            <label for="sr-resume-chk"><?php esc_html_e('Resume interrupted scan', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--resume</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <code> must be preserved */
                        echo wp_kses_post(__('Skips files already scanned (where <code>scanned_refs_at</code> is not null). Safe to run multiple times without duplicating references.', 'refaxination'));
                        ?>
                    </p>
                </div>

                <div class="rfx-option" style="grid-column: 1 / -1">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="sr-source-chk">
                        <div>
                            <label for="sr-source-chk"><?php esc_html_e('Restrict scanners', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--source=&lt;list&gt;</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php esc_html_e('By default all available scanners run. Select only the ones you need to speed up the process or re-scan a specific source. Scanners for inactive plugins (ACF, Yoast, TSF, SSP) are skipped automatically.', 'refaxination'); ?>
                    </p>
                    <div class="rfx-sources-grid" id="sr-sources" style="display:none">
                        <?php
                        $sources = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                            'attachment'   => [__('Attachments', 'refaxination'),       __('Populates attachment_id and confirms WP-registered thumbnails', 'refaxination')],
                            'postmeta'     => [__('Post Meta', 'refaxination'),          __('_thumbnail_id and URLs in post meta values', 'refaxination')],
                            'post_content' => [__('Post Content', 'refaxination'),       __('<img>, <a>, Gutenberg blocks and shortcodes in posts/pages', 'refaxination')],
                            'options'      => [__('Options', 'refaxination'),            __('Widgets, theme_mods and wp_options with upload URLs', 'refaxination')],
                            'tsf'          => [__('The SEO Framework', 'refaxination'),  __('Social images from The SEO Framework (TSF)', 'refaxination')],
                            'ssp'          => [__('SSP Podcasting', 'refaxination'),     __('Audio files in Seriously Simple Podcasting', 'refaxination')],
                            'acf'          => [__('ACF', 'refaxination'),                __('Image, file, and gallery fields in Advanced Custom Fields', 'refaxination')],
                            'yoast'        => [__('Yoast SEO', 'refaxination'),          __('og/twitter images in Yoast SEO', 'refaxination')],
                        ];
                        foreach ($sources as $val => [$label, $desc]) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        ?>
                        <label class="rfx-source-chip checked" title="<?php echo esc_attr($desc); ?>">
                            <input type="checkbox" class="rfx-source-input" value="<?php echo esc_attr($val); ?>" checked>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- QUARANTINE -->
        <div class="rfx-cmd-panel" data-panel="quarantine" style="display:none">
            <p class="rfx-cmd-warning">
                <?php
                /* translators: HTML tags <strong>, <code> and <br/> must be preserved */
                echo wp_kses_post(__('<strong>Important:</strong> The operation is <strong>fully reversible</strong> with the Restore command. Every move is logged in the audit table.<br/><strong>BUT</strong> always make a backup beforehand and run with <code>--dry-run</code> first.', 'refaxination'));
                ?>
            </p>
            <p class="rfx-cmd-desc">
                <?php
                /* translators: HTML tags <strong> and <code> must be preserved */
                echo wp_kses_post(__('Moves files classified as <strong>orphans</strong> from <code>uploads/</code> to <code>wp-content/orphans/</code>, preserving the folder structure.', 'refaxination'));
                ?>
            </p>
            <div class="rfx-options-grid">

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="q-dryrun-chk" checked>
                        <div>
                            <label for="q-dryrun-chk"><?php esc_html_e('Dry run', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--dry-run</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <strong> must be preserved */
                        echo wp_kses_post(__('Shows exactly what would be moved <strong>without moving any files</strong>. Strongly recommended before the first real run to validate the orphan list.', 'refaxination'));
                        ?>
                    </p>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="q-batch-chk">
                        <div>
                            <label for="q-batch-chk"><?php esc_html_e('Batch size', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--batch=&lt;n&gt;</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <strong> must be preserved */
                        echo wp_kses_post(__('Files moved per iteration. <strong>Default: 100.</strong> Reduce if the server has a request time limit or limited temporary disk space.', 'refaxination'));
                        ?>
                    </p>
                    <div class="rfx-option-input-row" id="q-batch-row" style="display:none">
                        <label for="q-batch-val"><?php esc_html_e('Files per batch:', 'refaxination'); ?></label>
                        <input type="number" id="q-batch-val" value="100" min="1" max="1000">
                    </div>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="q-library_only-chk">
                        <div>
                            <label for="q-library_only-chk"><?php esc_html_e('Include "library only" files', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--include-wp-only</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <em> must be preserved */
                        echo wp_kses_post(__('By default only <em>orphans</em> are moved. With this option, files that are in the Media Library but not referenced in any content are also included. Use with caution, these files may have been uploaded intentionally.', 'refaxination'));
                        ?>
                    </p>
                    <p class="rfx-option-warn" id="q-library_only-warn" style="display:none">
                        <?php esc_html_e('⚠️ Media Library files not referenced in content will be included.', 'refaxination'); ?>
                    </p>
                </div>

            </div>
        </div>

        <!-- RESTORE -->
        <div class="rfx-cmd-panel" data-panel="restore" style="display:none">
            <p class="rfx-cmd-desc">
                <?php
                /* translators: HTML tags <code> must be preserved */
                echo wp_kses_post(__('Reverses quarantine by moving files back from <code>orphans/</code> to their original paths in <code>uploads/</code>. Source and destination paths are read from the audit log, no information is lost.', 'refaxination'));
                ?>
            </p>
            <div class="rfx-options-grid">

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="r-all-chk" checked>
                        <div>
                            <label for="r-all-chk"><?php esc_html_e('Restore all', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--all</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tags <code> must be preserved */
                        echo wp_kses_post(__('Restores all files with status <code>moved</code> back to their original locations. Incompatible with <code>--file-id</code>.', 'refaxination'));
                        ?>
                    </p>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="r-fileid-chk">
                        <div>
                            <label for="r-fileid-chk"><?php esc_html_e('Specific file', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--file-id=&lt;id&gt;</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tags <code> must be preserved */
                        echo wp_kses_post(__('Restores a single file by its <code>refaxination_files</code> ID. Useful when a specific file was moved by mistake. Incompatible with <code>--all</code>.', 'refaxination'));
                        ?>
                    </p>
                    <div class="rfx-option-input-row" id="r-fileid-row" style="display:none">
                        <label for="r-fileid-val"><?php esc_html_e('File ID:', 'refaxination'); ?></label>
                        <input type="number" id="r-fileid-val" value="" min="1" placeholder="e.g. 4821">
                    </div>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="r-dryrun-chk">
                        <div>
                            <label for="r-dryrun-chk"><?php esc_html_e('Dry run', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--dry-run</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php esc_html_e('Shows what would be restored without moving any files. Useful to confirm paths before the real restore.', 'refaxination'); ?>
                    </p>
                </div>

            </div>
        </div>

        <!-- REPORT -->
        <div class="rfx-cmd-panel" data-panel="report" style="display:none">
            <p class="rfx-cmd-desc">
                <?php esc_html_e('Displays a report of indexed files. Can be filtered by status, grouped by file type, and exported in multiple formats, including CSV for spreadsheet analysis.', 'refaxination'); ?>
            </p>
            <div class="rfx-options-grid">

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="rp-format-chk">
                        <div>
                            <label for="rp-format-chk"><?php esc_html_e('Output format', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--format=&lt;fmt&gt;</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tags <strong> and <br> must be preserved */
                        echo wp_kses_post(__('<strong>table</strong> (default) visual table in the terminal.<br><strong>json</strong> JSON output for scripts and integrations.<br><strong>csv</strong> export to file, ideal for spreadsheets.', 'refaxination'));
                        ?>
                    </p>
                    <div class="rfx-option-input-row" id="rp-format-row" style="display:none">
                        <select id="rp-format-val">
                            <option value="table">table</option>
                            <option value="json">json</option>
                            <option value="csv">csv</option>
                        </select>
                    </div>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="rp-status-chk">
                        <div>
                            <label for="rp-status-chk"><?php esc_html_e('Filter by status', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--status=&lt;status&gt;</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php esc_html_e('Shows only files with the selected status.', 'refaxination'); ?>
                    </p>
                    <div class="rfx-option-input-row" id="rp-status-row" style="display:none">
                        <select id="rp-status-val">
                            <option value="orphan">orphan</option>
                            <option value="library_only">library_only (library only)</option>
                            <option value="referenced">referenced</option>
                            <option value="moved">moved</option>
                            <option value="pending">pending</option>
                        </select>
                    </div>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="rp-group-chk">
                        <div>
                            <label for="rp-group-chk"><?php esc_html_e('Group by type', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--group-by=type</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <code> must be preserved */
                        echo wp_kses_post(__('Aggregates files by MIME category: image, video, audio, document, other. Shows count and total size per group. Cannot be combined with <code>--status</code>.', 'refaxination'));
                        ?>
                    </p>
                </div>

            </div>
        </div>

        <!-- RESET -->
        <div class="rfx-cmd-panel" data-panel="reset" style="display:none">
            <p class="rfx-cmd-desc">
                <?php esc_html_e('Clears scan data to allow a fresh re-index. By default preserves the moves table (audit log). Use with care.', 'refaxination'); ?>
            </p>
            <div class="rfx-options-grid">

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="rs-tables-chk">
                        <div>
                            <label for="rs-tables-chk"><?php esc_html_e('Recreate tables', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--tables</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php esc_html_e('Drops and recreates all plugin tables, including the moves table. Use only if there is a schema problem or data corruption.', 'refaxination'); ?>
                    </p>
                    <p class="rfx-option-warn" id="rs-tables-warn" style="display:none">
                        <?php esc_html_e('⚠️ The entire quarantine move history will be deleted.', 'refaxination'); ?>
                    </p>
                </div>

                <div class="rfx-option">
                    <div class="rfx-option-header">
                        <input type="checkbox" id="rs-confirm-chk">
                        <div>
                            <label for="rs-confirm-chk"><?php esc_html_e('Skip confirmation', 'refaxination'); ?></label><br>
                            <span class="rfx-option-flag">--confirm</span>
                        </div>
                    </div>
                    <p class="rfx-option-help">
                        <?php
                        /* translators: HTML tag <code> must be preserved */
                        echo wp_kses_post(__('By default the command asks for interactive confirmation in the terminal. Use <code>--confirm</code> to skip that step in automated scripts.', 'refaxination'));
                        ?>
                    </p>
                </div>

            </div>
        </div>

        <!-- STATUS -->
        <div class="rfx-cmd-panel" data-panel="status" style="display:none">
            <p class="rfx-cmd-desc">
                <?php esc_html_e('Shows the currently running operation (if any) and a summary of the last completed operation, including processed file count, errors, and duration. Useful for monitoring long scans from another terminal.', 'refaxination'); ?>
            </p>
            <div class="rfx-options-grid">
                <div class="rfx-option" style="grid-column:1/-1">
                    <p style="margin:0;color:#888;font-size:13px;"><?php esc_html_e('No options available, this command takes no arguments.', 'refaxination'); ?></p>
                </div>
            </div>
        </div>

        <div class="rfx-output-wrap">
            <div class="rfx-output-label"><?php esc_html_e('Generated command', 'refaxination'); ?></div>
            <div class="rfx-output-row">
                <div class="rfx-output-cmd" id="rfx-generated-cmd">wp refaxination scan files</div>
                <button class="rfx-copy-btn" id="rfx-copy-btn" type="button">
                    <span id="rfx-copy-icon">&#x2398;</span>
                    <span id="rfx-copy-label"><?php esc_html_e('Copy', 'refaxination'); ?></span>
                </button>
            </div>
            <p class="rfx-output-note" id="rfx-output-note"></p>
        </div>

    </div><!-- .rfx-cmd-body -->
</div><!-- .rfx-cli-gen -->
