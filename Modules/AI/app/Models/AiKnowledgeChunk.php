<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeChunk extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'knowledge_source_id',
        'chunk_index',
        'content_ref',
        'token_count',
        'searchable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'token_count' => 'integer',
            'searchable' => 'boolean',
        ];
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeSource::class, 'knowledge_source_id');
    }
}
