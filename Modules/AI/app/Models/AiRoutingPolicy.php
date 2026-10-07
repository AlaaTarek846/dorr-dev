<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRoutingPolicy extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'scope_type', 'country_code', 'service_key', 'plan_id',
        'selection_strategy', 'fallback_enabled', 'priority', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fallback_enabled' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiPlan::class, 'plan_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AiRoutingRule::class, 'routing_policy_id');
    }
}
