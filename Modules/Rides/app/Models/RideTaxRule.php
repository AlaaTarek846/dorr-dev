<?php

namespace Modules\Rides\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Rides\Enums\TaxBase;
use Modules\Rides\Enums\TaxBearer;

/** A country's tax on rides — in Saudi Arabia, VAT on the app's commission, carried by the driver (Jawad). */
class RideTaxRule extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['country_id', 'code', 'base', 'rate_bp', 'borne_by', 'effective_from', 'effective_to', 'status'];

    protected function casts(): array
    {
        return [
            'base' => TaxBase::class,
            'borne_by' => TaxBearer::class,
            'rate_bp' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'status' => 'boolean',
        ];
    }

    /** @param Builder<RideTaxRule> $query */
    public function scopeInForceAt(Builder $query, int $countryId, ?Carbon $at = null): void
    {
        $at ??= now();
        $query->where('country_id', $countryId)
            ->where('status', true)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RideTaxRuleTranslation::class);
    }

    protected function translationModel(): string
    {
        return RideTaxRuleTranslation::class;
    }
}
