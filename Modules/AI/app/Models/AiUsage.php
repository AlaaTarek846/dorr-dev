<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsage extends Model
{
    use HasFactory;

    // Same class of bug as AiProviderHealth (found 2026-09-24): migration
    // creates 'ai_usage' (singular); Eloquent's default guess without
    // this override would be 'ai_usages'. This one is on the hot path -
    // AiChatService::sendMessage() writes to this model on every
    // successful reply, so this was a live, previously-undiscovered
    // crash risk, not a dormant one.
    protected $table = 'ai_usage';


    const TYPE_ESTIMATED = 'estimated';

    const TYPE_ACTUAL = 'actual';

    const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'input_cost',
        'output_cost',
        'total_cost',
        'usage_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'input_cost' => 'decimal:6',
            'output_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }
}
