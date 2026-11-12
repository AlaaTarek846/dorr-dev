<?php

namespace Modules\AI\Models\Concerns;

use App\Repositories\General\LanguageRepository;

/**
 * Back-compat for a catalog model whose customer-facing text (name, description) moved to a translation table -
 * the same rules as AiPlan:
 * - `$model->name / description` read the current language's translation (else any language's, never blank);
 * - setting them flat (`create(['name' => 'X'])`: seeders, tests, older code) writes that text into every stored
 *   language - the admin screen sends `translations[]` instead (TranslatableRepository);
 * - `$model->is_active` reads / writes `status` (the catalog's on/off column);
 * - isAvailable(): switched on and not in the trash = can be sold / renewed.
 *
 * The model also uses HasTranslations (translations(), translationModel()) and SoftDeletes, and drops its cached
 * translation relations after saving (see the models' booted()).
 */
trait HasFlatTranslatedText
{
    /** Open for new sales and renewals: switched on and not in the trash. */
    public function isAvailable(): bool
    {
        return (bool) $this->status && ! $this->trashed();
    }

    public function getNameAttribute(): ?string
    {
        return $this->translatedValue('name');
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->translatedValue('description');
    }

    public function setNameAttribute(?string $value): void
    {
        $this->queueFlatTranslation('name', $value);
    }

    public function setDescriptionAttribute(?string $value): void
    {
        $this->queueFlatTranslation('description', $value);
    }

    public function getIsActiveAttribute(): bool
    {
        return (bool) ($this->attributes['status'] ?? false);
    }

    public function setIsActiveAttribute(mixed $value): void
    {
        $this->attributes['status'] = (bool) $value;
    }

    /** Current-language value of a translated column, else any language's (never blank while one exists). */
    private function translatedValue(string $field): mixed
    {
        if (! $this->relationLoaded('translation') && ! $this->relationLoaded('translations')) {
            if (! $this->exists) {
                return null;
            }

            $this->load('translations');
        }

        $translation = $this->relationLoaded('translation') && $this->translation
            ? $this->translation
            : ($this->translations->firstWhere('locale', app()->getLocale()) ?? $this->translations->first());

        return $translation?->{$field};
    }

    /**
     * HasTranslations writes one field at a time; a new translation row needs its (NOT NULL) name in the same
     * insert, so write each language's queued fields together.
     */
    protected function syncPendingTranslations(): void
    {
        if ($this->pendingTranslations === []) {
            return;
        }

        $allowed = $this->translatableFields();
        $byLocale = [];

        foreach ($this->pendingTranslations as $field => $locales) {
            if (! in_array($field, $allowed, true)) {
                continue;
            }

            foreach ($locales as $locale => $value) {
                $byLocale[$locale][$field] = $value;
            }
        }

        $this->pendingTranslations = [];

        // A translation table without a name column (AiFeatureFlag: description only) gets no name placeholder.
        $hasName = in_array('name', $allowed, true);

        foreach ($byLocale as $locale => $values) {
            $existing = $this->translations()->where('locale', $locale)->first();

            if ($existing) {
                $existing->update($values);
            } else {
                $this->translations()->create(['locale' => $locale] + ($hasName ? ['name' => $values['name'] ?? (string) $this->code] : []) + $values);
            }
        }
    }

    private function queueFlatTranslation(string $field, mixed $value): void
    {
        $locales = LanguageRepository::storableLocaleCodes()
            ?: array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'en')]));

        $this->fillAllTranslations([$field => array_fill_keys($locales, $value)]);
    }
}
