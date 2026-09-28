<?php

namespace Modules\Chat\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/**
 * A look for a conversation, made by the admin: a wallpaper (image or plain colour) and the two
 * bubble colours. At most one is the default — used where a person hasn't picked one.
 */
class ChatTheme extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations;

    public const WALLPAPER = 'wallpaper';

    protected $fillable = [
        'background_color',
        'sender_color',
        'receiver_color',
        'is_dark',
        'sort_order',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_dark' => 'boolean',
            'is_default' => 'boolean',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatThemeTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatThemeTranslation::class;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function wallpaperUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::WALLPAPER) ?: null;
    }

    /**
     * What the apps draw.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->translatedName(),
            'wallpaper' => $this->wallpaperUrl(),
            'background_color' => $this->background_color,
            'sender_color' => $this->sender_color,
            'receiver_color' => $this->receiver_color,
            'is_dark' => $this->is_dark,
            'is_default' => $this->is_default,
        ];
    }
}
