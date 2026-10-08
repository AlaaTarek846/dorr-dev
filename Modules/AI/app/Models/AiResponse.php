<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiResponse extends Model
{
    use HasFactory;

    const FINISH_COMPLETED = 'completed';

    const FINISH_LENGTH_LIMIT = 'length_limit';

    const FINISH_TOOL_CALL = 'tool_call';

    const FINISH_ERROR = 'error';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'response',
        'finish_reason',
    ];

    /**
     * Bug found 2026-09-24 (manual chat testing, right after the
     * ai_provider_health/ai_usage table-name bug): AiChatService::
     * sendMessage() has always written an array (content + message)
     * into 'response', but this model never cast it - so every single
     * successful OR failed chat reply crashed at the final
     * AiResponse::create() with "Array to string conversion" the moment
     * a real database was actually queried, which php -l alone can
     * never catch. The column is a plain longText, not json, but
     * Eloquent's 'array' cast json_encodes/decodes transparently
     * regardless of the underlying column type, so no migration change
     * is needed - only this cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }
}
