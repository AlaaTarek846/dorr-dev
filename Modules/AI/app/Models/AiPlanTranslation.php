<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One language of an AI plan's customer-facing text (ai_plan_translations). */
class AiPlanTranslation extends Model
{
    protected $fillable = ['ai_plan_id', 'locale', 'name', 'description', 'badge', 'features'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AiPlan::class, 'ai_plan_id');
    }
}
