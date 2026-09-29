<?php

namespace Modules\Wallet\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Wallet\Enums\PaymentMethodType;
use Spatie\MediaLibrary\HasMedia;

/**
 * `credentials` is `encrypted:array` and MUST NEVER appear in
 * PaymentMethodResource or any log — Modules\Wallet\Http\Resources\PaymentMethodResource
 * deliberately doesn't read it at all, not even to omit it selectively.
 */
class PaymentMethod extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SearchFilterTrait, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'gateway',
        'type',
        'is_global',
        'supports_topup',
        'status',
        'sort_order',
        'credentials',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'is_global' => 'boolean',
            'supports_topup' => 'boolean',
            'status' => 'boolean',
            'sort_order' => 'integer',
            'credentials' => 'encrypted:array',
        ];
    }

    /**
     * The credential keys a gateway cannot work without (URPay's `mode` and
     * `test_consumer_mobile_number` are optional). Sandbox and manual methods need none.
     */
    private const REQUIRED_CREDENTIALS = [
        'myfatoorah' => ['api_url', 'api_key'],
        'arb' => ['tranportal_id', 'tranportal_password', 'tranportal_resource_key', 'hosted_url'],
        'urpay' => ['payment_url', 'username', 'password', 'client_id', 'terminal_id', 'merchant_wallet_number', 'merchant_id'],
    ];

    /**
     * Can a customer actually pay with this today? A listed online gateway whose credentials the admin
     * hasn't entered yet is "coming soon": shown in the app, but not chargeable.
     */
    public function isConfigured(): bool
    {
        $required = self::REQUIRED_CREDENTIALS[$this->gateway] ?? null;

        if ($required === null) {
            return true;
        }

        $credentials = $this->credentials;

        if (! is_array($credentials)) {
            return false;
        }

        foreach ($required as $key) {
            if (! isset($credentials[$key]) || $credentials[$key] === '') {
                return false;
            }
        }

        return true;
    }

    public function translations(): HasMany
    {
        return $this->hasMany(PaymentMethodTranslation::class);
    }

    protected function translationModel(): string
    {
        return PaymentMethodTranslation::class;
    }

    public function countryLinks(): HasMany
    {
        return $this->hasMany(PaymentMethodCountry::class);
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'payment_method_country')
            ->withPivot(['min_amount_minor', 'max_amount_minor', 'status'])
            ->withTimestamps();
    }

    /**
     * status=active AND (is_global OR an active payment_method_country row
     * for this country) — docs/wallet-structure.md §8's availability rule,
     * computed at request time, never stored.
     */
    public function scopeAvailableForCountry(Builder $query, int|Country $country): Builder
    {
        $countryId = $country instanceof Country ? $country->id : $country;

        return $query->where('status', true)
            ->where(function (Builder $q) use ($countryId) {
                $q->where('is_global', true)
                    ->orWhereHas('countryLinks', fn (Builder $link) => $link->where('country_id', $countryId)->where('status', true));
            });
    }
}
