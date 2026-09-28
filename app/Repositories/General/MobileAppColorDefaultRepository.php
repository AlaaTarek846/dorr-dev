<?php

namespace App\Repositories\General;

use App\Models\MobileAppColorDefault;
use App\Repositories\BaseRepository;
use App\Support\Mobile\MobileColorTokens;

class MobileAppColorDefaultRepository extends BaseRepository
{
    public function __construct(MobileAppColorDefault $model)
    {
        $this->model = $model;
    }

    public function active(): MobileAppColorDefault
    {
        $record = MobileAppColorDefault::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($record !== null) {
            return $record;
        }

        return MobileAppColorDefault::query()->firstOrCreate(
            ['slug' => 'platform-default'],
            [
                'is_active' => true,
                'light_tokens' => MobileColorTokens::seededLightTokens(),
                'dark_tokens' => MobileColorTokens::seededDarkTokens(),
                'light_gradients' => null,
                'dark_gradients' => null,
            ],
        );
    }
}
