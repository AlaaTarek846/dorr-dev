<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\User\Models\User;

/** A learner who bought a pack (O-26b), with the lessons they booked. */
class LessonEnrollment extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (LessonEnrollment $e) => $e->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'lessons_total' => 'integer', 'lessons_done' => 'integer', 'amount_minor' => 'integer', 'refunded_minor' => 'integer',
            'wallet_transaction_ids' => 'array', 'payment_expires_at' => 'datetime', 'discount_minor' => 'integer',
        ];
    }

    /** Lessons still free to book (not done, not booked and waiting). */
    public function lessonsLeft(): int
    {
        return max(0, $this->lessons_total - $this->sessions()->whereIn('status', ['requested', 'confirmed', 'done'])->count());
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(LessonPack::class, 'lesson_pack_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LessonSession::class);
    }
}
