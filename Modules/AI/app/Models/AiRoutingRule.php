<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRoutingRule extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'routing_policy_id', 'intent_id', 'provider_id', 'model_key',
        'priority', 'selection_config', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selection_config' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiRoutingPolicy::class, 'routing_policy_id');
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(AiIntent::class, 'intent_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
