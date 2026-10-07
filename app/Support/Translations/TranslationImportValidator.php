<?php

namespace App\Support\Translations;

use App\Enums\TranslationPlatform;
use Illuminate\Http\UploadedFile;
use JsonException;

/**
 * Validates an uploaded JSON/CSV translation against the `en` base. The file is only read as text:
 * JSON goes through json_decode, CSV through fgetcsv — nothing is ever included or executed.
 *
 * Missing keys are reported (not rejected); extra keys, placeholder changes, code/HTML and
 * non-text values reject the file.
 */
final class TranslationImportValidator
{
    private const REPORT_LIST_LIMIT = 200;

    public function __construct(private readonly TranslationSources $sources) {}

    public function validate(TranslationPlatform $platform, string $group, UploadedFile $file): TranslationImportResult
    {
        $base = $this->sources->units($platform, $group);

        if ($base === []) {
            return TranslationImportResult::rejected([self::error('base_unavailable')], $this->emptyReport(0));
        }

        $content = (string) file_get_contents((string) $file->getRealPath());
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $contentError = $this->contentError($content);

        if ($contentError !== null) {
            return TranslationImportResult::rejected([$contentError], $this->emptyReport(count($base)));
        }

        $content = $this->stripBom($content);
        $leaves = $extension === 'csv' ? $this->csvLeaves($content) : $this->jsonLeaves($content);

        if (isset($leaves['error'])) {
            return TranslationImportResult::rejected([$leaves['error']], $this->emptyReport(count($base)));
        }

        return $this->check($platform, $base, $leaves['leaves']);
    }

    /**
     * Counts for already stored content (dashboard overview). Keys no longer in the base are ignored.
     *
     * @param  array<mixed>  $stored
     * @return array{total_keys: int, translated_keys: int, missing_keys: int}
     */
    public function stats(TranslationPlatform $platform, string $group, array $stored): array
    {
        $base = $this->sources->units($platform, $group);
        $matched = $this->match($base, $this->leavesFromTree($stored)['leaves'] ?? []);
        $translated = $this->translatedCount($base, $matched['lines']);

        return [
            'total_keys' => count($base),
            'translated_keys' => $translated,
            'missing_keys' => count($base) - $translated,
        ];
    }

    /**
     * @param  array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>  $base
     * @param  list<array{0: string, 1: mixed}>  $leaves
     */
    private function check(TranslationPlatform $platform, array $base, array $leaves): TranslationImportResult
    {
        $errors = [];

        $invalidValues = array_values(array_map(
            static fn (array $leaf): string => $leaf[0],
            array_filter($leaves, static fn (array $leaf): bool => ! is_string($leaf[1])),
        ));

        if ($invalidValues !== []) {
            $errors[] = self::error('invalid_value', array_slice($invalidValues, 0, self::REPORT_LIST_LIMIT));
        }

        $matched = $this->match($base, array_values(array_filter($leaves, static fn (array $leaf): bool => is_string($leaf[1]))));
        $lines = $matched['lines'];

        if ($matched['extra'] !== []) {
            $errors[] = self::error('extra_keys', array_slice($matched['extra'], 0, self::REPORT_LIST_LIMIT));
        }

        $unsafe = [];
        $placeholderErrors = [];
        $pluralWithoutOther = [];

        foreach ($lines as $key => $value) {
            $unit = $base[$key];

            foreach ((array) $value as $text) {
                if ($this->isUnsafe($text)) {
                    $unsafe[] = $key;

                    break;
                }
            }

            if ($unit['plural']) {
                if (! isset($value['other'])) {
                    $pluralWithoutOther[] = $key;
                }

                $allowed = array_values(array_unique(array_merge(...array_map(
                    static fn (string $form): array => TranslationPlaceholders::extract($platform, $form),
                    array_values((array) $unit['value']),
                ))));

                foreach ($value as $quantity => $text) {
                    $found = TranslationPlaceholders::extract($platform, $text);

                    if (array_diff($found, $allowed) !== []) {
                        $placeholderErrors[] = ['key' => $key.'.'.$quantity, 'expected' => $allowed, 'found' => $found];
                    }
                }

                continue;
            }

            $expected = TranslationPlaceholders::extract($platform, (string) $unit['value']);
            $found = TranslationPlaceholders::extract($platform, (string) $value);

            if ($expected !== $found) {
                $placeholderErrors[] = ['key' => $key, 'expected' => $expected, 'found' => $found];
            }
        }

        if ($unsafe !== []) {
            $errors[] = self::error('unsafe_value', array_slice(array_values(array_unique($unsafe)), 0, self::REPORT_LIST_LIMIT));
        }

        if ($pluralWithoutOther !== []) {
            $errors[] = self::error('plural_other', $pluralWithoutOther);
        }

        if ($placeholderErrors !== []) {
            $errors[] = self::error('placeholders', array_column(array_slice($placeholderErrors, 0, self::REPORT_LIST_LIMIT), 'key'));
        }

        $translated = $this->translatedCount($base, $lines);
        $missing = array_keys(array_diff_key($base, array_filter(
            $lines,
            static fn (mixed $value, string $key): bool => ! $base[$key]['plural'] || isset($value['other']),
            ARRAY_FILTER_USE_BOTH,
        )));

        $report = [
            'total_keys' => count($base),
            'translated_keys' => $translated,
            'missing_keys' => count($base) - $translated,
            'missing' => array_slice($missing, 0, self::REPORT_LIST_LIMIT),
            'extra' => array_slice($matched['extra'], 0, self::REPORT_LIST_LIMIT),
            'placeholder_errors' => array_slice($placeholderErrors, 0, self::REPORT_LIST_LIMIT),
            'errors' => $errors,
        ];

        if ($errors !== []) {
            return TranslationImportResult::rejected($errors, $report);
        }

        return TranslationImportResult::valid($this->nest($platform, $base, $lines), $report);
    }

    /**
     * @param  array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>  $base
     * @param  list<array{0: string, 1: string}>  $leaves
     * @return array{lines: array<string, string|array<string, string>>, extra: list<string>}
     */
    private function match(array $base, array $leaves): array
    {
        $lines = [];
        $extra = [];

        foreach ($leaves as [$key, $value]) {
            if (! is_string($value)) {
                continue;
            }

            if (isset($base[$key]) && ! $base[$key]['plural']) {
                if (trim($value) !== '') {
                    $lines[$key] = $value;
                }

                continue;
            }

            if (preg_match('/^(.+)\.('.implode('|', AndroidStringsXml::QUANTITIES).')$/', $key, $match)
                && isset($base[$match[1]])
                && $base[$match[1]]['plural']) {
                if (trim($value) !== '') {
                    $lines[$match[1]][$match[2]] = $value;
                }

                continue;
            }

            $extra[] = $key;
        }

        return ['lines' => $lines, 'extra' => array_values(array_unique($extra))];
    }

    /**
     * @param  array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>  $base
     * @param  array<string, string|array<string, string>>  $lines
     */
    private function translatedCount(array $base, array $lines): int
    {
        $count = 0;

        foreach ($lines as $key => $value) {
            if (! $base[$key]['plural'] || isset($value['other'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Stored shape: backend/vue keep the base nesting; android is name => string | [quantity => string].
     *
     * @param  array<string, array{segments: list<string>, value: string|array<string, string>, plural: bool}>  $base
     * @param  array<string, string|array<string, string>>  $lines
     * @return array<string, mixed>
     */
    private function nest(TranslationPlatform $platform, array $base, array $lines): array
    {
        $tree = [];

        foreach ($base as $key => $unit) {
            if (! isset($lines[$key])) {
                continue;
            }

            if ($platform === TranslationPlatform::Android) {
                $tree[$key] = $lines[$key];

                continue;
            }

            $node = &$tree;

            foreach ($unit['segments'] as $segment) {
                if (! isset($node[$segment]) || ! is_array($node[$segment])) {
                    $node[$segment] = [];
                }

                $node = &$node[$segment];
            }

            $node = $lines[$key];
            unset($node);
        }

        return $tree;
    }

    private function contentError(string $content): ?array
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            return self::error('invalid_encoding');
        }

        if (preg_match('/<\?|<%/', $content)) {
            return self::error('php_code');
        }

        return null;
    }

    private function isUnsafe(string $text): bool
    {
        return (bool) preg_match('/<\s*\/?\s*(script|iframe|object|embed|style|link|meta)\b|javascript\s*:|<[^>]*\bon[a-z]+\s*=/i', $text);
    }

    /**
     * @return array{leaves: list<array{0: string, 1: mixed}>}|array{error: array<string, mixed>}
     */
    private function jsonLeaves(string $content): array
    {
        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['error' => self::error('invalid_json')];
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return ['error' => self::error('invalid_json')];
        }

        return $this->leavesFromTree($decoded);
    }

    /**
     * @param  array<mixed>  $tree
     * @param  list<string>  $prefix
     * @return array{leaves: list<array{0: string, 1: mixed}>}
     */
    private function leavesFromTree(array $tree, array $prefix = []): array
    {
        $leaves = [];

        foreach ($tree as $key => $value) {
            $segments = [...$prefix, (string) $key];

            if (is_array($value)) {
                array_push($leaves, ...$this->leavesFromTree($value, $segments)['leaves']);

                continue;
            }

            $leaves[] = [implode('.', $segments), $value];
        }

        return ['leaves' => $leaves];
    }

    /**
     * CSV with a header row containing `key` and `translation` (other columns are ignored).
     *
     * @return array{leaves: list<array{0: string, 1: mixed}>}|array{error: array<string, mixed>}
     */
    private function csvLeaves(string $content): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, null, ',', '"', '');
        $columns = is_array($header) ? array_map(static fn ($column) => strtolower(trim((string) $column)), $header) : [];
        $keyIndex = array_search('key', $columns, true);
        $translationIndex = array_search('translation', $columns, true);

        if ($keyIndex === false || $translationIndex === false) {
            fclose($handle);

            return ['error' => self::error('invalid_csv')];
        }

        $leaves = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $key = trim((string) ($row[$keyIndex] ?? ''));

            if ($key === '') {
                continue;
            }

            $leaves[] = [$key, (string) ($row[$translationIndex] ?? '')];
        }

        fclose($handle);

        return ['leaves' => $leaves];
    }

    private function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * @return array{total_keys: int, translated_keys: int, missing_keys: int, missing: list<string>, extra: list<string>, placeholder_errors: list<mixed>, errors: list<mixed>}
     */
    private function emptyReport(int $total): array
    {
        return [
            'total_keys' => $total,
            'translated_keys' => 0,
            'missing_keys' => $total,
            'missing' => [],
            'extra' => [],
            'placeholder_errors' => [],
            'errors' => [],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array{code: string, message: string, keys: list<string>}
     */
    private static function error(string $code, array $keys = []): array
    {
        return [
            'code' => $code,
            'message' => __('api.translations.errors.'.$code),
            'keys' => $keys,
        ];
    }
}
