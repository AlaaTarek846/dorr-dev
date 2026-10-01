<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Translation management rule the admin can act on. Renders itself — see ApiRenderable.
 */
class TranslationException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        private readonly int $status = 422,
        private readonly array $replace = [],
        private readonly array $data = [],
    ) {
        parent::__construct($errorCode);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __('api.translations.'.$this->errorCode, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return 'translations_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }

    public static function sourceLocale(): self
    {
        return new self('source_locale');
    }

    public static function unknownGroup(): self
    {
        return new self('invalid_target', 404);
    }

    public static function notPublished(): self
    {
        return new self('not_published', 404);
    }

    public static function noDraft(): self
    {
        return new self('no_draft');
    }

    public static function nothingToExport(): self
    {
        return new self('nothing_to_export');
    }

    public static function baseUnavailable(): self
    {
        return new self('base_unavailable');
    }

    public static function zipUnavailable(): self
    {
        return new self('zip_unavailable', 500);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function importInvalid(array $report): self
    {
        return new self('import_invalid', 422, [], ['report' => $report]);
    }
}
