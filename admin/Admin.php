<?php

declare(strict_types=1);

namespace Refaxination\Admin;

use Refaxination\Database;

class Admin
{
    public function registerHooks(): void
    {
        add_action('admin_menu',            [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_refaxination_status_poll', [$this, 'ajaxStatusPoll']);
    }

    public function addMenu(): void
    {
        add_management_page(
            __('Refaxination', 'refaxination'),
            __('Refaxination', 'refaxination'),
            'manage_options',
            'refaxination',
            [$this, 'renderPage'],
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'tools_page_refaxination') {
            return;
        }

        wp_enqueue_style(
            'refaxination-admin',
            REFAXINATION_URL . 'admin/assets/refaxination.css',
            [],
            REFAXINATION_VERSION,
        );

        wp_enqueue_script(
            'refaxination-admin',
            REFAXINATION_URL . 'admin/assets/refaxination.js',
            ['wp-i18n'],
            REFAXINATION_VERSION,
            true,
        );

        wp_set_script_translations('refaxination-admin', 'refaxination', REFAXINATION_DIR . 'languages/');

        wp_localize_script('refaxination-admin', 'refaxinationAdmin', [
            'nonce'   => wp_create_nonce('refaxination_status_poll'),
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die( esc_html__( 'Access denied.', 'refaxination' ) );
        }

        $tab = sanitize_key($_GET['tab'] ?? 'dashboard'); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, no state changes

        $tabs = [
            'dashboard'  => __('Dashboard', 'refaxination'),
            'files'      => __('Files', 'refaxination'),
            'operations' => __('Operations', 'refaxination'),
        ];

        if (! array_key_exists($tab, $tabs)) {
            $tab = 'dashboard';
        }

        $viewFile = REFAXINATION_DIR . 'admin/views/' . $tab . '.php';
        $running  = Database::getRunningOperation();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Refaxination', 'refaxination') . '</h1>';

        include REFAXINATION_DIR . 'admin/views/partials/running-operation.php';

        // Tab navigation
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $tabKey => $tabLabel) {
            $url    = add_query_arg(['page' => 'refaxination', 'tab' => $tabKey], admin_url('tools.php'));
            $active = $tab === $tabKey ? ' nav-tab-active' : '';
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url( $url ),
                esc_attr( $active ),
                esc_html( $tabLabel ),
            );
        }
        echo '</nav>';

        echo '<div class="rfx-tab-content">';
        if (file_exists($viewFile)) {
            include $viewFile;
        }
        echo '</div>';

        echo '</div>';
    }

    public function ajaxStatusPoll(): void
    {
        check_ajax_referer('refaxination_status_poll');

        if (! current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        $op = Database::getRunningOperation();

        if (! $op) {
            wp_send_json_success(null);
            return;
        }

        $processed = (int) $op->items_processed;
        $total     = (int) $op->items_total;
        $pct       = $total > 0 ? round($processed / $total * 100, 1) : 0;

        // Infer current phase for scan_files (no schema change needed)
        $phase = 'running';
        if ($op->operation_type === 'scan_files') {
            if ($total === 0) {
                $phase = 'counting';
            } elseif ($processed >= $total) {
                $phase = 'thumbnails';
            } else {
                $phase = 'indexing';
            }
        }

        wp_send_json_success([
            'id'              => (int) $op->id,
            'operation_type'  => $op->operation_type,
            'items_processed' => $processed,
            'items_total'     => $total,
            'pct'             => $pct,
            'status'          => $op->status,
            'phase'           => $phase,
            'started_at'      => $op->started_at,
        ]);
    }
}
