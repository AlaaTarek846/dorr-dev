<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press at the end of a help topic in the app: solved (true) or "I need an agent" (false).
 */
class SupportHelpFeedback extends Model
{
    protected $table = 'support_help_feedback';

    protected $fillable = ['support_help_node_id', 'user_id', 'solved'];

    protected function casts(): array
    {
        return ['solved' => 'boolean'];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(SupportHelpNode::class, 'support_help_node_id');
    }
}
