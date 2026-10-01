<?php

namespace App\Services\General;

use App\Enums\TranslationFileStatus;
use App\Enums\TranslationPlatform;
use App\Exceptions\TranslationException;
use App\Models\Language;
use App\Models\TranslationFile;
use App\Repositories\General\TranslationFileRepository;
use App\Support\Api\ApiResponse;
use App\Support\Translations\AndroidStringsXml;
use App\Support\Translations\PublishedTranslations;
use App\Support\Translations\TranslationImportValidator;
use App\Support\Translations\TranslationSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Dashboard translation management for non-source languages:
 * export → (translator) → validate → import as draft → publish.
 */
class TranslationService
{
    public function __construct(
        protected TranslationFileRepository $repository,
        protected TranslationSources $sources,
        protected TranslationImportValidator $validator,
        protected PublishedTranslations $published,
    ) {}

    public function overview(int|string $languageId): JsonResponse
    {
        $language = $this->repository->language($languageId);
        $isSource = $this->isSource($language);
        $files = $isSource ? collect() : $this->repository->forLanguage($language);

        $platforms = $isSource ? [] : array_map(fn (TranslationPlatform $platform) => [
            'platform' => $platform->value,
            'groups' => array_map(
                fn (string $group) => $this->groupSummary(
                    $platform,
                    $group,
                    $files->first(fn (TranslationFile $file) => $file->platform === $platform && $file->group === $group),
                ),
                $platform->groups(),
            ),
        ], TranslationPlatform::cases());

        return ApiResponse::success([
            'language' => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->translatedName() ?? $language->code,
                'direction' => $language->direction?->value ?? $language->direction,
                'is_source' => $isSource,
            ],
            'platforms' => $platforms,
        ], __('api.retrieved'));
    }

    public function validateUpload(int|string $languageId, string $platform, string $group, UploadedFile $file): JsonResponse
    {
        [$language, $platformEnum] = $this->managedTarget($languageId, $platform, $group);

        $result = $this->validator->validate($platformEnum, $group, $file);

        if (! $result->valid) {
            throw TranslationException::importInvalid($result->report);
        }

        return ApiResponse::success(
            ['valid' => true, 'report' => $result->report],
            __('api.translations.validated', ['missing' => $result->report['missing_keys']]),
        );
    }

    public function import(int|string $languageId, string $platform, string $group, UploadedFile $upload, ?int $adminId): JsonResponse
    {
        [$language, $platformEnum] = $this->managedTarget($languageId, $platform, $group);

        $result = $this->validator->validate($platformEnum, $group, $upload);

        if (! $result->valid) {
            throw TranslationException::importInvalid($result->report);
        }

        $json = $this->encode($result->contents);
        $file = $this->repository->firstOrNew($language, $platformEnum, $group);

        if (! $file->exists) {
            $file->created_by = $adminId;
        }

        $file->fill([
            'status' => TranslationFileStatus::Draft,
            'checksum' => hash('sha256', $json),
            'updated_by' => $adminId,
        ])->save();

        $file->addMediaFromString($json)
            ->usingFileName(TranslationFile::DRAFT_COLLECTION.'-'.now()->format('YmdHisv').'.json')
            ->usingName($this->mediaName($language, $platformEnum, $group))
            ->toMediaCollection(TranslationFile::DRAFT_COLLECTION);

        return ApiResponse::success(
            [
                'report' => $result->report,
                'file' => $this->groupSummary($platformEnum, $group, $file->fresh('media')),
            ],
            __('api.translations.draft_saved', ['missing' => $result->report['missing_keys']]),
        );
    }

    public function publish(int|string $languageId, string $platform, string $group, ?int $adminId): JsonResponse
    {
        [$language, $platformEnum] = $this->managedTarget($languageId, $platform, $group);

        $file = $this->repository->find($language, $platformEnum, $group);
        $draft = $file?->draftMedia();

        if ($file === null || $draft === null) {
            throw TranslationException::noDraft();
        }

        $json = (string) Storage::disk($draft->disk)->get($draft->getPathRelativeToRoot());
        $version = $file->version + 1;

        $file->addMediaFromString($json)
            ->usingFileName(TranslationFile::PUBLISHED_COLLECTION.'-v'.$version.'.json')
            ->usingName($this->mediaName($language, $platformEnum, $group))
            ->toMediaCollection(TranslationFile::PUBLISHED_COLLECTION);

        $draft->delete();

        $file->update([
            'status' => TranslationFileStatus::Published,
            'checksum' => hash('sha256', $json),
            'version' => $version,
            'published_at' => now(),
            'updated_by' => $adminId,
        ]);

        $this->published->flush();

        return ApiResponse::success(
            $this->groupSummary($platformEnum, $group, $file->fresh('media')),
            __('api.translations.published'),
        );
    }

    public function discardDraft(int|string $languageId, string $platform, string $group, ?int $adminId): JsonResponse
    {
        [$language, $platformEnum] = $this->managedTarget($languageId, $platform, $group);

        $file = $this->repository->find($language, $platformEnum, $group);
        $draft = $file?->draftMedia();

        if ($file === null || $draft === null) {
            throw TranslationException::noDraft();
        }

        $draft->delete();
        $file->load('media');

        if ($file->publishedMedia() === null) {
            $file->delete();
            $file = null;
        } else {
            $file->update(['status' => TranslationFileStatus::Published, 'updated_by' => $adminId]);
        }

        return ApiResponse::success(
            $this->groupSummary($platformEnum, $group, $file),
            __('api.translations.draft_discarded'),
        );
    }

    /**
     * CSV (key, en, ar, translation) or JSON (base structure) of the working copy — draft when
     * there is one, otherwise the published file. `missing` exports only untranslated keys.
     */
    public function export(int|string $languageId, string $platform, string $group, string $format, string $mode): Response
    {
        [$language, $platformEnum] = $this->managedTarget($languageId, $platform, $group);

        $base = $this->sources->units($platformEnum, $group);

        if ($base === []) {
            throw TranslationException::baseUnavailable();
        }

        $reference = $this->sources->units($platformEnum, $group, (string) config('translations.reference_locale', 'ar'));
        $file = $this->repository->find($language, $platformEnum, $group);
        $stored = $file === null ? [] : $this->workingCopy($file);
        $onlyMissing = $mode === 'missing';
        $code = strtolower($language->code);

        $filename = "dorr-{$code}-{$platformEnum->value}-{$group}".($onlyMissing ? '-missing' : '').'.'.$format;

        $content = $format === 'csv'
            ? $this->csv($base, $reference, $stored, $code, $platformEnum, $onlyMissing)
            : $this->encode($this->jsonTemplate($base, $stored, $code, $platformEnum, $onlyMissing), true);

        return response($content, 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * ZIP with values-{qualifier}/strings.xml, chat_strings.xml, wallet_strings.xml generated from the
     * stored Android JSON. `draft` prefers pending drafts (falls back to published per group).
     */
    public function exportAndroid(int|string $languageId, string $source): BinaryFileResponse
    {
        $language = $this->repository->language($languageId);

        if ($this->isSource($language)) {
            throw TranslationException::sourceLocale();
        }

        if (! class_exists(ZipArchive::class)) {
            throw TranslationException::zipUnavailable();
        }

        $code = strtolower($language->code);
        $qualifier = AndroidStringsXml::qualifier($code);
        $files = $this->repository->forLanguage($language)
            ->filter(fn (TranslationFile $file) => $file->platform === TranslationPlatform::Android);

        $documents = [];

        foreach (TranslationPlatform::Android->groups() as $group) {
            $file = $files->first(fn (TranslationFile $item) => $item->group === $group);

            if ($file === null) {
                continue;
            }

            $contents = $source === 'draft' && $file->hasDraft()
                ? $file->contents(TranslationFile::DRAFT_COLLECTION)
                : $file->contents(TranslationFile::PUBLISHED_COLLECTION);

            $entries = [];

            foreach (array_keys($this->sources->units(TranslationPlatform::Android, $group)) as $name) {
                if (isset($contents[$name]) && (is_string($contents[$name]) || is_array($contents[$name]))) {
                    $entries[$name] = $contents[$name];
                }
            }

            if ($entries !== []) {
                $documents["{$qualifier}/{$group}.xml"] = AndroidStringsXml::build($entries, $code);
            }
        }

        if ($documents === []) {
            throw TranslationException::nothingToExport();
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'dorr-android-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($documents as $name => $xml) {
            $zip->addFromString($name, $xml);
        }

        $zip->close();

        return response()
            ->download($path, "dorr-android-{$code}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /**
     * Interface languages for the language switchers: the dashboard (Vue) by default, the
     * Android app with `platform=android`.
     */
    public function interfaceLanguages(?string $platform = null): JsonResponse
    {
        if ($platform === TranslationPlatform::Android->value) {
            return $this->androidLanguages();
        }

        $languages = $this->repository->interfaceLanguages(PublishedTranslations::sourceLocales())
            ->map(fn (Language $language) => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->translatedName() ?? $language->code,
                'direction' => $language->direction?->value ?? $language->direction,
                'is_default_dashboard' => (bool) $language->is_default_dashboard,
                'is_source' => $this->isSource($language),
                'version' => $language->translationFiles->first()?->version,
                'flag' => $language->flag ? [
                    'id' => $language->flag->id,
                    'code' => $language->flag->code,
                ] : null,
            ])
            ->values();

        return ApiResponse::success($languages, __('api.retrieved'));
    }

    /**
     * Published Vue messages for a dashboard-managed locale (ar/en are bundled in the SPA).
     */
    public function vueMessages(string $code): JsonResponse
    {
        $code = strtolower($code);
        $messages = $this->published->lines($code, TranslationPlatform::Vue, 'messages');

        if ($messages === []) {
            throw TranslationException::notPublished();
        }

        $version = (int) $this->published->version($code, TranslationPlatform::Vue, 'messages');

        return ApiResponse::success([
            'locale' => $code,
            'version' => $version,
            'messages' => $messages,
        ], __('api.retrieved'))
            ->setEtag("vue-{$code}-{$version}")
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * Published Android strings of a dashboard-managed locale, all groups merged into one flat map
     * (ar/en are bundled in the APK). Unpublished groups are left out — the app falls back to its
     * bundled English for them. Requires the `strings` group to be published.
     */
    public function androidStrings(string $code): JsonResponse
    {
        $code = strtolower($code);
        $strings = [];
        $versions = [];

        foreach (TranslationPlatform::Android->groups() as $group) {
            $lines = $this->published->lines($code, TranslationPlatform::Android, $group);

            if ($group === 'strings' && $lines === []) {
                throw TranslationException::notPublished();
            }

            if ($lines !== []) {
                $strings = array_merge($strings, $lines);
                $versions[$group] = $this->published->version($code, TranslationPlatform::Android, $group);
            }
        }

        $language = $this->repository->activeLanguageByCode($code);

        if ($language === null) {
            throw TranslationException::notPublished();
        }

        $version = PublishedTranslations::androidVersion($versions);

        $response = ApiResponse::success([
            'code' => $code,
            'direction' => $language->direction?->value ?? $language->direction,
            'version' => $version,
            'strings' => $strings,
        ], __('api.retrieved'))
            ->setEtag("android-{$code}-{$version}")
            ->header('Cache-Control', 'no-cache');

        $response->isNotModified(request());

        return $response;
    }

    protected function androidLanguages(): JsonResponse
    {
        $languages = $this->repository->androidLanguages(PublishedTranslations::sourceLocales())
            ->map(function (Language $language) {
                $isSource = $this->isSource($language);
                $versions = $language->translationFiles
                    ->mapWithKeys(fn (TranslationFile $file) => [$file->group => $file->version])
                    ->all();

                return [
                    'id' => $language->id,
                    'code' => $language->code,
                    'name' => $language->translatedName() ?? $language->code,
                    'direction' => $language->direction?->value ?? $language->direction,
                    'flag' => $language->flag ? [
                        'id' => $language->flag->id,
                        'code' => $language->flag->code,
                    ] : null,
                    'android_version' => $isSource ? null : PublishedTranslations::androidVersion($versions),
                ];
            })
            ->values();

        return ApiResponse::success($languages, __('api.retrieved'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function groupSummary(TranslationPlatform $platform, string $group, ?TranslationFile $file): array
    {
        $base = $this->sources->units($platform, $group);
        $stored = $file === null ? [] : $this->workingCopy($file);
        $stats = $this->validator->stats($platform, $group, $stored);
        $hasDraft = $file?->hasDraft() ?? false;
        $isPublished = $file?->isPublished() ?? false;

        return [
            'group' => $group,
            'status' => match (true) {
                $hasDraft => TranslationFileStatus::Draft->value,
                $isPublished => TranslationFileStatus::Published->value,
                default => 'not_started',
            },
            'has_draft' => $hasDraft,
            'is_published' => $isPublished,
            'version' => $file?->version ?? 0,
            'published_at' => $file?->published_at?->toIso8601String(),
            'updated_at' => $file?->updated_at?->toIso8601String(),
            'base_available' => $base !== [],
            ...$stats,
            'progress' => $stats['total_keys'] > 0
                ? (int) floor($stats['translated_keys'] * 100 / $stats['total_keys'])
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function workingCopy(TranslationFile $file): array
    {
        return $file->hasDraft()
            ? $file->contents(TranslationFile::DRAFT_COLLECTION)
            : $file->contents(TranslationFile::PUBLISHED_COLLECTION);
    }

    /**
     * @param  array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>  $base
     * @param  array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>  $reference
     * @param  array<string, mixed>  $stored
     */
    protected function csv(array $base, array $reference, array $stored, string $code, TranslationPlatform $platform, bool $onlyMissing): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['key', (string) config('translations.base_locale', 'en'), (string) config('translations.reference_locale', 'ar'), 'translation'], ',', '"', '');

        foreach ($base as $key => $unit) {
            $current = $this->storedValue($stored, $unit['segments'], $platform);

            if ($unit['plural']) {
                $forms = is_array($current) ? $current : [];

                if ($onlyMissing && isset($forms['other'])) {
                    continue;
                }

                $baseForms = (array) $unit['value'];
                $referenceForms = (array) ($reference[$key]['value'] ?? []);

                foreach (AndroidStringsXml::pluralCategories($code) as $quantity) {
                    fputcsv($handle, [
                        $key.'.'.$quantity,
                        $baseForms[$quantity] ?? $baseForms['other'] ?? '',
                        $referenceForms[$quantity] ?? $referenceForms['other'] ?? '',
                        $forms[$quantity] ?? '',
                    ], ',', '"', '');
                }

                continue;
            }

            $translation = is_string($current) ? $current : '';

            if ($onlyMissing && $translation !== '') {
                continue;
            }

            $referenceValue = $reference[$key]['value'] ?? '';

            fputcsv($handle, [
                $key,
                (string) $unit['value'],
                is_string($referenceValue) ? $referenceValue : '',
                $translation,
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>  $base
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    protected function jsonTemplate(array $base, array $stored, string $code, TranslationPlatform $platform, bool $onlyMissing): array
    {
        $tree = [];

        foreach ($base as $key => $unit) {
            $current = $this->storedValue($stored, $unit['segments'], $platform);

            if ($unit['plural']) {
                $forms = is_array($current) ? $current : [];

                if ($onlyMissing && isset($forms['other'])) {
                    continue;
                }

                $baseForms = is_array($unit['value']) ? $unit['value'] : [];
                $tree[$key] = [];

                foreach (AndroidStringsXml::pluralCategories($code) as $quantity) {
                    $tree[$key][$quantity] = (string) ($forms[$quantity] ?? $baseForms[$quantity] ?? $baseForms['other'] ?? '');
                }

                continue;
            }

            $translation = is_string($current) ? $current : '';

            if ($onlyMissing && $translation !== '') {
                continue;
            }

            if ($translation === '') {
                $translation = is_string($unit['value']) ? $unit['value'] : '';
            }

            if ($platform === TranslationPlatform::Android) {
                $tree[$key] = $translation;

                continue;
            }

            $node = &$tree;

            foreach ($unit['segments'] as $segment) {
                if (! isset($node[$segment]) || ! is_array($node[$segment])) {
                    $node[$segment] = [];
                }

                $node = &$node[$segment];
            }

            $node = $translation;
            unset($node);
        }

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  list<string>  $segments
     */
    protected function storedValue(array $stored, array $segments, TranslationPlatform $platform): mixed
    {
        if ($platform === TranslationPlatform::Android) {
            return $stored[$segments[0]] ?? null;
        }

        $node = $stored;

        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @return array{0: Language, 1: TranslationPlatform}
     */
    protected function managedTarget(int|string $languageId, string $platform, string $group): array
    {
        $language = $this->repository->language($languageId);
        $platformEnum = TranslationPlatform::tryFrom($platform);

        if ($platformEnum === null || ! $platformEnum->hasGroup($group)) {
            throw TranslationException::unknownGroup();
        }

        if ($this->isSource($language)) {
            throw TranslationException::sourceLocale();
        }

        return [$language, $platformEnum];
    }

    protected function isSource(Language $language): bool
    {
        return in_array(strtolower((string) $language->code), PublishedTranslations::sourceLocales(), true);
    }

    protected function mediaName(Language $language, TranslationPlatform $platform, string $group): string
    {
        return strtolower($language->code)."-{$platform->value}-{$group}";
    }

    /**
     * @param  array<mixed>  $data
     */
    protected function encode(array $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        if ($data === []) {
            return '{}';
        }

        return json_encode($data, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }
}
