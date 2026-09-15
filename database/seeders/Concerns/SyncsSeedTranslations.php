<?php

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SyncsSeedTranslations
{
    /**
     * @param  array<string, string>  $translations
     */
    protected function syncTranslations(Model $model, array $translations): void
    {
        foreach ($translations as $locale => $name) {
            $model->translations()->updateOrCreate(
                ['locale' => $locale],
                ['name' => $name],
            );
        }
    }
}
