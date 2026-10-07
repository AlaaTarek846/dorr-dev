<?php

namespace App\Http\Resources\Concerns;

trait FormatsTranslations
{
    /**
     * Name column, kept as the shorthand every existing resource relies on.
     *
     * @return array<string, mixed>
     */
    protected function translationFields(): array
    {
        return [
            'name' => $this->resource->translatedName(),
            'translations' => $this->whenLoaded(
                'translations',
                fn () => $this->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'name' => $item->name,
                ])->values(),
            ),
        ];
    }

    /**
     * Arbitrary translatable columns, e.g. FAQ (question, answer) or
     * Privacy Policy (content).
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    protected function translationFieldsFor(array $fields): array
    {
        $resource = $this->resource;

        $values = array_fill_keys($fields, null);

        foreach ($fields as $field) {
            $values[$field] = $resource->translated($field);
        }

        $values['translations'] = $this->whenLoaded(
            'translations',
            fn () => $this->translations->map(fn ($item) => [
                'locale' => $item->locale,
                ...$item->only($fields),
            ])->values(),
        );

        return $values;
    }
}
