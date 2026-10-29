<?php

namespace Modules\Freelance\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A skill (FL-102). `active` shows in the catalog; `inactive` is switched off by the admin; `pending` was suggested by a
 * freelancer and waits for the admin; `rejected` was turned down; `merged` is an alias of `mergedInto` and shows nowhere.
 */
class FreelanceSkill extends Model
{
    use HasTranslations;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public const MERGED = 'merged';

    public const STATUSES = [self::ACTIVE, self::INACTIVE, self::PENDING, self::REJECTED, self::MERGED];

    protected $fillable = ['slug', 'status', 'suggested_by_type', 'suggested_by_id', 'merged_into_id', 'reject_reason', 'usage_count'];

    protected function casts(): array
    {
        return ['usage_count' => 'integer', 'suggested_by_id' => 'integer', 'merged_into_id' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FreelanceSkillTranslation::class);
    }

    protected function translationModel(): string
    {
        return FreelanceSkillTranslation::class;
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(FreelanceSpecialty::class, 'freelance_skill_specialty', 'freelance_skill_id', 'freelance_specialty_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
