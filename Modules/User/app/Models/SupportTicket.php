<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\Admin;
use Modules\User\Enums\SupportTicketStatus;

class SupportTicket extends Model
{
    protected $fillable = [
        'number',
        'user_id',
        'admin_id',
        'title',
        'body',
        'image_path',
        'status',
        'last_message_at',
        'auto_reply_stopped_at',
    ];

    protected static function booted(): void
    {
        // Every ticket gets its own random number when it is created.
        static::creating(function (self $ticket) {
            $ticket->number ??= self::generateNumber();
        });
    }

    /** A random 7-digit number nobody else has: what the customer and the team quote, not the database id. */
    public static function generateNumber(): string
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'last_message_at' => 'datetime',
            'auto_reply_stopped_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The support agent who took the ticket (the first one to answer it). */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class)->latestOfMany();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SupportTicketActivity::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
