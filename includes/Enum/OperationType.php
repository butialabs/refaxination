<?php

declare(strict_types=1);

namespace Refaxination\Enum;

enum OperationType: string
{
    case ScanFiles  = 'scan_files';
    case ScanRefs   = 'scan_refs';
    case Quarantine = 'quarantine';
    case Restore    = 'restore';
    case Reset      = 'reset';

    public function label(): string
    {
        return match ($this) {
            self::ScanFiles  => 'scan files',
            self::ScanRefs   => 'scan refs',
            self::Quarantine => 'quarantine',
            self::Restore    => 'restore',
            self::Reset      => 'reset',
        };
    }
}
