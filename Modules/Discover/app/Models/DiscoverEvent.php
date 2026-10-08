<?php

namespace Modules\Discover\Models;

use App\Models\Country;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/** A public event from a trusted source (spec 169, 174–176). Times are UTC with the place's zone. */
class DiscoverEvent extends Model implements HasMedia
{
    use HasMediaTrait;

    public const COVER = 'cover';

    public const STATUSES = ['confirmed', 'postponed', 'cancelled', 'ended', 'sold_out'];

    protected $fillable = [
        'uuid', 'organizer_id', 'source', 'title', 'description', 'language', 'category_id', 'country_id', 'city_id', 'venue', 'address', 'lat', 'lng',
        'starts_at', 'ends_at', 'timezone', 'is_free', 'price_text', 'source_url', 'booking_url', 'family_friendly', 'status', 'review_status', 'review_note',
        'last_verified_at', 'dedupe_key', 'interested_count',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'last_verified_at' => 'datetime', 'is_free' => 'boolean', 'family_friendly' => 'boolean',
            'lat' => 'float', 'lng' => 'float', 'interested_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(DiscoverOrganizer::class, 'organizer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DiscoverCategory::class, 'category_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(DiscoverCity::class, 'city_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(DiscoverEventStatus::class, 'event_id');
    }

    /** What the public may see: approved by the admin (or a verified organizer's, published). */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('review_status', 'approved');
    }

    /** Not over yet (a long one still running counts). */
    public function scopeUpcoming(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', '!=', 'ended')
            ->where(fn ($q) => $q->where('starts_at', '>=', $now)->orWhere('ends_at', '>=', $now));
    }

    /**
     * Trusted: added by the admin, or from an organizer the admin verified — never just because
     * someone submitted it (AT-DISC-02).
     */
    public function isVerified(): bool
    {
        return $this->source === 'admin' || ($this->organizer?->isVerified() ?? false);
    }

    public function coverUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::COVER) ?: null;
    }

    public static function dedupeKey(string $title, int $cityId, ?string $localDate): string
    {
        return md5(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title))).'|'.$cityId.'|'.substr((string) $localDate, 0, 10));
    }
}
