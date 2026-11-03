<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A comment on a community post (FL-L12), or a reply to one (one level only). */
class FreelanceCommunityComment extends Model
{
    use SoftDeletes;

    protected $table = 'freelance_community_comments';

    protected $fillable = [
        'uuid', 'post_id', 'freelancer_id', 'parent_id', 'body', 'reactions_count', 'replies_count',
        'status', 'hidden_reason', 'hidden_by_admin_id', 'hidden_at', 'edited_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceCommunityComment $c) => $c->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime', 'edited_at' => 'datetime', 'reactions_count' => 'integer', 'replies_count' => 'integer'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(FreelanceCommunityPost::class, 'post_id')->withTrashed();
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isHidden(): bool
    {
        return $this->status === FreelanceCommunityPost::HIDDEN;
    }
}
