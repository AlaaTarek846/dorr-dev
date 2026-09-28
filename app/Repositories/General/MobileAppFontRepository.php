<?php

namespace App\Repositories\General;

use App\Models\MobileAppFont;
use App\Repositories\TranslatableRepository;

class MobileAppFontRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'media'];

    protected array $deleteBlockRelations = ['userAppearances'];

    public function __construct(MobileAppFont $model)
    {
        $this->model = $model;
    }

    /**
     * @return list<string>
     */
    protected function reservedPayloadKeys(): array
    {
        return array_merge(parent::reservedPayloadKeys(), [
            'font_files',
            'remove_font_file_ids',
        ]);
    }

    public function defaultFont(): ?MobileAppFont
    {
        return MobileAppFont::query()
            ->where('is_default', true)
            ->where('status', true)
            ->first();
    }
}
