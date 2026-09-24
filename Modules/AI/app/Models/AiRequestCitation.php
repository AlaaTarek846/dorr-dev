<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequestCitation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'request_id',
        'knowledge_source_id',
        'knowledge_chunk_id',
        'position',
        'excerpt',
        'relevance_score',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'relevance_score' => 'float',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeSource::class, 'knowledge_source_id');
    }

    public function knowledgeChunk(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeChunk::class, 'knowledge_chunk_id');
    }
}
