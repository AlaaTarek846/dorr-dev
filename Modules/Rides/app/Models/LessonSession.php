<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One booked driving lesson (O-26b). */
class LessonSession extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (LessonSession $s) => $s->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['number' => 'integer', 'starts_at' => 'datetime', 'done_at' => 'datetime', 'pickup_lat' => 'float', 'pickup_lng' => 'float'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LessonEnrollment::class, 'lesson_enrollment_id');
    }
}
