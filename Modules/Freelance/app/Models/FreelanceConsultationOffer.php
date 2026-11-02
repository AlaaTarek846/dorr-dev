<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One paid consultation a freelancer lists (FL-L07), like a ready-made service: a title and a few words, the lengths sold with a price
 * each, and the weekly hours clients can book in — in the freelancer's own time zone. It goes through the staff's review before clients
 * see it (`draft` → `pending_review` → `published` | `rejected`, `published` ⇄ `paused`, `suspended` by the staff); only
 * ConsultationListings moves its status.
 */
class FreelanceConsultationOffer extends Model
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending_review';

    public const PUBLISHED = 'published';

    public const PAUSED = 'paused';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const STATUSES = [self::DRAFT, self::PENDING, self::PUBLISHED, self::PAUSED, self::REJECTED, self::SUSPENDED];

    protected $fillable = [
        'uuid', 'freelancer_id', 'country_id', 'title', 'about', 'status', 'status_reason', 'submitted_at', 'reviewed_at', 'reviewed_by', 'published_at',
        'options', 'weekly', 'timezone',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceConsultationOffer $o) => $o->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'options' => 'array', 'weekly' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime',
        ];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(FreelanceConsultation::class, 'offer_id');
    }

    /** @return list<array{minutes: int, price_minor: int}> shortest first */
    public function lengths(): array
    {
        return collect((array) $this->options)->map(fn ($o) => ['minutes' => (int) $o['minutes'], 'price_minor' => (int) $o['price_minor']])
            ->sortBy('minutes')->values()->all();
    }

    public function priceFor(int $minutes): ?int
    {
        foreach ($this->lengths() as $o) {
            if ($o['minutes'] === $minutes) {
                return $o['price_minor'];
            }
        }

        return null;
    }
}
