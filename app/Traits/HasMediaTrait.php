<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait HasMediaTrait
{
    use InteractsWithMedia;

    /**
     * Storage folder name under disk root, e.g. admin/5/avatar.jpg
     */
    public function mediaStorageFolder(): string
    {
        return strtolower(class_basename(static::class));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        //
    }

    /**
     * Alias for Spatie's media() relationship.
     */
    public function medias()
    {
        return $this->media();
    }

    public function getSingleMedia(string $collection): ?Media
    {
        return $this->getFirstMedia($collection);
    }

    public function getSingleMediaUrl(string $collection, ?string $conversion = null): string
    {
        $url = $conversion
            ? $this->getFirstMediaUrl($collection, $conversion)
            : $this->getFirstMediaUrl($collection);

        return $this->normalizeMediaUrl($url);
    }

    public function getSingleMediaOriginalUrl(string $collection): string
    {
        return $this->getSingleMediaUrl($collection);
    }

    public function getSingleMediaThumbUrl(string $collection): string
    {
        return $this->getSingleMediaUrl($collection);
    }

    /**
     * @return array<int, string>
     */
    public function getMultipleMediaUrls(string $collection, ?string $conversion = null): array
    {
        return $this->getMultipleMedia($collection)
            ->map(function (Media $media) use ($conversion) {
                $url = $conversion ? $media->getUrl($conversion) : $media->getUrl();

                return $this->normalizeMediaUrl($url);
            })
            ->all();
    }

    /**
     * Return a path-only URL so media works on any host/port (e.g. 127.0.0.1 vs localhost).
     */
    protected function normalizeMediaUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }

    public function getMultipleMedia(string $collection): Collection
    {
        return $this->getMedia($collection)->sortBy('order_column')->values();
    }

    /**
     * Build a deterministic stored file name from the media collection.
     */
    protected function mediaStorageFileName(UploadedFile $file, string $collection, int|string|null $suffix = null): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $stem = $suffix === null ? $collection : "{$collection}-{$suffix}";

        return "{$stem}.{$extension}";
    }

    /**
     * @return \Spatie\MediaLibrary\MediaCollections\FileAdder<$this>
     */
    protected function addUploadedMedia(UploadedFile $file, string $collection, int|string|null $suffix = null): \Spatie\MediaLibrary\MediaCollections\FileAdder
    {
        return $this->addMedia($file)
            ->usingFileName($this->mediaStorageFileName($file, $collection, $suffix));
    }

    /**
     * Replace the single media item in a collection (e.g. cover image).
     */
    public function setSingleMedia(string $collection, ?UploadedFile $file, ?string $name = null): ?Media
    {
        if (! $file instanceof UploadedFile) {
            return $this->getSingleMedia($collection);
        }

        $this->clearMediaCollection($collection);

        $adder = $this->addUploadedMedia($file, $collection);

        if ($name !== null) {
            $adder->usingName($name);
        }

        return $adder->toMediaCollection($collection);
    }

    /**
     * Append multiple uploads to a collection.
     *
     * Each item: ['file' => UploadedFile, 'name' => 'optional', 'order' => int]
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    public function addMultipleMedia(string $collection, array $files): void
    {
        $order = (int) ($this->getMedia($collection)->max('order_column') ?? 0);

        foreach ($files as $fileData) {
            if (! isset($fileData['file']) || ! $fileData['file'] instanceof UploadedFile) {
                continue;
            }

            /** @var UploadedFile $file */
            $file = $fileData['file'];
            $itemOrder = isset($fileData['order']) ? (int) $fileData['order'] : ++$order;

            $adder = $this->addUploadedMedia($file, $collection, $itemOrder);

            if (isset($fileData['name'])) {
                $adder->usingName($fileData['name']);
            }

            $adder->setOrder($itemOrder);
            $adder->toMediaCollection($collection);
        }
    }

    public function deleteSingleMedia(string $collection): bool
    {
        $media = $this->getSingleMedia($collection);

        if (! $media) {
            return false;
        }

        $media->delete();

        return true;
    }

    /**
     * Remove all media attached to this model.
     */
    public function cleanMedia(): void
    {
        $this->media()->each(fn (Media $media) => $media->delete());
    }

    /**
     * Sync a multi-file collection with optional reordering and new uploads.
     *
     * Existing item: ['id' => int, 'order' => int]
     * New item:      ['file' => UploadedFile, 'name' => 'optional', 'order' => int]
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function syncMultipleMedia(string $collection, array $items): void
    {
        $existingMedia = $this->getMedia($collection);

        $providedIds = collect($items)
            ->filter(fn (array $item) => isset($item['id']))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($existingMedia as $media) {
            if (! in_array($media->id, $providedIds, true)) {
                $media->delete();
            }
        }

        foreach ($items as $itemData) {
            if (isset($itemData['id'])) {
                $media = $existingMedia->firstWhere('id', (int) $itemData['id']);

                if ($media && isset($itemData['order'])) {
                    $media->update(['order_column' => (int) $itemData['order']]);
                }

                continue;
            }

            if (! isset($itemData['file']) || ! $itemData['file'] instanceof UploadedFile) {
                continue;
            }

            /** @var UploadedFile $file */
            $file = $itemData['file'];
            $suffix = $itemData['order'] ?? null;

            $adder = $this->addUploadedMedia($file, $collection, $suffix);

            if (isset($itemData['name'])) {
                $adder->usingName($itemData['name']);
            }

            if (isset($itemData['order'])) {
                $adder->setOrder((int) $itemData['order']);
            }

            $adder->toMediaCollection($collection);
        }
    }
}
