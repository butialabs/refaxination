<?php

declare(strict_types=1);

namespace Refaxination\ValueObject;

use Refaxination\Enum\FileStatus;

readonly class FileEntry
{
    public function __construct(
        public int        $id,
        public string     $relativePath,
        public string     $filename,
        public string     $extension,
        public int        $fileSize,
        public string     $mimeType,
        public bool       $isThumbnail,
        public ?int       $parentId,
        public ?int       $attachmentId,
        public FileStatus $status,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->mimeType, 'audio/');
    }

    public function isDocument(): bool
    {
        return in_array($this->mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], strict: true);
    }

    public function typeGroup(): string
    {
        return match (true) {
            $this->isImage()    => 'image',
            $this->isVideo()    => 'video',
            $this->isAudio()    => 'audio',
            $this->isDocument() => 'document',
            default             => 'other',
        };
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max(0, $this->fileSize);
        $pow   = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $pow   = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int) $row['id'],
            relativePath: $row['relative_path'],
            filename:     $row['filename'],
            extension:    $row['extension'],
            fileSize:     (int) $row['file_size'],
            mimeType:     $row['mime_type'],
            isThumbnail:  (bool) $row['is_thumbnail'],
            parentId:     isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            attachmentId: isset($row['attachment_id']) ? (int) $row['attachment_id'] : null,
            status:       FileStatus::from($row['status']),
        );
    }
}
