<?php

namespace App\Repositories\Concerns;

use App\Repositories\General\LanguageRepository;
use Illuminate\Database\Eloquent\Model;

trait SyncsTranslations
{
    protected function shouldFilterTranslationsByStorableLocales(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    protected function storableTranslationLocales(): array
    {
        return LanguageRepository::storableLocaleCodes();
    }
    /**
     * @return list<string>
     */
    protected function reservedPayloadKeys(): array
    {
        return array_merge(parent::reservedPayloadKeys(), ['translations']);
    }

    protected function afterStore(Model $model, array $data): void
    {
        parent::afterStore($model, $data);
        $this->syncTranslations($model, $data);
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        parent::afterUpdate($model, $data);
        $this->syncTranslations($model, $data);
    }

    protected function beforeForceDestroy(Model $model): void
    {
        if (method_exists($model, 'translations')) {
            $model->translations()->delete();
        }

        parent::beforeForceDestroy($model);
    }

    protected function syncTranslations(Model $model, array $data): void
    {
        if (! isset($data['translations']) || ! method_exists($model, 'translations')) {
            return;
        }

        $fields = $this->translatableFields($model);

        if ($fields === []) {
            return;
        }

        $allowedLocales = $this->shouldFilterTranslationsByStorableLocales()
            ? $this->storableTranslationLocales()
            : null;

        foreach ($data['translations'] as $translation) {
            if (! isset($translation['locale'])) {
                continue;
            }

            $values = array_intersect_key($translation, array_flip($fields));

            if ($values === []) {
                continue;
            }

            $locale = strtolower((string) $translation['locale']);

            if ($allowedLocales !== null && ! in_array($locale, $allowedLocales, true)) {
                continue;
            }

            $model->translations()->updateOrCreate(
                ['locale' => $locale],
                $values,
            );
        }

        if ($allowedLocales !== null) {
            if ($allowedLocales === []) {
                $model->translations()->delete();
            } else {
                $model->translations()->whereNotIn('locale', $allowedLocales)->delete();
            }
        }
    }

    /**
     * Translatable columns taken from the translation model, minus the locale
     * and the parent foreign key.
     *
     * @return list<string>
     */
    protected function translatableFields(Model $model): array
    {
        if (! method_exists($model, 'translatableFields')) {
            return ['name'];
        }

        /** @var list<string> $fields */
        $fields = $model->translatableFields();

        return $fields;
    }
}
