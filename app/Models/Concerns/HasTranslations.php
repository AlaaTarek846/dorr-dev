<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasTranslations
{
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
        if ($this->relationLoaded('translation') && $this->translation) {
            return $this->translation->name;
        }

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', app()->getLocale())?->name
                ?? $this->translations->first()?->name;
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

        foreach ($this->pendingTranslations as $field => $locales) {
            if ($field !== 'name') {
                continue;
            }

            foreach ($locales as $locale => $value) {
                $this->translations()->updateOrCreate(
                    ['locale' => $locale],
                    ['name' => $value],
                );
            }
        }

        $this->pendingTranslations = [];
    }
}
