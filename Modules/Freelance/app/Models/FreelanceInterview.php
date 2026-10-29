<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A short interview before the agreement (FL-310).
 *
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property int $project_id
 * @property int $client_id
 * @property int $freelancer_id
 * @property string $proposed_by
 * @property \Illuminate\Support\Carbon $starts_at
 * @property int $duration_minutes
 * @property ?string $note
 * @property string $status
 * @property ?int $calendar_item_id
 */
class FreelanceInterview extends Model
{
    public const PROPOSED = 'proposed';

    public const CONFIRMED = 'confirmed';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    /** A newer proposal replaced it. */
    public const SUPERSEDED = 'superseded';

    public const OPEN = [self::PROPOSED, self::CONFIRMED];

    protected $fillable = [
        'uuid', 'conversation_id', 'project_id', 'client_id', 'freelancer_id', 'proposed_by', 'starts_at', 'duration_minutes', 'note',
        'status', 'responded_at', 'calendar_item_id', 'reminded_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (FreelanceInterview $i): void {
            $i->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'responded_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(FreelanceConversation::class, 'conversation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }
}
