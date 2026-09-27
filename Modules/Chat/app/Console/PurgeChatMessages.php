<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatPinnedMessage;
use Modules\Chat\Models\ChatSetting;

/**
 * Makes "gone" really gone on the server:
 *  - disappearing messages whose time is up are deleted (with their files);
 *  - messages deleted for everyone keep their content hidden for `deleted_message_retention_days`
 *    (so a report can still be reviewed), then the text, data and files are wiped for good;
 *  - expired pins are removed.
 */
class PurgeChatMessages extends Command
{
    protected $signature = 'chat:purge';

    protected $description = 'Delete disappeared messages and wipe the content of long-deleted ones.';

    public function handle(): int
    {
        $expired = 0;
        ChatMessage::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->chunkById(200, function ($messages) use (&$expired) {
                foreach ($messages as $message) {
                    $message->clearMediaCollection(ChatMessage::ATTACHMENTS);
                    $message->delete();
                    $expired++;
                }
            });

        $cutoff = now()->subDays(ChatSetting::current()->deleted_message_retention_days);
        $wiped = 0;
        ChatMessage::query()->whereNotNull('deleted_for_everyone_at')->where('deleted_for_everyone_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('body')->orWhereNotNull('meta'))
            ->chunkById(200, function ($messages) use (&$wiped) {
                foreach ($messages as $message) {
                    $message->clearMediaCollection(ChatMessage::ATTACHMENTS);
                    $message->forceFill(['body' => null, 'meta' => null])->save();
                    $wiped++;
                }
            });

        $pins = ChatPinnedMessage::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();

        $this->info("{$expired} disappeared, {$wiped} wiped, {$pins} pins expired.");

        return self::SUCCESS;
    }
}
