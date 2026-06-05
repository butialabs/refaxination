<?php

declare(strict_types=1);

namespace Refaxination\ValueObject;

use Refaxination\Enum\SourceType;

readonly class FileReference
{
    public function __construct(
        public int        $fileId,
        public SourceType $sourceType,
        public ?int       $sourceId,
        public ?string    $metaKey,
        public ?string    $context,
    ) {}
}
