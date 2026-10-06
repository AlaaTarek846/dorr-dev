<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

class ChatPortalView extends Model
{
    protected $fillable = ['chat_portal_id', 'viewer_type', 'viewer_id', 'viewed_on'];

    protected function casts(): array
    {
        return ['viewed_on' => 'date'];
    }
}
