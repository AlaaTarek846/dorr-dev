<?php

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SyncsSeedTranslations
{
    /**
     * Sync a name-only translation set.
     *
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

    /**
     * Sync an arbitrary translation field set (locale => field => value).
     *
     * @param  array<string, array<string, mixed>>  $translations
     */
    protected function syncTranslationFields(Model $model, array $translations): void
    {
        foreach ($translations as $locale => $fields) {
            $model->translations()->updateOrCreate(
                ['locale' => $locale],
                $fields,
            );
        }
    }
}
