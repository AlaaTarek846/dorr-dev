<?php

namespace App\Http\Requests\Concerns;

trait HasCatalogRules
{
    /**
     * @return array<string, mixed>
     */
    protected function translationRules(): array
    {
        return [
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusChangeRules(): array
    {
        return [
            'status' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteMultipleRules(string $table): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct', "exists:{$table},id"],
        ];
    }
}
