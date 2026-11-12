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
 * An AI gateway: the entry the chat goes through. AiRoutingEngine takes the first switched-on `production` gateway
 * (lowest id), records it on every ai_requests row, and falls back to its default policy when no policy matches by
 * scope. A catalog entity like Country: its name lives in ai_gateway_translations; the environment and the default
 * policy are technical values; `status` is the on/off switch and deleting moves it to the trash.
 *
 * Switched off or in the trash = never used for routing.
 *
 * Back-compat (HasFlatTranslatedText): `$gateway->name` reads the current language, `$gateway->is_active` reads
 * `status`, and a flat name (`create(['name' => ...])`) is written into every stored language.
 *
 * Not to be confused with Modules\AI\Services\AiGateway (the class that calls the providers).
 */
class AiGateway extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    protected $table = 'ai_gateways';

    /** The name is shown wherever a gateway is (requests log), so its translations always come along. */
    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'environment', 'default_policy_id', 'status', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_gateway_translations (HasTranslations' saved hook runs first):
        // drop the cached relations so the next read sees it.
        static::saved(function (self $gateway): void {
            $gateway->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiGatewayTranslation::class, 'ai_gateway_id');
    }

    protected function translationModel(): string
    {
        return AiGatewayTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name'];
    }

    /**
     * withTrashed: the screen still names a default policy that was moved to the trash. The routing engine
     * checks it is switched on and not trashed before falling back to it (AiRoutingEngine).
     */
    public function defaultPolicy(): BelongsTo
    {
        return $this->belongsTo(AiRoutingPolicy::class, 'default_policy_id')->withTrashed();
    }

    /** The chat requests that went through this gateway (ai_requests) - history that names it. */
    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class, 'gateway_id');
    }
}
