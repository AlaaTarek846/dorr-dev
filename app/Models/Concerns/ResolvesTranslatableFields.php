<?php

namespace App\Models\Concerns;

trait ResolvesTranslatableFields
{
    /**
     * Translatable columns, derived from the translation model $fillable minus
     * the locale and the parent foreign key.
     *
     * @return list<string>
     */
    public function translatableFields(): array
    {
        $translation = $this->translationModelInstance();

        if ($translation === null) {
            return [];
        }

        $reserved = ['locale', $this->getForeignKey()];

        return array_values(array_filter(
            $translation->getFillable(),
            fn (string $field) => ! in_array($field, $reserved, true),
        ));
    }

    protected function translationModelInstance(): ?object
    {
        $class = $this->translationModel();

        return class_exists($class) ? new $class : null;
    }
}