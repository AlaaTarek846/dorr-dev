<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/** A client-freelancer room about one project (FL-006). */
class FreelanceConversation extends Model
{
    public const OPEN = 'open';

    public const READ_ONLY = 'read_only';

    public const CLOSED = 'closed';

    protected $fillable = ['uuid', 'client_id', 'freelancer_id', 'context_type', 'context_id', 'status', 'last_message_at', 'last_message_preview', 'client_unread', 'freelancer_unread'];

    protected static function booted(): void
    {
        static::creating(function (FreelanceConversation $conversation): void {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'client_unread' => 'integer',
            'freelancer_unread' => 'integer',
            'context_id' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FreelanceMessage::class, 'conversation_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
