<?php

namespace App\Repositories\General;

use App\Models\PlatformSetting;
use App\Repositories\BaseRepository;

class PlatformSettingRepository extends BaseRepository
{
    public function __construct(PlatformSetting $model)
    {
        $this->model = $model;
    }

    public function instance(): PlatformSetting
    {
        return $this->model->newQuery()->firstOrCreate(
            ['id' => 1],
            ['app_name' => (string) config('app.name', 'Dorr')],
        );
    }
}
