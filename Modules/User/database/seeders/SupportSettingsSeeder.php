<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\SupportSetting;

/**
 * The single support-settings row: automatic replies on, Sunday–Thursday 09:00–18:00 (Asia/Riyadh),
 * the AI allowed to answer twice per ticket. Texts stay empty so the defaults in lang/{locale}/support.php apply.
 */
class SupportSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (SupportSetting::query()->exists()) {
            return;
        }

        SupportSetting::query()->create([
            'auto_reply_enabled' => true,
            'ack_enabled' => true,
            'ack_message' => ['ar' => '', 'en' => ''],
            'away_enabled' => true,
            'away_message' => ['ar' => '', 'en' => ''],
            'hours' => SupportSetting::defaultHours(),
            'timezone' => 'Asia/Riyadh',
            'away_every_hours' => 6,
            'ai_enabled' => true,
            'ai_max_replies' => 2,
        ]);
    }
}
