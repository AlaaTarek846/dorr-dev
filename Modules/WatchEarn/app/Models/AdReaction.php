<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;

/** One viewer's reaction on an ad — the chat's reactions (D4). */
class AdReaction extends Model
{
    protected $table = 'we_reactions';

    protected $fillable = ['ad_id', 'user_id', 'emoji'];
}
