<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\User\Models\User;

/** A comment on an ad — seen by everyone who sees the ad (D4). */
class AdComment extends Model
{
    use SoftDeletes;

    protected $table = 'we_comments';

    protected $fillable = ['ad_id', 'user_id', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
