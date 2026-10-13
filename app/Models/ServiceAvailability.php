<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The switches of one service in one place: a country (`region_id` null) or one of its regions. A null switch is "not set":
 * on a country it counts as on, on a region it follows the country.
 */
class ServiceAvailability extends Model
{
    protected $table = 'service_availability';

    public const CUSTOMER_HIDDEN = 'hidden';

    public const CUSTOMER_COMING_SOON = 'coming_soon';

    public const CUSTOMER_VIEW_ONLY = 'view_only';

    /** What a customer sees while the customer switch is off. */
    public const CUSTOMER_MODES = [self::CUSTOMER_HIDDEN, self::CUSTOMER_COMING_SOON, self::CUSTOMER_VIEW_ONLY];

    /** The switches an admin sets (the same names everywhere: API, rows, resolver). */
    public const SWITCHES = ['service_enabled', 'customer_enabled', 'customer_mode', 'provider_enabled'];

    protected $fillable = ['service_category_id', 'country_id', 'region_id', ...self::SWITCHES];

    protected function casts(): array
    {
        return [
            'service_enabled' => 'boolean',
            'customer_enabled' => 'boolean',
            'provider_enabled' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
