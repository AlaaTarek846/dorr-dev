<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

/** The advertiser's report on a comment under their ad (D21) — the admin removes the comment or keeps it. */
class CommentReport extends Model
{
    public const REASONS = ['offensive', 'spam', 'misleading', 'harassment', 'other'];

    public const PENDING = 'pending';

    public const REMOVED = 'removed';

    public const KEPT = 'kept';

    protected $table = 'we_comment_reports';

    protected $fillable = ['comment_id', 'ad_id', 'reporter_id', 'reason', 'note', 'status', 'decision_note', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(AdComment::class, 'comment_id')->withTrashed();
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
