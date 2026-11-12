<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A domain policy: how DORR AI answers a message of one domain (legal, health, education, code, marketing, general
 * info) - risk level, jurisdiction / triage gates, sandboxed code, an allowlist, an extra system instruction and a
 * disclaimer (AiDomainClassifier → AiDomainPipelineService). A catalog entity like Country: the name / description
 * the admin reads live in ai_domain_policy_translations; domain_key, the switches, the system prompt addition and the
 * disclaimer (an instruction to the model, which translates it into the reply's language) are technical and not
 * translated; `status` is the on/off switch and deleting moves it to the trash.
 *
 * Switched off or in the trash = never applied (the classifier skips it, so the message gets no domain guidance).
 *
 * Back-compat (HasFlatTranslatedText): `$policy->name / description` read the current language, `$policy->is_active`
 * reads `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiDomainPolicy extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    public const DOMAIN_LEGAL = 'legal';

    public const DOMAIN_HEALTH = 'health';

    public const DOMAIN_EDUCATION = 'education';

    public const DOMAIN_CODE = 'code';

    public const DOMAIN_MARKETING = 'marketing';

    public const DOMAIN_GENERAL_INFO = 'general_info';

    /** @var list<string> */
    public const DOMAINS = [
        self::DOMAIN_LEGAL, self::DOMAIN_HEALTH, self::DOMAIN_EDUCATION,
        self::DOMAIN_CODE, self::DOMAIN_MARKETING, self::DOMAIN_GENERAL_INFO,
    ];

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    /** @var list<string> */
    public const RISKS = [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH, self::RISK_CRITICAL];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain_key',
        'name',
        'description',
        'risk_level',
        'requires_jurisdiction',
        'requires_triage',
        'sandbox_required',
        'allowlist_enforced',
        'system_prompt_addition',
        'disclaimer_text',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_jurisdiction' => 'boolean',
            'requires_triage' => 'boolean',
            'sandbox_required' => 'boolean',
            'allowlist_enforced' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_domain_policy_translations (HasTranslations' saved hook runs
        // first): drop the cached relations so the next read sees it.
        static::saved(function (self $policy): void {
            $policy->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiDomainPolicyTranslation::class, 'ai_domain_policy_id');
    }

    protected function translationModel(): string
    {
        return AiDomainPolicyTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name', 'description'];
    }
}
