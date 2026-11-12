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
 * A security policy: the four switches the AI service is meant to require (sign-in, permission check, tenant
 * isolation, rate limiting). A catalog entity like Country: the name / description the admin reads live in
 * ai_security_policy_translations; the switches are technical and not translated; `status` is the on/off switch and
 * deleting moves it to the trash (= switched off).
 *
 * Nothing in the engine reads this table today: sign-in, permissions, tenant isolation and rate limits are enforced in
 * code (auth guards, admin.screen, owner scoping, throttle). The row records the policy.
 *
 * Back-compat (HasFlatTranslatedText): `$policy->name / description` read the current language, `$policy->is_active`
 * reads `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiSecurityPolicy extends Model
{
    use HasFactory, HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'authentication_required',
        'authorization_required',
        'tenant_isolation_required',
        'rate_limit_enabled',
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
            'authentication_required' => 'boolean',
            'authorization_required' => 'boolean',
            'tenant_isolation_required' => 'boolean',
            'rate_limit_enabled' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_security_policy_translations (HasTranslations' saved hook runs
        // first): drop the cached relations so the next read sees it.
        static::saved(function (self $policy): void {
            $policy->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiSecurityPolicyTranslation::class, 'ai_security_policy_id');
    }

    protected function translationModel(): string
    {
        return AiSecurityPolicyTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name', 'description'];
    }
}
