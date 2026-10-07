<?php

namespace Modules\Chat\Models;

use App\Models\Concerns\HasTranslations;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something for sale for N weeks / months / years: a merchant portal's place on the portals page
 * (`portal`) or a channel's verification tick (`channel_verification`). Priced per country.
 */
class ChatPackage extends Model
{
    use HasTranslations;

    public const KIND_PORTAL = 'portal';

    public const KIND_CHANNEL_VERIFICATION = 'channel_verification';

    public const KINDS = [self::KIND_PORTAL, self::KIND_CHANNEL_VERIFICATION];

    public const PERIODS = ['week', 'month', 'year'];

    protected $fillable = ['kind', 'period', 'period_count', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer', 'period_count' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatPackageTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatPackageTranslation::class;
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ChatPackagePrice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function priceIn(int $countryId): ?int
    {
        $row = $this->relationLoaded('prices')
            ? $this->prices->firstWhere('country_id', $countryId)
            : $this->prices()->where('country_id', $countryId)->first();

        return $row?->amount_minor;
    }

    /** When a period bought now (or stacked on one still running) ends. */
    public function endFrom(CarbonInterface $start): CarbonInterface
    {
        $start = $start->copy();

        return match ($this->period) {
            'week' => $start->addWeeks($this->period_count),
            'year' => $start->addYears($this->period_count),
            default => $start->addMonths($this->period_count),
        };
    }
}
