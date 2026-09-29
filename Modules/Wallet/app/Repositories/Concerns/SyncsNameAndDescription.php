<?php

namespace Modules\Wallet\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The shared SyncsTranslations concern only persists `name`. Wallet catalogs
 * (payment methods, fee rules) also carry a `description`, so this overrides
 * the write side to include it (the read side is each model's Resource).
 * Use it *after* SyncsTranslations is in the parent chain — it only replaces
 * syncTranslations().
 */
trait SyncsNameAndDescription
{
    protected function syncTranslations(Model $model, array $data): void
    {
        if (! isset($data['translations'])) {
            return;
        }

        $allowedLocales = $this->storableTranslationLocales();

        foreach ($data['translations'] as $translation) {
            if (! isset($translation['locale'], $translation['name'])) {
                continue;
            }

            $locale = strtolower((string) $translation['locale']);

            if (! in_array($locale, $allowedLocales, true)) {
                continue;
            }

            $model->translations()->updateOrCreate(
                ['locale' => $locale],
                ['name' => $translation['name'], 'description' => $translation['description'] ?? null],
            );
        }

        $model->translations()->whereNotIn('locale', $allowedLocales ?: [''])->delete();
    }
}
