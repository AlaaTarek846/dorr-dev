<?php

namespace App\Support\Translations;

use App\Enums\TranslationPlatform;
use App\Models\TranslationFile;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runtime read side of published translations, cached forever and invalidated by bumping a
 * generation number (publish, discard, language changes). Cached values are JSON strings
 * decoded with json_decode on read.
 */
final class PublishedTranslations
{
    private const GENERATION_KEY = 'translations.generation';

    /** @var list<string>|null */
    private ?array $backendLocales = null;

    /**
     * Source locales plus active languages that have at least one published backend file.
     *
     * @return list<string>
     */
    public function backendLocales(): array
    {
        return $this->backendLocales ??= $this->resolveBackendLocales();
    }

    /**
     * @return list<string>
     */
    private function resolveBackendLocales(): array
    {
        $sources = self::sourceLocales();

        try {
            $dynamic = Cache::rememberForever($this->key('backend_locales'), fn () => TranslationFile::query()
                ->where('platform', TranslationPlatform::Backend->value)
                ->whereNotNull('published_at')
                ->whereHas('language', fn ($query) => $query->where('status', true))
                ->with('language:id,code')
                ->get()
                ->map(fn (TranslationFile $file) => strtolower((string) $file->language?->code))
                ->filter()
                ->unique()
                ->values()
                ->all());
        } catch (Throwable) {
            return $sources;
        }

        return array_values(array_unique([...$sources, ...$dynamic]));
    }

    /**
     * Published lines of one group for a dashboard-managed locale ([] for source locales).
     *
     * @return array<mixed>
     */
    public function lines(string $locale, TranslationPlatform $platform, string $group): array
    {
        $locale = strtolower($locale);

        if (in_array($locale, self::sourceLocales(), true) || ! $platform->hasGroup($group)) {
            return [];
        }

        try {
            $json = Cache::rememberForever(
                $this->key("lines.{$locale}.{$platform->value}.{$group}"),
                fn () => $this->readPublished($locale, $platform, $group),
            );
        } catch (Throwable) {
            return [];
        }

        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function version(string $locale, TranslationPlatform $platform, string $group): ?int
    {
        $file = $this->publishedFile($locale, $platform, $group);

        return $file?->version;
    }

    /**
     * Stable version of a language's Android bundle: changes whenever any Android group is
     * (re)published. Unpublished groups count as version 0.
     *
     * @param  array<string, int|null>  $groupVersions  group => published version
     */
    public static function androidVersion(array $groupVersions): string
    {
        return sha1(implode('|', array_map(
            fn (string $group) => $group.':'.(int) ($groupVersions[$group] ?? 0),
            TranslationPlatform::Android->groups(),
        )));
    }

    public function flush(): void
    {
        Cache::forever(self::GENERATION_KEY, (int) Cache::get(self::GENERATION_KEY, 0) + 1);
        $this->backendLocales = null;
    }

    /**
     * @return list<string>
     */
    public static function sourceLocales(): array
    {
        return array_map('strtolower', (array) config('translations.source_locales', ['ar', 'en']));
    }

    private function readPublished(string $locale, TranslationPlatform $platform, string $group): string
    {
        $file = $this->publishedFile($locale, $platform, $group);

        if ($file === null) {
            return '';
        }

        $contents = $file->contents(TranslationFile::PUBLISHED_COLLECTION);

        return $contents === [] ? '' : (string) json_encode($contents, JSON_UNESCAPED_UNICODE);
    }

    private function publishedFile(string $locale, TranslationPlatform $platform, string $group): ?TranslationFile
    {
        return TranslationFile::query()
            ->where('platform', $platform->value)
            ->where('group', $group)
            ->whereNotNull('published_at')
            ->whereHas('language', fn ($query) => $query->where('status', true)->where('code', $locale))
            ->first();
    }

    private function key(string $suffix): string
    {
        return 'translations.'.(int) Cache::get(self::GENERATION_KEY, 0).'.'.$suffix;
    }
}
