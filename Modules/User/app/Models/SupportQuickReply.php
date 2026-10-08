<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A ready answer for support agents: typing "/" + its shortcut in a ticket's reply box drops the text in.
 * The agent can still edit it before sending — it always goes out in that agent's name, never as an
 * automatic reply.
 */
class SupportQuickReply extends Model
{
    protected $fillable = ['shortcut', 'title', 'body', 'sort_order', 'status'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => 'boolean',
        ];
    }
}
