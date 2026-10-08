<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Support: the automatic-reply settings, the agents' quick replies, the help FAQs the AI answers from, the app's guided help menu,
 * and (outside production) a few demo tickets that show every kind of automatic reply.
 *
 * Every seeder only adds what is missing, so re-running never overwrites what the admin changed.
 */
class SupportDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SupportSettingsSeeder::class,
            SupportQuickReplySeeder::class,
            SupportHelpFaqSeeder::class,
            SupportHelpNodeSeeder::class,
        ]);

        if (! app()->environment('production')) {
            $this->call([SupportTicketDemoSeeder::class, SupportHelpFeedbackDemoSeeder::class]);
        }
    }
}
