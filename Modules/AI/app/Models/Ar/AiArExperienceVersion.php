<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiArExperienceVersion extends Model
{
    protected $table = 'ai_ar_experience_versions';

    protected $fillable = ['experience_id', 'version_no', 'note'];

    public function items(): HasMany
    {
        return $this->hasMany(AiArSceneItem::class, 'experience_version_id')->orderBy('id');
    }
}
