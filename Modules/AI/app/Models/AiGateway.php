<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGateway extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'environment', 'default_policy_id', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function defaultPolicy(): BelongsTo
    {
        return $this->belongsTo(AiRoutingPolicy::class, 'default_policy_id');
    }
}
