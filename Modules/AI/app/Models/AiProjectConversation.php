<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProjectConversation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'conversation_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'project_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
