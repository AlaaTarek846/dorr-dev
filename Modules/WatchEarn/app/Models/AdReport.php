<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

/** One viewer's report on an ad (WE-035) — enough unique ones suspend it. */
class AdReport extends Model
{
    public const REASONS = ['misleading', 'offensive', 'adult', 'violence', 'scam', 'other'];

    protected $table = 'we_reports';

    protected $fillable = ['ad_id', 'user_id', 'reason', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
