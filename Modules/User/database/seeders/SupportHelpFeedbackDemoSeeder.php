<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\SupportHelpFeedback;
use Modules\User\Models\SupportHelpNode;
use Modules\User\Models\User;

/**
 * Demo numbers for the quick-chat page of the dashboard (solved / asked for an agent per topic), spread over
 * the last weeks. Outside production only, and only while no answer has been recorded, so real data is never mixed in.
 */
class SupportHelpFeedbackDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (SupportHelpFeedback::query()->exists()) {
            return;
        }

        $user = User::query()->where('email', 'user@example.com')->first();
        // A topic that ends a path is the only place a customer can press the two buttons.
        $topics = SupportHelpNode::query()->doesntHave('children')->orderBy('id')->pluck('id');

        foreach ($topics as $index => $topicId) {
            // Deterministic, so every environment shows the same story: most topics solve most questions.
            $total = 6 + (($index * 7) % 15);
            $agentShare = 0.15 + (($index * 13) % 40) / 100;

            for ($i = 0; $i < $total; $i++) {
                $feedback = SupportHelpFeedback::query()->create([
                    'support_help_node_id' => $topicId,
                    'user_id' => $user?->id,
                    'solved' => ($i / $total) >= $agentShare,
                ]);

                $moment = now()->subHours((($index * 31) + ($i * 17)) % (24 * 21));
                $feedback->forceFill(['created_at' => $moment, 'updated_at' => $moment])->save();
            }
        }
    }
}
