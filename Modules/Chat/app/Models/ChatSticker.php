<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;

/**
 * One sticker: a transparent PNG / WebP (animated WebP works too), and the emoji it stands for.
 */
class ChatSticker extends Model implements HasMedia
{
    use HasMediaTrait;

    public const IMAGE = 'image';

    protected $fillable = ['pack_id', 'emoji', 'sort_order'];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(ChatStickerPack::class, 'pack_id');
    }

    public function imageUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::IMAGE) ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $media = $this->getSingleMedia(self::IMAGE);

        return [
            'id' => $this->id,
            'pack_id' => $this->pack_id,
            'emoji' => $this->emoji,
            'url' => $this->imageUrl(),
            'width' => $media?->getCustomProperty('width'),
            'height' => $media?->getCustomProperty('height'),
        ];
    }
}
