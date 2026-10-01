<?php

namespace App\Support\Translations;

final class TranslationImportResult
{
    /**
     * @param  array<string, mixed>  $contents  normalized JSON tree to store (only when valid)
     * @param  array<string, mixed>  $report
     */
    private function __construct(
        public readonly bool $valid,
        public readonly array $contents,
        public readonly array $report,
    ) {}

    /**
     * @param  array<string, mixed>  $contents
     * @param  array<string, mixed>  $report
     */
    public static function valid(array $contents, array $report): self
    {
        return new self(true, $contents, $report);
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $report
     */
    public static function rejected(array $errors, array $report): self
    {
        return new self(false, [], [...$report, 'errors' => $errors]);
    }
}
