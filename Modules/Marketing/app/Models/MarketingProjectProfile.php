<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProjectProfile extends Model
{
    protected $fillable = ['project_id', 'offer', 'customer_segments', 'channels', 'team_size', 'monthly_budget_minor', 'assumptions', 'discovery'];

    protected function casts(): array
    {
        return [
            'customer_segments' => 'array',
            'channels' => 'array',
            'assumptions' => 'array',
            'discovery' => 'array',
            'team_size' => 'integer',
            'monthly_budget_minor' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }
}
