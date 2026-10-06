<?php

namespace Modules\Chat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Chat\Models\ChatReportType;
use Modules\Chat\Models\ChatTheme;
use Modules\Chat\Services\ChatThemeService;

/**
 * Starting report reasons and a few colour themes (no wallpaper images — the admin adds those).
 * Only seeds an empty table, so it never overwrites what the admin changed.
 */
class ChatDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The occasions catalog (DORR Moments).
        $this->call(ChatMomentsSeeder::class);

        if (! ChatReportType::query()->exists()) {
            $reasons = [
                ['Spam or advertising', 'رسايل مزعجة أو إعلانات'],
                ['Harassment or bullying', 'مضايقة أو تنمّر'],
                ['Fraud or scam', 'نصب أو احتيال'],
                ['Inappropriate content', 'محتوى غير لائق'],
                ['Impersonation', 'انتحال شخصية'],
                ['Other', 'سبب تاني'],
            ];

            foreach ($reasons as $i => [$en, $ar]) {
                $type = ChatReportType::query()->create(['sort_order' => $i]);
                $type->translations()->createMany([['locale' => 'en', 'name' => $en], ['locale' => 'ar', 'name' => $ar]]);
            }
        }

        if (! ChatTheme::query()->exists()) {
            $themes = [
                ['Classic', 'كلاسيك', '#EFEAE2', '#DCF8C6', '#FFFFFF', false, false],
                ['Ocean', 'محيط', '#E0F2FE', '#BAE6FD', '#FFFFFF', false, false],
                ['Blossom', 'زهور', '#FCE7F3', '#FBCFE8', '#FFFFFF', false, false],
                ['Sand', 'رملي', '#FEF3C7', '#FDE68A', '#FFFBEB', false, false],
                ['Midnight', 'منتصف الليل', '#0F172A', '#4F46E5', '#1E293B', true, false],
                ['Forest', 'غابة', '#052E16', '#15803D', '#14532D', true, false],
            ];

            foreach ($themes as $i => [$en, $ar, $bg, $sender, $receiver, $dark, $default]) {
                $theme = ChatTheme::query()->create([
                    'background_color' => $bg, 'sender_color' => $sender, 'receiver_color' => $receiver,
                    'is_dark' => $dark, 'is_default' => $default, 'sort_order' => $i,
                ]);
                $theme->translations()->createMany([['locale' => 'en', 'name' => $en], ['locale' => 'ar', 'name' => $ar]]);
            }

            app(ChatThemeService::class)->flush();
        }
    }
}
