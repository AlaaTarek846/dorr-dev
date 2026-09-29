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

    /**
     * How long a view-once file is kept after the last person opened it, so a phone that is still
     * downloading finishes before the bytes go.
     */
    private const VIEW_ONCE_GRACE_MINUTES = 15;

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

        $viewOnce = $this->purgeOpenedViewOnce();

        $stories = app(StoryService::class)->purgeExpired();

        $this->info("{$expired} disappeared, {$wiped} wiped, {$viewOnce} view-once files deleted, {$pins} pins expired, {$stories} stories expired.");

        return self::SUCCESS;
    }

    /**
     * A view-once file leaves the server once everyone it was sent to has opened it, and the grace
     * period is up. The message itself stays — it is part of the conversation.
     */
    private function purgeOpenedViewOnce(): int
    {
        $cutoff = now()->subMinutes(self::VIEW_ONCE_GRACE_MINUTES);
        $purged = 0;

        ChatMessageUserState::query()
            ->whereNotNull('opened_at')->where('opened_at', '<=', $cutoff)
            ->with('message')
            ->chunkById(200, function ($states) use (&$purged) {
                foreach ($states as $state) {
                    $message = $state->message;

                    if ($message === null || ! $message->view_once || ! $this->everyoneOpened($message)) {
                        continue;
                    }

                    $message->clearMediaCollection(ChatMessage::ATTACHMENTS);
                    $message->clearMediaCollection(ChatMessage::THUMBNAIL);
                    $purged++;
                }
            });

        return $purged;
    }

    /**
     * True when every other member still in the conversation has opened it; a member who left
     * before opening cannot hold the file hostage.
     */
    private function everyoneOpened(ChatMessage $message): bool
    {
        $recipients = $message->conversation->participants()
            ->whereNull('left_at')
            ->where(fn ($q) => $q->where('id', '!=', $message->conversation->participants()
                ->where('participant_type', $message->sender_type)
                ->where('participant_id', $message->sender_id)
                ->value('id')))
            ->pluck('id');

        if ($recipients->isEmpty()) {
            return false;
        }

        $opened = ChatMessageUserState::query()
            ->where('message_id', $message->id)
            ->whereIn('participant_id', $recipients)
            ->whereNotNull('opened_at')
            ->distinct()
            ->pluck('participant_id');

        return $opened->count() === $recipients->count();
    }
}
