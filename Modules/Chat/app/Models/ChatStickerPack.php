<?php

namespace Modules\Chat\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/**
 * A sticker pack made by the admin: a name per language, a cover, and its stickers.
 */
class ChatStickerPack extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations;

    public const COVER = 'cover';

    protected $fillable = ['sort_order', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatStickerPackTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatStickerPackTranslation::class;
    }

    public function stickers(): HasMany
    {
        return $this->hasMany(ChatSticker::class, 'pack_id')->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function coverUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::COVER) ?: $this->stickers->first()?->imageUrl();
    }
}
