<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use SoftDeletes;

    protected $table = 'user_addresses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'building_number',
        'floor',
        'address_details',
        'landmark',
        'latitude',
        'longitude',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user's default address: $user->addresses()->default()->first().
     *
     * @param  Builder<Address>  $query
     * @return Builder<Address>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
