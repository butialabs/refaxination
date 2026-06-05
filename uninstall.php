<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Preserve the moves audit table by default.
// Set 'refaxination_uninstall_drop_moves' to true in wp-config.php to also drop it.
$dropMoves = (bool) (defined('REFAXINATION_UNINSTALL_DROP_MOVES') && REFAXINATION_UNINSTALL_DROP_MOVES); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

\Refaxination\Database::uninstall(dropMoves: $dropMoves);
