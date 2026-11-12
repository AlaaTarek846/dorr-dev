<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * One safety rule of a policy: "when the message matches this condition (keywords / risk score), do this action"
 * (allow, block, review, require confirmation, sanitize), the highest priority first (AiSafetyGuard). A catalog
 * entity like Country: the name the admin reads lives in ai_safety_rule_translations; the condition, the action and
 * the priority are technical and not translated; `status` is the on/off switch and deleting moves it to the trash.
 *
 * Switched off or in the trash = never applied - and the same when its policy is switched off or in the trash.
 *
 * Back-compat (HasFlatTranslatedText): `$rule->name` reads the current language, `$rule->is_active` reads `status`,
 * and a flat name (`create(['name' => ...])`) is written into every stored language.
 */
class AiSafetyRule extends Model
{
    use HasFactory, HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    const ACTION_ALLOW = 'allow';

    const ACTION_BLOCK = 'block';

    const ACTION_REVIEW = 'review';

    const ACTION_REQUIRE_CONFIRMATION = 'require_confirmation';

    const ACTION_SANITIZE = 'sanitize';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_ALLOW, self::ACTION_BLOCK, self::ACTION_REVIEW, self::ACTION_REQUIRE_CONFIRMATION, self::ACTION_SANITIZE,
    ];

    /** The name is shown wherever a rule is (the safety events log), so its translations always come along. */
    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'safety_policy_id',
        'name',
        'condition',
        'action',
        'priority',
        'status',
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
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_safety_rule_translations (HasTranslations' saved hook runs
        // first): drop the cached relations so the next read sees it.
        static::saved(function (self $rule): void {
            $rule->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiSafetyRuleTranslation::class, 'ai_safety_rule_id');
    }

    protected function translationModel(): string
    {
        return AiSafetyRuleTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name'];
    }

    /**
     * withTrashed: the screen and the events log still name a policy that was moved to the trash. The guard only
     * applies rules whose policy is switched on and not in the trash (it filters on that itself).
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiSafetyPolicy::class, 'safety_policy_id')->withTrashed();
    }

    /** The safety events this rule caused (ai_safety_events) - history that names it. */
    public function safetyEvents(): HasMany
    {
        return $this->hasMany(AiSafetyEvent::class, 'safety_rule_id');
    }
}
