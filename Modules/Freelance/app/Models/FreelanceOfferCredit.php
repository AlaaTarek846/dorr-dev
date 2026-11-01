<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Extra offers a freelancer bought (FL-L01, phase 2): one batch per purchase. How many are left is never stored — it is the quantity minus
 * the offers sent on this batch that still count (an offer taken back before the client saw it gives its place back, like the monthly
 * free offers), so a withdrawn offer, a retry or two requests together can't make the number drift.
 */
class FreelanceOfferCredit extends Model
{
    protected $fillable = [
        'uuid', 'freelancer_id', 'pack_id', 'country_id', 'request_id', 'quantity', 'price_minor', 'currency_code', 'wallet_transaction_id', 'expires_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceOfferCredit $c) => $c->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_minor' => 'integer', 'expires_at' => 'datetime'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(FreelanceOfferPack::class, 'pack_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** The offers sent on this batch. */
    public function offers(): HasMany
    {
        return $this->hasMany(FreelanceOffer::class, 'credit_id');
    }

    /** Batches that have not ended. */
    public function scopeUsable($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
