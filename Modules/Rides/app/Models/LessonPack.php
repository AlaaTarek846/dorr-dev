<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;

/** A trainer's lesson pack (O-26b). */
class LessonPack extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (LessonPack $p) => $p->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'lessons' => 'integer', 'minutes_per_lesson' => 'integer', 'price_minor' => 'integer',
            'area_lat' => 'float', 'area_lng' => 'float', 'own_car' => 'boolean',
        ];
    }

    /** One lesson's share of the pack (the last lesson takes the remainder). */
    public function lessonShare(int $number): int
    {
        $each = intdiv($this->price_minor, max(1, $this->lessons));

        return $number >= $this->lessons ? $this->price_minor - $each * ($this->lessons - 1) : $each;
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(LessonEnrollment::class);
    }
}
