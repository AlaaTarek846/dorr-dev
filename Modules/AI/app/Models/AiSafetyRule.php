<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSafetyRule extends Model
{
    use HasFactory;

    const ACTION_ALLOW = 'allow';

    const ACTION_BLOCK = 'block';

    const ACTION_REVIEW = 'review';

    const ACTION_REQUIRE_CONFIRMATION = 'require_confirmation';

    const ACTION_SANITIZE = 'sanitize';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'safety_policy_id',
        'name',
        'condition',
        'action',
        'priority',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiSafetyPolicy::class, 'safety_policy_id');
    }
}
