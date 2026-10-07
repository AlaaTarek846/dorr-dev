<?php

namespace Modules\Chat\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/**
 * A category (sport, news, fashion…) picked by a channel and by a merchant portal — the admin's
 * list, each with an icon and a name per language.
 */
class ChatCategory extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations;

    public const ICON = 'icon';

    protected $fillable = ['sort_order', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatCategoryTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatCategoryTranslation::class;
    }

    public function portals(): HasMany
    {
        return $this->hasMany(ChatPortal::class, 'category_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ChatGroup::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function iconUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::ICON) ?: null;
    }

    /**
     * @return array{id: int, name: ?string, icon: ?string}
     */
    public function brief(): array
    {
        return ['id' => $this->id, 'name' => $this->translatedName(), 'icon' => $this->iconUrl()];
    }
}
