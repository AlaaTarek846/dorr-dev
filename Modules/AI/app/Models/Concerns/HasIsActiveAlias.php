<?php

namespace Modules\AI\Models\Concerns;

/**
 * Back-compat for a catalog model with no translated text whose `is_active` column became the catalog's `status`
 * (AiRoutingRule, AiLearnedIntent): `$model->is_active` reads / writes `status`, so older code, seeders and tests
 * keep working. Query builders must use `status` (the column), never `is_active`.
 *
 * Translated models get the same alias from HasFlatTranslatedText.
 */
trait HasIsActiveAlias
{
    /** Switched on and not in the trash = may be used. */
    public function isAvailable(): bool
    {
        return (bool) $this->status && ! $this->trashed();
    }

    public function getIsActiveAttribute(): bool
    {
        return (bool) ($this->attributes['status'] ?? false);
    }

    public function setIsActiveAttribute(mixed $value): void
    {
        $this->attributes['status'] = (bool) $value;
    }
}
