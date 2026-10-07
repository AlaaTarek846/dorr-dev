<?php

namespace Modules\Chat\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Chat\Support\ParticipantType;
use Spatie\MediaLibrary\HasMedia;

/**
 * A merchant's portal: their website as a card (logo, name and description in every language,
 * category, views). It sits on the portals page while a paid period runs (`listed_until`) and
 * stays the merchant's when it ends, ready for the next subscription.
 */
class ChatPortal extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SoftDeletes;

    public const LOGO = 'logo';

    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'category_id', 'website_url', 'status', 'views_count', 'listed_until'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'views_count' => 'integer', 'listed_until' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatPortalTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatPortalTranslation::class;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChatCategory::class, 'category_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ChatSubscription::class, 'subject_id')->where('subject_type', 'portal');
    }

    /** On the portals page right now. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('status', true)->where('listed_until', '>', now());
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === ParticipantType::aliasFor($owner) && (int) $this->owner_id === (int) $owner->getKey();
    }

    public function isListed(): bool
    {
        return $this->status && $this->listed_until !== null && $this->listed_until->isFuture();
    }

    public function logoUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::LOGO) ?: null;
    }
}
