<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\Admin;
use Modules\User\Enums\SupportTicketStatus;

class SupportTicket extends Model
{
    protected $fillable = [
        'number',
        'user_id',
        'driver_id',
        'freelancer_id',
        'ride_id',
        'category',
        'reference',
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

    /** D-285: a captain's ticket. */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(\Modules\Driver\Models\Driver::class);
    }

    /** A freelancer's ticket. */
    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(\Modules\Freelance\Models\Freelancer::class);
    }

    /** Whoever opened it — a rider, a captain or a freelancer. */
    public function getOwnerAttribute(): ?\Illuminate\Database\Eloquent\Model
    {
        return match (true) {
            $this->driver_id !== null => $this->driver,
            $this->freelancer_id !== null => $this->freelancer,
            default => $this->user,
        };
    }

    /** The private channel of whoever opened it. */
    public function ownerChannel(): string
    {
        return match (true) {
            $this->driver_id !== null => 'Modules.Driver.Models.Driver.'.$this->driver_id,
            $this->freelancer_id !== null => 'Modules.Freelance.Models.Freelancer.'.$this->freelancer_id,
            default => 'Modules.User.Models.User.'.$this->user_id,
        };
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

    /** The ride this ticket is about, as `{uuid, number}` (R-127); null when it isn't about one. */
    public function rideRef(): ?array
    {
        if ($this->ride_id === null) {
            return null;
        }
        $ride = DB::table('rides')->where('id', $this->ride_id)->first(['uuid', 'number']);

        return $ride === null ? null : ['uuid' => $ride->uuid, 'number' => $ride->number];
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
