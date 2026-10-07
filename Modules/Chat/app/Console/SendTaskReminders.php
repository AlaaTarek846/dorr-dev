<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Models\ChatTask;
use Modules\Chat\Services\ChatPushNotifier;

/**
 * My tasks (spec 38): a task whose time came notifies me once ("✅ Call the plumber"). Not when it
 * is done already. Scheduled every minute.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'chat:task-reminders';

    protected $description = 'Notify people of their tasks whose due time has come';

    public function handle(ChatPushNotifier $push): int
    {
        $sent = 0;
        ChatTask::query()->whereNull('done_at')->whereNull('reminded_at')->whereNotNull('due_at')->where('due_at', '<=', now())
            ->orderBy('id')->chunkById(200, function ($tasks) use ($push, &$sent) {
                foreach ($tasks as $task) {
                    $task->forceFill(['reminded_at' => now()])->save();
                    $push->taskDue($task);
                    $sent++;
                }
            });

        $this->info($sent.' task reminder(s) sent.');

        return self::SUCCESS;
    }
}
