<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * FL-708: a warning, a limit or a suspension on one person (client or freelancer), with its appeal. Its state comes from the dates:
 * lifted (by staff or an appeal), expired (its end passed), or active.
 */
class FreelanceSanction extends Model
{
    public const WARNING = 'warning';

    public const CHAT_LIMIT = 'chat_limit';

    public const WORK_LIMIT = 'work_limit';

    public const SUSPENSION = 'suspension';

    public const TYPES = [self::WARNING, self::CHAT_LIMIT, self::WORK_LIMIT, self::SUSPENSION];

    /** What each type stops: `chat` = sending messages and interviews, `work` = new offers, projects, hiring and invitations. */
    public const BLOCKS = [self::WARNING => [], self::CHAT_LIMIT => ['chat'], self::WORK_LIMIT => ['work'], self::SUSPENSION => ['chat', 'work']];

    public const SOURCES = ['manual', 'message_report', 'review_objection', 'dispute'];

    protected $fillable = [
        'uuid', 'subject_type', 'subject_id', 'type', 'reason', 'source', 'source_ref', 'issued_by', 'starts_at', 'ends_at',
        'lifted_by', 'lift_note', 'lifted_at', 'appeal_note', 'appealed_at', 'appeal_status', 'appeal_decided_by', 'appeal_decision_note', 'appeal_decided_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'lifted_at' => 'datetime', 'appealed_at' => 'datetime', 'appeal_decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function state(): string
    {
        return match (true) {
            $this->lifted_at !== null => 'lifted',
            $this->ends_at !== null && $this->ends_at->isPast() => 'expired',
            default => 'active',
        };
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('lifted_at')->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function scopeFor(Builder $q, string $side, int $id): Builder
    {
        return $q->where('subject_type', $side)->where('subject_id', $id);
    }
}
