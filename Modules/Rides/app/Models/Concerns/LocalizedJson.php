<?php

namespace Modules\Rides\Models\Concerns;

/** A `{ar, en}` JSON column read in the current language (falling back to the other one). */
trait LocalizedJson
{
    public function localized(string $attribute): ?string
    {
        $value = (array) ($this->{$attribute} ?? []);
        $locale = app()->getLocale();

        return $value[$locale] ?? $value['ar'] ?? $value['en'] ?? (reset($value) ?: null);
    }
}
