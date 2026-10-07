<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSafetyPolicy extends Model
{
    use HasFactory;

    const RISK_LOW = 'low';

    const RISK_MEDIUM = 'medium';

    const RISK_HIGH = 'high';

    const RISK_CRITICAL = 'critical';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'risk_level',
        'applies_to',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applies_to' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AiSafetyRule::class, 'safety_policy_id');
    }
}
