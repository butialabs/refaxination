<?php

declare(strict_types=1);

namespace Refaxination\Enum;

enum FileStatus: string
{
    case Pending    = 'pending';
    case Referenced = 'referenced';
    case LibraryOnly     = 'library_only';
    case Orphan     = 'orphan';
    case Moved      = 'moved';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => __('Pending', 'refaxination'),
            self::Referenced => __('Referenced', 'refaxination'),
            self::LibraryOnly     => __('Library Only', 'refaxination'),
            self::Orphan     => __('Orphan', 'refaxination'),
            self::Moved      => __('Moved', 'refaxination'),
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending    => 'rfx-badge rfx-badge--pending',
            self::Referenced => 'rfx-badge rfx-badge--referenced',
            self::LibraryOnly     => 'rfx-badge rfx-badge--wp-only',
            self::Orphan     => 'rfx-badge rfx-badge--orphan',
            self::Moved      => 'rfx-badge rfx-badge--moved',
        };
    }
}
