<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageUserState;
use Modules\Chat\Models\ChatPinnedMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\StoryService;

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
                    $message->clearMediaCollection(ChatMessage::THUMBNAIL);
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
                    $message->clearMediaCollection(ChatMessage::THUMBNAIL);
                    $message->forceFill(['body' => null, 'meta' => null])->save();
                    $wiped++;
                }
            });

        $pins = ChatPinnedMessage::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();

        $stories = app(StoryService::class)->purgeExpired();

        // View-once files: gone once everyone else opened them (10 minutes of grace to finish
        // watching), and after 14 days in any case — like WhatsApp.
        $viewOnce = 0;
        ChatMessage::query()->where('view_once', true)->where('created_at', '<=', now()->subMinutes(10))
            ->whereHas('media')
            ->with('conversation')
            ->chunkById(200, function ($messages) use (&$viewOnce) {
                foreach ($messages as $message) {
                    $others = $message->conversation?->activeParticipants()
                        ->where(fn ($q) => $q->where('participant_type', '!=', $message->sender_type)->orWhere('participant_id', '!=', $message->sender_id))
                        ->pluck('id') ?? collect();
                    $opened = ChatMessageUserState::query()->where('message_id', $message->id)->whereIn('participant_id', $others)
                        ->whereNotNull('opened_at')->where('opened_at', '<=', now()->subMinutes(10))->count();

                    if ($message->created_at->lte(now()->subDays(14)) || ($others->isNotEmpty() && $opened >= $others->count())) {
                        $message->clearMediaCollection(ChatMessage::ATTACHMENTS);
                        $message->clearMediaCollection(ChatMessage::THUMBNAIL);
                        $viewOnce++;
                    }
                }
            });

        $this->info("{$expired} disappeared, {$wiped} wiped, {$pins} pins expired, {$stories} stories expired, {$viewOnce} view-once cleared.");

        return self::SUCCESS;
    }
}
