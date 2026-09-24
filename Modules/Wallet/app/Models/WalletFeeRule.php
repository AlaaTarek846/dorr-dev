<?php

namespace Modules\Wallet\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `percent` is signed — positive = fee kept by the platform, negative = bonus
 * given by it. A bonus is always credited spend_only (docs/wallet-plan.md §10/§12),
 * that is enforced in PaymentCompletionService, never left to the rule.
 */
class WalletFeeRule extends Model
{
    use HasTranslations, SearchFilterTrait, SoftDeletes;

    public const OPERATION_TOPUP = 'topup';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'operation',
        'country_id',
        'payment_method_id',
        'owner_type',
        'percent',
        'min_amount_minor',
        'max_amount_minor',
        'starts_at',
        'ends_at',
        'max_uses_per_owner',
        'budget_total_minor',
        'budget_used_minor',
        'priority',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:4',
            'min_amount_minor' => 'integer',
            'max_amount_minor' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses_per_owner' => 'integer',
            'budget_total_minor' => 'integer',
            'budget_used_minor' => 'integer',
            'priority' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(WalletFeeRuleTranslation::class);
    }

    protected function translationModel(): string
    {
        return WalletFeeRuleTranslation::class;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isBonus(): bool
    {
        return (float) $this->percent < 0;
    }

    public function isFee(): bool
    {
        return (float) $this->percent > 0;
    }

    public function budgetRemainingMinor(): ?int
    {
        return $this->budget_total_minor === null ? null : max(0, $this->budget_total_minor - $this->budget_used_minor);
    }
}
