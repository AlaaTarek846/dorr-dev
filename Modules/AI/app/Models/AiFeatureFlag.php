<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;
use Modules\AI\Services\AiFeatureFlagGate;

/**
 * A feature flag - the admin's kill switch for a provider, a model, a tool or a whole DORR AI service (by country /
 * domain / environment), read by AiFeatureFlagGate. A catalog entity like Country: the description the admin reads
 * lives in ai_feature_flag_translations; key, target, provider / model / tool / service keys, country, domain and
 * environment are technical values and are not translated.
 *
 * `status` is the flag's on/off column (it was `is_enabled`): status off = the kill switch is on - its target is
 * turned off where the flag's scope matches; status on = the flag does nothing. Deleting moves it to the trash, and
 * a trashed flag is never applied (as if it were deleted), so its target is allowed again.
 *
 * Back-compat: `$flag->is_enabled` (and `is_active`) read / write `status`; `$flag->description` reads the current
 * language (HasFlatTranslatedText) and a flat description is written into every stored language.
 */
class AiFeatureFlag extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    public const TARGET_PROVIDER = 'provider';

    public const TARGET_MODEL = 'model';

    public const TARGET_TOOL = 'tool';

    /** A whole DORR AI service switched off (by country / domain) — its key is in `tool_key` (spec "Feature Flag مستقل"). */
    public const TARGET_SERVICE = 'service';

    /** The DORR AI chat itself, as a TARGET_SERVICE key. */
    public const SERVICE_AI_CHAT = 'ai_chat';

    /** @var list<string> */
    public const TARGETS = [self::TARGET_PROVIDER, self::TARGET_MODEL, self::TARGET_TOOL, self::TARGET_SERVICE];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'target_type',
        'provider_id',
        'model_key',
        'tool_key',
        'country_code',
        'domain',
        'environment',
        'status',
        'is_enabled',
        'is_active',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_feature_flag_translations (HasTranslations' saved hook runs
        // first): drop the cached relations so the next read sees it.
        static::saved(function (self $flag): void {
            $flag->unsetRelation('translations')->unsetRelation('translation');
        });

        // The gate caches the switched-off flags for a few seconds: any change applies at once.
        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::{$event}(fn () => AiFeatureFlagGate::flushCache());
        }
    }

    /** `is_enabled` was the flag's column - it is the catalog's `status` now. */
    public function getIsEnabledAttribute(): bool
    {
        return (bool) ($this->attributes['status'] ?? false);
    }

    public function setIsEnabledAttribute(mixed $value): void
    {
        $this->attributes['status'] = (bool) $value;
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiFeatureFlagTranslation::class, 'ai_feature_flag_id');
    }

    protected function translationModel(): string
    {
        return AiFeatureFlagTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['description'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
