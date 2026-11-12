<?php

namespace Modules\Moving\Models;

use App\Models\Country;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Moving\Enums\LoadType;
use Modules\Moving\Enums\MovingStatus;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Models\VehicleType;
use Modules\User\Models\User;
use Spatie\MediaLibrary\HasMedia;

/**
 * A customer's move (docs/moving_business.md §4): what, from where to where, when, and the extras. Published to the
 * branches whose area holds the pickup point; after an offer is accepted it carries the agreed price, the
 * establishment, the vehicle and the driver. Photos and videos are in the media collections `photos` and `videos`.
 */
class MovingRequest extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $table = 'moving_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MovingStatus::class,
            'load_type' => LoadType::class,
            'packing' => 'boolean',
            'disassembly' => 'boolean',
            'crane' => 'boolean',
            'technician' => 'boolean',
            'pickup_elevator' => 'boolean',
            'dropoff_elevator' => 'boolean',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'dropoff_lat' => 'float',
            'dropoff_lng' => 'float',
            'scheduled_at' => 'datetime',
            'offers_deadline_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'driver_accepted_at' => 'datetime',
            'driver_location_at' => 'datetime',
            'driver_lat' => 'float',
            'driver_lng' => 'float',
            'delivered_at' => 'datetime',
            'objection_until' => 'datetime',
            'settled_at' => 'datetime',
            'refunded_minor' => 'integer',
            'policy' => 'array',
            'vehicle_type_ids' => 'array',
            'price_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'commission_minor' => 'integer',
            'provider_net_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** A value of the policy kept with the request (REQ-003); null = not set when it was published. */
    public function policy(string $key): mixed
    {
        return Arr::get($this->policy ?? [], $key);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    /** @return list<int> every vehicle type the customer accepts, the first choice first (older requests: their one type) */
    public function vehicleTypeIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', $this->vehicle_type_ids ?? [])));

        return $ids !== [] ? $ids : ($this->vehicle_type_id !== null ? [(int) $this->vehicle_type_id] : []);
    }

    /** @return Collection<int, VehicleType> the accepted vehicle types, in the customer's order */
    public function vehicleTypes(): Collection
    {
        $ids = $this->vehicleTypeIds();

        return VehicleType::query()->with('translations')->whereIn('id', $ids)->get()
            ->sortBy(fn (VehicleType $t) => array_search($t->id, $ids, true))->values();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(MovingOffer::class, 'request_id');
    }

    public function acceptedOffer(): BelongsTo
    {
        return $this->belongsTo(MovingOffer::class, 'accepted_offer_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'driver_membership_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(MovingInventoryItem::class, 'request_id')->orderBy('number');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(MovingCase::class, 'request_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(MovingTicket::class, 'request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MovingRequestEvent::class, 'request_id');
    }
}
