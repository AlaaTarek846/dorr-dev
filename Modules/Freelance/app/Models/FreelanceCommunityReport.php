<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A member's report of a community post or comment that breaks the rules (FL-L12); the staff uphold (hide) or dismiss it. */
class FreelanceCommunityReport extends Model
{
    public const OPEN = 'open';

    public const UPHELD = 'upheld';

    public const DISMISSED = 'dismissed';

    public const STATUSES = [self::OPEN, self::UPHELD, self::DISMISSED];

    public const REASONS = ['spam', 'contact', 'abuse', 'off_topic', 'misleading', 'other'];

    protected $table = 'freelance_community_reports';

    protected $fillable = [
        'uuid', 'reporter_id', 'subject_type', 'subject_id', 'author_id', 'reason', 'details', 'status', 'decision_note', 'decided_by_admin_id', 'decided_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceCommunityReport $r) => $r->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'reporter_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'author_id')->withTrashed();
    }

    public function subject(): FreelanceCommunityPost|FreelanceCommunityComment|null
    {
        return $this->subject_type === 'post'
            ? FreelanceCommunityPost::withTrashed()->find($this->subject_id)
            : FreelanceCommunityComment::withTrashed()->find($this->subject_id);
    }
}
