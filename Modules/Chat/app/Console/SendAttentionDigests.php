<?php

namespace Modules\Chat\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Services\ChatPushNotifier;

/**
 * The attention summary (spec 146): what I chose to get "in the summary" comes as one
 * notification every `digest_minutes` (from the first one waiting) — never in my quiet time.
 * Scheduled every minute.
 */
class SendAttentionDigests extends Command
{
    protected $signature = 'chat:attention-digest';

    protected $description = 'Send the attention summaries that are due';

    public function handle(ChatPushNotifier $push): int
    {
        $sent = 0;
        DB::table('chat_attention_digests')->orderBy('id')->chunkById(200, function ($rows) use ($push, &$sent) {
            $settings = ChatPrivacySetting::query()
                ->where(fn ($q) => $rows->each(fn ($r) => $q->orWhere(fn ($q) => $q->where('owner_type', $r->owner_type)->where('owner_id', $r->owner_id))))
                ->get()->keyBy(fn ($s) => $s->owner_type.':'.$s->owner_id);
            foreach ($rows as $row) {
                $s = $settings->get($row->owner_type.':'.$row->owner_id);
                $every = max(15, (int) ($s?->digest_minutes ?: 60));
                if ($row->first_at === null || Carbon::parse($row->first_at)->addMinutes($every)->isFuture() || $s?->quietOn()) {
                    continue;
                }
                // Claimed first: never twice.
                if (DB::table('chat_attention_digests')->where('id', $row->id)->delete() === 0) {
                    continue;
                }
                if ($row->messages_count > 0) {
                    $push->attentionDigest($row->owner_type, (int) $row->owner_id, (int) $row->messages_count, count(json_decode((string) $row->conversation_ids, true) ?: []));
                    $sent++;
                }
            }
        });
        $this->info("Sent {$sent} attention summaries.");

        return self::SUCCESS;
    }
}
