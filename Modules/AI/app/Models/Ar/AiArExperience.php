<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One AR experience (a scene) requested by any service. The engine does not know which service: that is `source_service`. */
class AiArExperience extends Model
{
    use SoftDeletes;

    public const TYPES = ['decor', 'education', 'commerce', 'real_estate', 'maintenance', 'portal', 'free'];

    protected $table = 'ai_ar_experiences';

    protected $fillable = ['owner_type', 'owner_id', 'country_id', 'type', 'title', 'status', 'source_service', 'current_version_id'];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'country_id' => 'integer', 'current_version_id' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AiArExperienceVersion::class, 'experience_id')->orderBy('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AiArExperienceVersion::class, 'current_version_id');
    }

    public function captures(): HasMany
    {
        return $this->hasMany(AiArCapture::class, 'experience_id');
    }
}
