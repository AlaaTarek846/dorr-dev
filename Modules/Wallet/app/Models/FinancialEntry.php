<?php

namespace Modules\Wallet\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Wallet\Enums\FinancialEntryType;

/**
 * The system's own income/expense ledger — never written to directly, only
 * through Modules\Wallet\Services\FinancialLedgerService::record().
 *
 * `reference_type`/`reference_id` is a genuine Eloquent morphTo() (full class
 * name, no alias) — unlike Jawad's free-text reference_type, this always
 * resolves to a real model. It's unrelated to the User/Provider/Admin owner
 * aliasing story (Modules\Wallet\Support\OwnerType) since financial_entries
 * references domain records (bookings, fee rules...), not wallet owners.
 */
class FinancialEntry extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'type',
        'amount_minor',
        'currency_id',
        'country_id',
        'entry_date',
        'description',
        'notes',
        'reference_type',
        'reference_id',
        'wallet_transaction_id',
        'created_by_type',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => FinancialEntryType::class,
            'amount_minor' => 'integer',
            'entry_date' => 'date',
            'notes' => 'json',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
