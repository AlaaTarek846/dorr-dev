<?php

namespace App\Models;

use App\Enums\TranslationFileStatus;
use App\Enums\TranslationPlatform;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Metadata for one translation group of a dashboard-managed language.
 * The JSON itself lives in Media Library: a pending `draft` and the live `published` file.
 */
class TranslationFile extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DRAFT_COLLECTION = 'draft';

    public const PUBLISHED_COLLECTION = 'published';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'language_id',
        'platform',
        'group',
        'status',
        'checksum',
        'version',
        'published_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => TranslationPlatform::class,
            'status' => TranslationFileStatus::class,
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $disk = (string) config('translations.disk', 'local');

        $this->addMediaCollection(self::DRAFT_COLLECTION)->singleFile()->useDisk($disk);
        $this->addMediaCollection(self::PUBLISHED_COLLECTION)->singleFile()->useDisk($disk);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function draftMedia(): ?Media
    {
        return $this->getFirstMedia(self::DRAFT_COLLECTION);
    }

    public function publishedMedia(): ?Media
    {
        return $this->getFirstMedia(self::PUBLISHED_COLLECTION);
    }

    public function hasDraft(): bool
    {
        return $this->draftMedia() !== null;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->publishedMedia() !== null;
    }

    /**
     * Decoded JSON of a collection (draft or published); [] when absent.
     *
     * @return array<string, mixed>
     */
    public function contents(string $collection): array
    {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return [];
        }

        $json = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
