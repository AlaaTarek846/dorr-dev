<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProjectKnowledge extends Model
{
    // Same class of bug as AiProviderHealth (found 2026-09-24): migration
    // creates 'ai_project_knowledge' (singular); Eloquent's default guess
    // without this override would be 'ai_project_knowledges'.
    protected $table = 'ai_project_knowledge';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'knowledge_source_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'project_id');
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeSource::class, 'knowledge_source_id');
    }
}
