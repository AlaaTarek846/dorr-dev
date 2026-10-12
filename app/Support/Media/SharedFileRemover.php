<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;

/**
 * Leaves a file alone while another media row still uses it (a forwarded chat file shares the
 * first one's file — see `Modules\Chat\Support\SharedMedia`); the last row to go removes it.
 */
class SharedFileRemover extends DefaultFileRemover
{
    public function removeAllFiles(Media $media): void
    {
        if ($this->stillUsed($media)) {
            return;
        }

        parent::removeAllFiles($media);
    }

    private function stillUsed(Media $media): bool
    {
        $root = $media->getAttribute('shared_root_id') ?? $media->getKey();

        return Media::query()
            ->whereKeyNot($media->getKey())
            ->where(fn ($q) => $q->whereKey($root)->orWhere('shared_root_id', $root))
            ->exists();
    }
}
