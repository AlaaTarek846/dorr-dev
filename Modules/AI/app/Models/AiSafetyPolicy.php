<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A safety policy: a group of safety rules (AiSafetyRule) the chat checks every message against before it reaches
 * a provider (AiSafetyGuard). A catalog entity like Country: the name / description the admin reads live in
 * ai_safety_policy_translations; the risk level and the contexts it applies to are technical and not translated;
 * `status` is the on/off switch and deleting moves it to the trash.
 *
 * Switched off or in the trash = none of its rules is applied.
 *
 * Back-compat (HasFlatTranslatedText): `$policy->name / description` read the current language, `$policy->is_active`
 * reads `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiSafetyPolicy extends Model
{
    use HasFactory, HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    const RISK_LOW = 'low';

    const RISK_MEDIUM = 'medium';

    const RISK_HIGH = 'high';

    const RISK_CRITICAL = 'critical';

    /** Where a policy applies (applies_to) - the contexts the screen offers. */
    public const CONTEXTS = ['chat', 'agent', 'tool', 'code'];

    /** The name is shown wherever a policy is (its rules, the safety events log), so its translations come along. */
    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'risk_level',
        'applies_to',
        'description',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applies_to' => 'array',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_safety_policy_translations (HasTranslations' saved hook runs
        // first): drop the cached relations so the next read sees it.
        static::saved(function (self $policy): void {
            $policy->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiSafetyPolicyTranslation::class, 'ai_safety_policy_id');
    }

    protected function translationModel(): string
    {
        return AiSafetyPolicyTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name', 'description'];
    }

    /** The policy's rules that are not in the trash (the list's rule count). */
    public function rules(): HasMany
    {
        return $this->hasMany(AiSafetyRule::class, 'safety_policy_id');
    }

    /** Every rule of this policy, in the trash too (deleting the policy for good deletes them all - cascade). */
    public function safetyRules(): HasMany
    {
        return $this->hasMany(AiSafetyRule::class, 'safety_policy_id')->withTrashed();
    }

    /** The safety events this policy caused (ai_safety_events) - history that names it. */
    public function safetyEvents(): HasMany
    {
        return $this->hasMany(AiSafetyEvent::class, 'safety_policy_id');
    }
}
