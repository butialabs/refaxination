<?php

declare(strict_types=1);

namespace Refaxination\Enum;

enum MoveDirection: string
{
    case Quarantine = 'quarantine';
    case Restore    = 'restore';
}
