<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pack of extra offers for sale (FL-L01, phase 2): how many offers it adds, and in `prices` what it costs in each country. What a
 * freelancer bought is a [FreelanceOfferCredit] that keeps its own quantity and price, so editing a pack never changes a purchase.
 */
class FreelanceOfferPack extends Model
{
    protected $fillable = ['slug', 'name', 'offers', 'sort_order', 'status'];

    protected function casts(): array
    {
        return ['name' => 'array', 'offers' => 'integer', 'sort_order' => 'integer', 'status' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FreelanceOfferPackPrice::class, 'pack_id');
    }

    /** The pack's name in a language (the app's, else Arabic, else English). */
    public function text(?string $locale = null): ?string
    {
        $values = (array) $this->name;
        $locale ??= app()->getLocale();

        return $values[$locale] ?? $values['ar'] ?? $values['en'] ?? null;
    }
}
