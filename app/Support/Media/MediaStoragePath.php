<?php

namespace App\Support\Media;

use Illuminate\Database\Eloquent\Model;

class MediaStoragePath
{
    /**
     * Resolve the storage folder segment before model id and file name.
     *
     * General catalog models: general/{table}
     * Module models: {module}/{table}
     *
     * Example: general/dashboard_themes/5/preview_image.jpg
     */
    public static function folderForModel(Model $model): string
    {
        $class = $model::class;

        if (str_starts_with($class, 'App\\Models\\')) {
            return 'general/'.$model->getTable();
        }

        if (preg_match('/^Modules\\\\([^\\\\]+)\\\\Models\\\\/', $class, $matches)) {
            return strtolower($matches[1]).'/'.$model->getTable();
        }

        return strtolower(class_basename($class));
    }
}
