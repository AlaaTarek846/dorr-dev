<?php

namespace App\Services\General;

use App\Http\Resources\General\MobileAppFontResource;
use App\Models\MobileAppFont;
use App\Repositories\General\MobileAppFontRepository;
use App\Services\CatalogService;
use App\Support\Mobile\MobileFontWeightGuesser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MobileAppFontService extends CatalogService
{
    protected ?string $resource = MobileAppFontResource::class;

    public function __construct(MobileAppFontRepository $repository)
    {
        parent::__construct($repository);
    }

    protected function beforeStore(array $data): array
    {
        return $this->preparePayload($data);
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        return $this->preparePayload($data);
    }

    protected function afterStore(Model $model, array $data): void
    {
        /** @var MobileAppFont $model */
        $this->syncDefaultFont($model, $data);
        $this->syncFontFiles($model, $data);
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        /** @var MobileAppFont $model */
        $this->syncDefaultFont($model, $data);
        $this->syncFontFiles($model, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        if (array_key_exists('status', $data)) {
            $data['status'] = filter_var($data['status'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('is_default', $data)) {
            $data['is_default'] = filter_var($data['is_default'], FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncDefaultFont(MobileAppFont $font, array $data): void
    {
        if (empty($data['is_default'])) {
            return;
        }

        MobileAppFont::query()
            ->where('id', '!=', $font->id)
            ->update(['is_default' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncFontFiles(MobileAppFont $font, array $data): void
    {
        if (! empty($data['remove_font_file_ids']) && is_array($data['remove_font_file_ids'])) {
            $font->getMedia(MobileAppFont::FONT_FILES_COLLECTION)
                ->whereIn('id', $data['remove_font_file_ids'])
                ->each(fn (Media $media) => $media->delete());
        }

        if (empty($data['font_files']) || ! is_array($data['font_files'])) {
            return;
        }

        foreach ($data['font_files'] as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $weight = MobileFontWeightGuesser::fromFileName($file->getClientOriginalName());

            $font->addMedia($file)
                ->usingFileName($file->getClientOriginalName())
                ->withCustomProperties(['weight' => $weight])
                ->toMediaCollection(MobileAppFont::FONT_FILES_COLLECTION);
        }
    }
}
