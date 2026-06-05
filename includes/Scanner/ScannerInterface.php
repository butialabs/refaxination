<?php

declare(strict_types=1);

namespace Refaxination\Scanner;

use Refaxination\Enum\SourceType;

interface ScannerInterface
{
    public function getSourceType(): SourceType;

    /**
     * @param  array<int, array{id: int, relative_path: string, attachment_id: int|null}> $fileBatch
     */
    public function scan(array $fileBatch): int;

    public function isAvailable(): bool;
}
