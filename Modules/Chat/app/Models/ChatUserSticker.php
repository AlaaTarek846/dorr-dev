<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

/**
 * A sticker someone made from their own photo (cut out, outlined, 512×512 transparent WebP / PNG).
 * Soft-deleted when removed from "My stickers", so messages that already carry it keep their image.
 */
class ChatUserSticker extends Model implements HasMedia
{
    use HasMediaTrait;
    use SoftDeletes;

    public const IMAGE = 'image';

    protected $fillable = ['owner_type', 'owner_id', 'emoji'];

    public function imageUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::IMAGE) ?: null;
    }

    public function isOwnedBy(string $type, int $id): bool
    {
        return $this->owner_type === $type && (int) $this->owner_id === $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $media = $this->getSingleMedia(self::IMAGE);

        return [
            'id' => $this->id,
            'emoji' => $this->emoji,
            'url' => $this->imageUrl(),
            'width' => $media?->getCustomProperty('width'),
            'height' => $media?->getCustomProperty('height'),
        ];
    }
}
