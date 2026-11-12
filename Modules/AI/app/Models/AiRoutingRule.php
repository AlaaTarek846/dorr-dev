<?php

namespace Modules\AI\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasIsActiveAlias;

/**
 * One routing rule of a policy: "for this intent (or any), send to this provider / model", the highest priority
 * first (AiRoutingEngine). Has the countries list (status, trash) but no translation table: it holds no text people
 * read - ids, a model id, a priority and JSON settings.
 *
 * Switched off or in the trash = never used for routing. `$rule->is_active` reads `status` (HasIsActiveAlias).
 */
class AiRoutingRule extends Model
{
    use HasIsActiveAlias, SearchFilterTrait, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'routing_policy_id', 'intent_id', 'provider_id', 'model_key',
        'priority', 'selection_config', 'status', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selection_config' => 'array',
            'priority' => 'integer',
            'status' => 'boolean',
        ];
    }

    /** withTrashed: the screen still names a policy that was moved to the trash (the engine only reads live ones). */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiRoutingPolicy::class, 'routing_policy_id')->withTrashed();
    }

    /**
     * withTrashed: the screen still names an intent that was moved to the trash. The engine compares intent_id
     * with the classified intent, and a trashed intent is never classified, so such a rule never applies.
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(AiIntent::class, 'intent_id')->withTrashed();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
