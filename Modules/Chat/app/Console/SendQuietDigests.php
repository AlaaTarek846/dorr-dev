<?php

namespace Modules\Chat\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Services\ChatPushNotifier;

/**
 * Smart quiet (spec 115): once someone's quiet time is over, one notification sums up what came in
 * meanwhile. Scheduled every minute.
 */
class SendQuietDigests extends Command
{
    protected $signature = 'chat:quiet-digest';

    protected $description = 'Send the "while it was quiet" summary to people whose quiet time just ended';

    public function handle(ChatPushNotifier $push): int
    {
        $sent = 0;

        DB::table('chat_quiet_digests')->orderBy('id')->chunkById(200, function ($rows) use ($push, &$sent) {
            $settings = ChatPrivacySetting::query()
                ->where(fn ($q) => $rows->each(fn ($r) => $q->orWhere(fn ($q) => $q->where('owner_type', $r->owner_type)->where('owner_id', $r->owner_id))))
                ->get()->keyBy(fn ($s) => $s->owner_type.':'.$s->owner_id);

            foreach ($rows as $row) {
                $setting = $settings->get($row->owner_type.':'.$row->owner_id);
                if ($setting?->quietOn()) {
                    continue; // still quiet
                }

                $chats = count(json_decode((string) $row->conversation_ids, true) ?: []);
                if ($row->messages_count > 0) {
                    $push->quietDigest($row->owner_type, (int) $row->owner_id, (int) $row->messages_count, $chats);
                    $sent++;
                }
                DB::table('chat_quiet_digests')->where('id', $row->id)->delete();
            }
        });

        $this->info("Sent {$sent} quiet-time summaries.");

        return self::SUCCESS;
    }
}
