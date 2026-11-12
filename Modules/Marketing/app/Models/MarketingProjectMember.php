<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Marketing\Enums\ProjectRole;

class MarketingProjectMember extends Model
{
    protected $fillable = ['project_id', 'member_type', 'member_id', 'role'];

    protected function casts(): array
    {
        return ['role' => ProjectRole::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function member(): MorphTo
    {
        return $this->morphTo();
    }
}
