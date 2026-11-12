<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One thing to do on the calendar (U06) or made in the ad studio (U07): a post, an ad (with A/B variants) or a plain
 * task. Content states (spec 05): DRAFT → POLICY_REVIEW → APPROVED → SCHEDULED → PUBLISHED / FAILED / REJECTED.
 * Nothing is published by DORR in phase B: "published" is the person telling us they posted it.
 */
class MarketingContentItem extends Model
{
    use SoftDeletes;

    public const KINDS = ['post', 'ad', 'task'];

    public const CHANNELS = ['instagram', 'tiktok', 'snapchat', 'x', 'facebook', 'youtube', 'google', 'linkedin', 'whatsapp', 'email', 'sms', 'website', 'offline', 'other'];

    public const FORMATS = ['text', 'image', 'video', 'story', 'reel', 'carousel'];

    public const DRAFT = 'DRAFT';

    public const POLICY_REVIEW = 'POLICY_REVIEW';

    public const APPROVED = 'APPROVED';

    public const SCHEDULED = 'SCHEDULED';

    public const PUBLISHED = 'PUBLISHED';

    public const FAILED = 'FAILED';

    public const REJECTED = 'REJECTED';

    public const OPEN = 'OPEN';

    public const DONE = 'DONE';

    protected $fillable = [
        'uuid', 'project_id', 'plan_id', 'kind', 'channel', 'format', 'title', 'objective', 'content', 'claims', 'status',
        'planned_at', 'cost_estimate_minor', 'origin', 'review_note', 'published_url', 'done_at', 'lock_version',
        'created_by_type', 'created_by_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'claims' => 'array',
            'planned_at' => 'datetime',
            'done_at' => 'datetime',
            'cost_estimate_minor' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isTask(): bool
    {
        return $this->kind === 'task';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MarketingPlan::class, 'plan_id');
    }

    /** What an approval is bound to: the words and where they go, not the time or the cost estimate. */
    public function approvalPayload(): array
    {
        return ['kind' => $this->kind, 'channel' => $this->channel, 'format' => $this->format, 'title' => $this->title, 'content' => $this->content];
    }
}
