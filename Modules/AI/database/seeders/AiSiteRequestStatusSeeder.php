<?php

namespace Modules\AI\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AI\Models\AiSiteRequestStatus;
use Modules\AI\Models\AiSiteServiceSetting;

/** The workflow statuses of human-assisted site requests (labels/colours stay editable from the dashboard) and the on-switch. */
class AiSiteRequestStatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AiSiteRequestStatus::DEFAULTS as $i => [$code, $ar, $en, $color]) {
            AiSiteRequestStatus::query()->firstOrCreate(
                ['code' => $code],
                ['name_ar' => $ar, 'name_en' => $en, 'color' => $color, 'sort_order' => ($i + 1) * 10, 'is_active' => true, 'is_system' => true],
            );
        }

        AiSiteServiceSetting::current();
    }
}
