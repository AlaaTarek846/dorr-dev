<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasTranslations
{
    use ResolvesTranslatableFields;

    /**
     * @var array<string, array<string, string>>
     */
    protected array $pendingTranslations = [];

    abstract public function translations(): HasMany;

    public function translation(): HasOne
    {
        return $this->hasOne($this->translationModel())
            ->where('locale', app()->getLocale());
    }

    abstract protected function translationModel(): string;

    public function translatedName(): ?string
    {
        return $this->translated('name');
    }

    /**
     * Value of a translatable column for the current locale, with a fallback
     * to any available locale so a record is never blank in the API.
     */
    public function translated(string $field): ?string
    {
        if ($this->relationLoaded('translation') && $this->translation) {
            return $this->translation->{$field};
        }

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', app()->getLocale())?->{$field}
                ?? $this->translations->first()?->{$field};
        }

        return null;
    }

    /**
     * @param  array<string, array<string, string>>  $fields
     */
    public function fillAllTranslations(array $fields): static
    {
        $this->pendingTranslations = array_merge($this->pendingTranslations, $fields);

        return $this;
    }

    protected static function bootHasTranslations(): void
    {
        static::saved(function (Model $model): void {
            if (! method_exists($model, 'syncPendingTranslations')) {
                return;
            }

            $model->syncPendingTranslations();
        });
    }

    protected function syncPendingTranslations(): void
    {
        if ($this->pendingTranslations === []) {
            return;
        }

        $allowed = $this->translatableFields();

        foreach ($this->pendingTranslations as $field => $locales) {
            if (! in_array($field, $allowed, true)) {
                continue;
            }

            foreach ($locales as $locale => $value) {
                $this->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [$field => $value],
                );
            }
        }

        $this->pendingTranslations = [];
    }
}
