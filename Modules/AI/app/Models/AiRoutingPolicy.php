<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A routing policy: which set of routing rules applies to a chat message (global, or scoped to a country / plan),
 * the highest priority winning (AiRoutingEngine). A catalog entity like Country: its name lives in
 * ai_routing_policy_translations; scope, codes, strategy and priority are technical values and are not translated;
 * `status` is the on/off switch and deleting moves it to the trash.
 *
 * Switched off or in the trash = never used for routing - neither picked by scope nor as a gateway's default policy.
 *
 * Back-compat (HasFlatTranslatedText): `$policy->name` reads the current language, `$policy->is_active` reads
 * `status`, and a flat name (`create(['name' => ...])`) is written into every stored language.
 */
class AiRoutingPolicy extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    /** The name is shown wherever a policy is (gateways, rules), so its translations always come along. */
    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'scope_type', 'country_code', 'service_key', 'plan_id',
        'selection_strategy', 'fallback_enabled', 'priority', 'status', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fallback_enabled' => 'boolean',
            'priority' => 'integer',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_routing_policy_translations (HasTranslations' saved hook runs
        // first): drop the cached relations so the next read sees it.
        static::saved(function (self $policy): void {
            $policy->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiRoutingPolicyTranslation::class, 'ai_routing_policy_id');
    }

    protected function translationModel(): string
    {
        return AiRoutingPolicyTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name'];
    }

    public function plan(): BelongsTo
    {
        // withTrashed: a plan moved to the trash still names what was bought / configured.
        return $this->belongsTo(AiPlan::class, 'plan_id')->withTrashed();
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AiRoutingRule::class, 'routing_policy_id');
    }

    /** Every rule of this policy, in the trash too (deleting the policy for good deletes them all - cascade). */
    public function routingRules(): HasMany
    {
        return $this->hasMany(AiRoutingRule::class, 'routing_policy_id')->withTrashed();
    }

    /** Every gateway using this policy as its default, in the trash too (deleting it for good would unlink them). */
    public function gateways(): HasMany
    {
        return $this->hasMany(AiGateway::class, 'default_policy_id')->withTrashed();
    }
}
