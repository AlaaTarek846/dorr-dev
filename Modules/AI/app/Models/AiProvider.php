<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AI\Enums\AiModelCapability;

class AiProvider extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'is_enabled',
        'is_default',
        'api_key',
        'model',
        'base_url',
        'temperature',
        'max_tokens',
        'extra',
        'available_models',
        'available_models_synced_at',
        'last_test_status',
        'last_test_message',
        'last_tested_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'api_key' => 'encrypted',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'extra' => 'array',
            'available_models' => 'array',
            'available_models_synced_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }

    public function isUsableForChat(): bool
    {
        return $this->is_enabled && $this->hasApiKey();
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiProviderModel::class, 'provider_id')->orderBy('sort_order');
    }

    public function activeModels(): HasMany
    {
        return $this->models()->where('is_active', true);
    }

    /**
     * The registered model this provider should use for plain-chat
     * requests: the one flagged is_default among its active
     * ai_provider_models rows, or null when the admin hasn't registered
     * any models yet - callers fall back to the legacy single $model
     * column in that case (see AiRoutingEngine / AiChatService).
     */
    public function defaultRegisteredModel(): ?AiProviderModel
    {
        // Root-cause fix - real, observed bug: this fallback used to be a
        // bare ->first() with NO capability filter at all, so whichever
        // active model happened to sort first "won" as "the provider's
        // default chat model" even when it was an image-generation-only
        // row (e.g. "gpt-image-2.5-flare", registered active but
        // deliberately never is_default by AiProviderModelSyncService -
        // see its own docblock). Two real callers rely on this: the
        // "Reclassify with AI" feature (AiModelCapabilityClassifier, which
        // literally sends a chat/completions call using whatever this
        // returns) and, far more seriously, AiRoutingEngine's normal
        // routing path for every PLAIN chat message with no special
        // capability requirement - so this one gap could silently route
        // ordinary conversation to an image-only model and fail every
        // single message with "This is not a chat model ... Did you mean
        // to use v1/completions?" until an admin happened to notice and
        // manually flip is_default on a real chat model. The fallback now
        // only ever considers a model that is actually chat-capable, so
        // an image-generation-only (or any other non-chat) row can never
        // be silently promoted to "the default" again, however it sorts.
        return $this->activeModels()->where('is_default', true)->first()
            ?? $this->activeModels()->get()->first(
                fn (AiProviderModel $model) => $model->hasCapability(AiModelCapability::Chat)
            );
    }

    /**
     * Best active, registered model of this provider that has every
     * capability in $required, ordered by sort_order. Null when the
     * provider has no models registered at all, or none of them match.
     *
     * @param  list<string>  $required
     */
    public function bestModelFor(array $required): ?AiProviderModel
    {
        return $this->activeModels()
            ->get()
            ->first(fn (AiProviderModel $model) => $model->hasAllCapabilities($required));
    }

    public function hasAnyRegisteredModels(): bool
    {
        return $this->models()->exists();
    }

    public function maskedApiKey(): ?string
    {
        if (! $this->hasApiKey()) {
            return null;
        }

        $key = (string) $this->api_key;
        $visible = substr($key, -4);

        return str_repeat('•', 8).$visible;
    }
}
