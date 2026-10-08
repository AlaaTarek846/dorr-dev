<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFileCitation extends Model
{
    protected $fillable = [
        'request_id',
        'file_id',
        'chunk_id',
        'position',
        'excerpt',
        'relevance_score',
        'retrieval_method',
        'source_reference',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'relevance_score' => 'float',
            'source_reference' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AiFile::class, 'file_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(AiFileChunk::class, 'chunk_id');
    }
}
