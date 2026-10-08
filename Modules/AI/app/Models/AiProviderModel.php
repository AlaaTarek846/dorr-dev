<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Enums\AiModelCapability;

class AiProviderModel extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'model_key',
        'display_name',
        'capabilities',
        'temperature',
        'temperature_supported',
        'max_tokens',
        'max_output_tokens',
        'context_window',
        'is_default',
        'is_active',
        'sort_order',
        // Dynamic model registry fields (see the
        // add_registry_fields_to_ai_provider_models_table migration) -
        // purely descriptive/lifecycle metadata layered on top of the
        // fields above, never read by the existing capability-matching
        // pipeline (bestModelFor()/hasAllCapabilities()/activeModels()
        // still only ever look at capabilities/is_active).
        'category',
        'needs_review',
        'model_family',
        'canonical_model_id',
        'is_alias',
        'is_snapshot',
        'release_date',
        'status',
        'deprecated_at',
        'last_seen_at',
        'endpoints',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'temperature' => 'float',
            'temperature_supported' => 'boolean',
            'max_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'context_window' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'needs_review' => 'boolean',
            'is_alias' => 'boolean',
            'is_snapshot' => 'boolean',
            'release_date' => 'date',
            'deprecated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'endpoints' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /**
     * @param  list<string>  $required
     */
    public function hasAllCapabilities(array $required): bool
    {
        if ($required === []) {
            return true;
        }

        $own = $this->capabilities ?? [];

        return count(array_intersect($required, $own)) === count($required);
    }

    public function hasCapability(AiModelCapability|string $capability): bool
    {
        $value = $capability instanceof AiModelCapability ? $capability->value : $capability;

        return in_array($value, $this->capabilities ?? [], true);
    }

    public function isDeprecated(): bool
    {
        return $this->status === 'deprecated';
    }
}
