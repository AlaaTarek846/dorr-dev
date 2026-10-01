<?php

namespace App\Repositories\General;

use App\Enums\TranslationPlatform;
use App\Models\Language;
use App\Models\TranslationFile;
use Illuminate\Support\Collection;

class TranslationFileRepository
{
    public function language(int|string $id): Language
    {
        return Language::query()->with(['translations', 'translation'])->findOrFail($id);
    }

    /**
     * @return Collection<int, TranslationFile>
     */
    public function forLanguage(Language $language): Collection
    {
        return TranslationFile::query()
            ->with('media')
            ->where('language_id', $language->id)
            ->get();
    }

    public function find(Language $language, TranslationPlatform $platform, string $group): ?TranslationFile
    {
        return TranslationFile::query()
            ->with('media')
            ->where('language_id', $language->id)
            ->where('platform', $platform->value)
            ->where('group', $group)
            ->first();
    }

    public function firstOrNew(Language $language, TranslationPlatform $platform, string $group): TranslationFile
    {
        return $this->find($language, $platform, $group) ?? new TranslationFile([
            'language_id' => $language->id,
            'platform' => $platform->value,
            'group' => $group,
        ]);
    }

    /**
     * Active languages whose interface can be shown: source locales that store translations
     * (same rule as the languages dropdown) and dashboard languages with a published Vue file.
     *
     * @param  list<string>  $sourceLocales
     * @return Collection<int, Language>
     */
    public function interfaceLanguages(array $sourceLocales): Collection
    {
        return Language::query()
            ->with(['translations', 'translation', 'flag'])
            ->with(['translationFiles' => fn ($query) => $query
                ->where('platform', TranslationPlatform::Vue->value)
                ->whereNotNull('published_at')])
            ->where('status', true)
            ->where(fn ($query) => $query
                ->where(fn ($source) => $source->whereIn('code', $sourceLocales)->where('stores_translation', true))
                ->orWhereHas('translationFiles', fn ($files) => $files
                    ->where('platform', TranslationPlatform::Vue->value)
                    ->whereNotNull('published_at')))
            ->orderBy('id')
            ->get();
    }

    /**
     * Android app languages: the source locales (bundled in the APK, so always listed) and active
     * languages whose Android `strings` group is published. Published Android files are eager-loaded
     * for the version hash.
     *
     * @param  list<string>  $sourceLocales
     * @return Collection<int, Language>
     */
    public function androidLanguages(array $sourceLocales): Collection
    {
        return Language::query()
            ->with(['translations', 'translation', 'flag'])
            ->with(['translationFiles' => fn ($query) => $query
                ->where('platform', TranslationPlatform::Android->value)
                ->whereNotNull('published_at')])
            ->where(fn ($query) => $query
                ->whereIn('code', $sourceLocales)
                ->orWhere(fn ($dynamic) => $dynamic
                    ->where('status', true)
                    ->whereHas('translationFiles', fn ($files) => $files
                        ->where('platform', TranslationPlatform::Android->value)
                        ->where('group', 'strings')
                        ->whereNotNull('published_at'))))
            ->orderBy('id')
            ->get();
    }

    public function activeLanguageByCode(string $code): ?Language
    {
        return Language::query()
            ->where('status', true)
            ->where('code', $code)
            ->first();
    }
}
