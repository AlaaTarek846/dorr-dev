<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatStoryView extends Model
{
    public $timestamps = false;

    protected $fillable = ['story_id', 'viewer_type', 'viewer_id', 'viewed_at', 'reaction', 'hidden'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime', 'hidden' => 'boolean'];
    }
}
