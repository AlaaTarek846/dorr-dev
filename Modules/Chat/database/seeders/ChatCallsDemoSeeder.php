<?php

namespace Modules\Chat\Database\Seeders;

use App\Enums\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\CallParticipantStatus;
use Modules\Chat\Enums\CallStatus;
use Modules\Chat\Enums\CallType;
use Modules\Chat\Models\ChatCall;
use Modules\Chat\Models\ChatCallLink;
use Modules\Chat\Models\ChatCallParticipant;
use Modules\Chat\Models\ChatCallRecording;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\ContactService;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\GroupService;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;

/**
 * Local demo: the Calls screen (log rows of every kind, plus two call links).
 *
 *   php artisan db:seed --class="Modules\\Chat\\Database\\Seeders\\ChatCallsDemoSeeder"
 *
 * Targets the last mobile-app login. Re-run skips the same room names / tokens.
 */
class ChatCallsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tokenUserId = DB::table('personal_access_tokens')
            ->where('tokenable_type', 'user')
            ->where('name', 'mobile-app')
            ->orderByDesc('last_used_at')
            ->value('tokenable_id');
        $viewer = $tokenUserId ? User::query()->find($tokenUserId) : null;
        $viewer ??= User::query()->where('phone', '+201113667448')->first()
            ?? User::query()->where('phone', '+201277738694')->first();
        if ($viewer === null) {
            $this->command?->error('No viewer user (last mobile token / +201113667448).');

            return;
        }

        $countryId = $viewer->country_id;
        $contacts = app(ContactService::class);
        $conversations = app(ConversationService::class);

        $people = [
            ['سارة علي', '501333001'],
            ['عمر حسن', '501333002'],
            ['لينا محمود', '501333003'],
        ];
        $chats = [];
        foreach ($people as [$name, $local]) {
            $phone = '+966'.$local;
            $other = User::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $name,
                    'country_id' => $countryId,
                    'status' => UserStatus::Active,
                    'phone_verified_at' => now(),
                ]
            );
            if (blank($other->name)) {
                $other->update(['name' => $name]);
            }
            $contacts->add($viewer, $name, $phone, $viewer->country);
            $contacts->add($other, $viewer->name ?: 'أنا', $viewer->phone, $other->country ?? $viewer->country);
            $mine = $conversations->openDirect($viewer, $other);
            $chats[] = ['other' => $other, 'chat' => $mine->conversation];
        }
        [$sara, $omar, $lina] = $chats;

        $group = app(GroupService::class)->create($viewer, 'فريق المبيعات', null, [$omar['other']]);
        $groupChat = $group['participant']->conversation;

        $made = 0;
        for ($i = 1; $i <= 10; $i++) {
            $made += $this->addCall($viewer, $sara['chat'], $sara['other'], 'fill-'.$i, CallType::Audio, CallStatus::Ended, outgoing: $i % 2 === 0, hoursAgo: 40 + $i, duration: 45 + $i);
        }
        $made += $this->addCall($viewer, $sara['chat'], $sara['other'], 'out-audio', CallType::Audio, CallStatus::Ended, outgoing: true, hoursAgo: 2, duration: 186);
        $made += $this->addCall($viewer, $omar['chat'], $omar['other'], 'in-audio', CallType::Audio, CallStatus::Ended, outgoing: false, hoursAgo: 5, duration: 94);
        $made += $this->addCall($viewer, $lina['chat'], $lina['other'], 'missed', CallType::Audio, CallStatus::Missed, outgoing: false, hoursAgo: 8);
        $made += $this->addCall($viewer, $sara['chat'], $sara['other'], 'cancelled', CallType::Audio, CallStatus::Cancelled, outgoing: true, hoursAgo: 12);
        $made += $this->addCall($viewer, $omar['chat'], $omar['other'], 'declined', CallType::Video, CallStatus::Declined, outgoing: false, hoursAgo: 16);
        $made += $this->addCall($viewer, $lina['chat'], $lina['other'], 'out-video', CallType::Video, CallStatus::Ended, outgoing: true, hoursAgo: 20, duration: 312);
        $made += $this->addCall($viewer, $groupChat, $omar['other'], 'group', CallType::Video, CallStatus::Ended, outgoing: true, hoursAgo: 28, duration: 640, group: true);
        $made += $this->addCall($viewer, $lina['chat'], $lina['other'], 'silenced', CallType::Audio, CallStatus::Missed, outgoing: false, hoursAgo: 30, silenced: true);
        $made += $this->addCall($viewer, $sara['chat'], $sara['other'], 'recorded', CallType::Audio, CallStatus::Ended, outgoing: true, hoursAgo: 1, duration: 420, recorded: true);

        $made += $this->link($viewer, CallType::Audio);
        $made += $this->link($viewer, CallType::Video);

        $this->command?->info("Seeded {$made} call-log rows/links for {$viewer->phone} (user {$viewer->id}).");
    }

    private function addCall(
        User $viewer,
        \Modules\Chat\Models\ChatConversation $chat,
        User $other,
        string $key,
        CallType $type,
        CallStatus $status,
        bool $outgoing,
        int $hoursAgo,
        ?int $duration = null,
        bool $silenced = false,
        bool $recorded = false,
        bool $group = false,
    ): int {
        $room = sprintf('calldemo-%d-%s', $viewer->id, $key);
        if (ChatCall::query()->where('room_name', $room)->exists()) {
            return 0;
        }

        $mine = ChatParticipant::query()->of($viewer)->where('conversation_id', $chat->id)->first();
        $peer = ChatParticipant::query()->of($other)->where('conversation_id', $chat->id)->first();
        if ($mine === null || $peer === null) {
            return 0;
        }

        $at = now()->subHours($hoursAgo);
        $answered = $duration !== null ? $at->copy() : null;
        $ended = $duration !== null ? $at->copy()->addSeconds($duration) : $at->copy()->addSeconds(20);
        $initiator = $outgoing ? $mine : $peer;

        $call = ChatCall::query()->create([
            'conversation_id' => $chat->id,
            'type' => $type,
            'status' => $status,
            'initiator_participant_id' => $initiator->id,
            'room_name' => $room,
            'answered_at' => $answered,
            'ended_at' => $ended,
        ]);
        $call->forceFill(['created_at' => $at, 'updated_at' => $ended])->save();

        $this->row($call, $mine, $outgoing, $status, $silenced, $answered, $ended);
        $this->row($call, $peer, ! $outgoing, $status, false, $answered, $ended);
        if ($group) {
            // The group already has the two people; no extra row is required for the layout.
        }

        if ($recorded) {
            $recording = ChatCallRecording::query()->create([
                'call_id' => $call->id,
                'conversation_id' => $chat->id,
                'requested_by_participant_id' => $mine->id,
                'status' => 'ready',
                'file_path' => 'demo/'.$room.'.mp4',
                'size_bytes' => 1_240_000,
                'duration_seconds' => $duration,
                'started_at' => $answered,
                'stopped_at' => $ended,
                'expires_at' => now()->addDays(30),
            ]);
            DB::table('chat_call_recording_consents')->insert([
                'recording_id' => $recording->id,
                'participant_id' => $mine->id,
                'decision' => 'accepted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return 1;
    }

    private function row(
        ChatCall $call,
        ChatParticipant $who,
        bool $wasInitiator,
        CallStatus $status,
        bool $silenced,
        $answered,
        $ended,
    ): void {
        $part = match ($status) {
            CallStatus::Ended => $wasInitiator || $answered !== null ? CallParticipantStatus::Left : CallParticipantStatus::Missed,
            CallStatus::Missed => $wasInitiator ? CallParticipantStatus::Left : CallParticipantStatus::Missed,
            CallStatus::Declined => $wasInitiator ? CallParticipantStatus::Left : CallParticipantStatus::Declined,
            CallStatus::Cancelled => $wasInitiator ? CallParticipantStatus::Left : CallParticipantStatus::Missed,
            default => CallParticipantStatus::Left,
        };
        ChatCallParticipant::query()->create([
            'call_id' => $call->id,
            'participant_id' => $who->id,
            'status' => $part,
            'joined_at' => $answered,
            'left_at' => $ended,
            'silenced' => $silenced,
        ]);
    }

    private function link(User $viewer, CallType $type): int
    {
        $token = 'calldemo'.$viewer->id.$type->value;
        if (ChatCallLink::query()->where('token', $token)->exists()) {
            return 0;
        }
        ChatCallLink::query()->create([
            'token' => $token,
            'owner_type' => ParticipantType::aliasFor($viewer),
            'owner_id' => $viewer->id,
            'type' => $type,
            'expires_at' => now()->addDays(30),
        ]);

        return 1;
    }
}
