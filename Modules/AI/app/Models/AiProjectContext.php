<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProjectContext extends Model
{
    public const TYPE_DECISION = 'decision';

    public const TYPE_CONSTRAINT = 'constraint';

    public const TYPE_SUMMARY = 'summary';

    protected $table = 'ai_project_context';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'context_type',
        'content',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'project_id');
    }
}
