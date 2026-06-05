<?php

declare(strict_types=1);

namespace Refaxination;

class Refaxination
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void
    {
        Database::install();

        if (is_admin()) {
            (new Admin\Admin())->registerHooks();
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('refaxination', Cli\Cli::class);
            \WP_CLI::add_command('refaxination scan', Cli\ScanCommand::class);
        }
    }
}
