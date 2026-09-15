<?php

namespace App\Repositories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LanguageRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'flag'];

    /**
     * Language name translations (en/ar) are not filtered by storable locales.
     */
    protected function shouldFilterTranslationsByStorableLocales(): bool
    {
        return false;
    }

    public function __construct(Language $model)
    {
        $this->model = $model;
    }

    public function dropdown(): Collection
    {
        return $this->index()
            ->where('status', true)
            ->where('stores_translation', true)
            ->get()
            ->map(fn (Language $language) => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->translatedName() ?? $language->code,
                'direction' => $language->direction?->value ?? $language->direction,
                'is_default_dashboard' => (bool) $language->is_default_dashboard,
                'flag' => $language->flag ? [
                    'id' => $language->flag->id,
                    'code' => $language->flag->code,
                ] : null,
            ])
            ->values();
    }

    /**
     * @return list<string>
     */
    public static function storableLocaleCodes(): array
    {
        return Language::query()
            ->where('status', true)
            ->where('stores_translation', true)
            ->pluck('code')
            ->map(fn (string $code) => strtolower($code))
            ->values()
            ->all();
    }

    protected function afterStore(Model $model, array $data): void
    {
        parent::afterStore($model, $data);

        if ($model instanceof Language) {
            $this->syncExclusiveDefaults($model, $data);
            $this->handleTranslationStorageChange($model);
        }
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        parent::afterUpdate($model, $data);

        if ($model instanceof Language) {
            $this->syncExclusiveDefaults($model, $data);
            $this->handleTranslationStorageChange($model);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncExclusiveDefaults(Language $language, array $data): void
    {
        if (! empty($data['is_default_website'])) {
            $this->model->newQuery()
                ->whereKeyNot($language->id)
                ->update(['is_default_website' => false]);
        }

        if (! empty($data['is_default_dashboard'])) {
            $this->model->newQuery()
                ->whereKeyNot($language->id)
                ->update(['is_default_dashboard' => false]);
        }
    }

    protected function handleTranslationStorageChange(Language $language): void
    {
        if ($language->stores_translation && $language->status) {
            return;
        }

        $this->purgeCatalogTranslationsForLocale(strtolower($language->code));
    }

    protected function purgeCatalogTranslationsForLocale(string $locale): void
    {
        foreach (['flag_translations', 'country_translations', 'currency_translations'] as $table) {
            DB::table($table)->where('locale', $locale)->delete();
        }
    }
}
