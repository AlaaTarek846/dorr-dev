<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/** FL-709: one report of a chat message. */
class FreelanceMessageReport extends Model implements HasMedia
{
    use HasMediaTrait;

    public const OPEN = 'open';

    public const DISMISSED = 'dismissed';

    public const UPHELD = 'upheld';

    protected $fillable = [
        'uuid', 'message_id', 'conversation_id', 'reporter_type', 'reporter_id', 'reason', 'note', 'message_body',
        'status', 'decided_by', 'decision_note', 'message_hidden', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['message_hidden' => 'boolean', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(FreelanceMessage::class, 'message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(FreelanceConversation::class, 'conversation_id');
    }
}
