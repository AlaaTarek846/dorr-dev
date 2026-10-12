<?php

namespace App\Support\Media;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class ModelFolderPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/responsive-images/';
    }

    protected function getBasePath(Media $media): string
    {
        $folder = $this->resolveFolder($media);
        $path = "{$folder}/{$media->model_id}";
        $prefix = config('media-library.prefix', '');

        if ($prefix !== '') {
            return "{$prefix}/{$path}";
        }

        return $path;
    }

    protected function resolveFolder(Media $media): string
    {
        $model = $media->model;

        if ($model instanceof Model && method_exists($model, 'mediaStorageFolder')) {
            return $model->mediaStorageFolder();
        }

        return strtolower(class_basename((string) $media->model_type));
    }
}
