<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPlan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'usage_minutes',
        'cooldown_minutes',
        'price',
        'currency',
        'is_trial',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usage_minutes' => 'integer',
            'cooldown_minutes' => 'integer',
            'price' => 'decimal:2',
            'is_trial' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AiSubscription::class, 'plan_id');
    }
}
