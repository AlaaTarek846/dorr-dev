<?php

namespace Modules\Chat\Support;

use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

/**
 * A file put on another message (forward, a capsule, a reshared status) without copying it,
 * like WhatsApp: a new media row that points at the same file on the disk. Deleting one of them
 * leaves the file for the others (`App\Support\Media\SharedFileRemover`).
 */
class SharedMedia
{
    public static function attach(Media $source, HasMedia $target, string $collection, ?int $order = null): Media
    {
        $copy = $source->replicate(['uuid', 'shared_root_id']);
        $copy->uuid = (string) Str::uuid();
        $copy->model_type = $target->getMorphClass();
        $copy->model_id = $target->getKey();
        $copy->collection_name = $collection;
        $copy->order_column = $order;
        $copy->shared_root_id = $source->getAttribute('shared_root_id') ?? $source->getKey();
        $copy->setCustomProperty('shared_path', $source->getCustomProperty('shared_path')
            ?? rtrim(PathGeneratorFactory::create($source)->getPath($source), '/'));
        $copy->save();

        return $copy;
    }
}
