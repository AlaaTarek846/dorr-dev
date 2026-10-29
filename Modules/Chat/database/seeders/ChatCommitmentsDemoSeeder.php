<?php

namespace Modules\Chat\Database\Seeders;

use App\Enums\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatCommitment;
use Modules\Chat\Models\ChatHandoff;
use Modules\Chat\Services\ContactService;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\LifeService;
use Modules\Chat\Services\MessageService;
use Modules\User\Models\User;

/**
 * Local demo: open commitments and handoffs for «التزاماتي».
 *
 *   php artisan db:seed --class="Modules\\Chat\\Database\\Seeders\\ChatCommitmentsDemoSeeder"
 *
 * Targets the last mobile-app login (Sanctum `mobile-app`). Re-run skips rows that already
 * exist on the same source messages.
 */
class ChatCommitmentsDemoSeeder extends Seeder
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
        $messages = app(MessageService::class);
        $life = app(LifeService::class);

        $people = [
            ['سارة علي', '501222001'],
            ['عمر حسن', '501222002'],
            ['لينا محمود', '501222003'],
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
            $chat = $mine->conversation;
            $other->refresh();
            $chats[] = [
                'other' => $other,
                'chat' => $chat,
                'viewerP' => $mine,
                'otherP' => $conversations->participantOf($other, $chat),
            ];
        }

        [$sara, $omar, $lina] = $chats;
        $made = 0;

        $made += $this->commitment($life, $messages, $sara, $viewer, fromOther: true, title: 'ابعت عقد الإيجار', due: now()->addDay()->setTime(18, 0), accept: false, n: 1);
        $made += $this->commitment($life, $messages, $omar, $viewer, fromOther: true, title: 'احجز قاعة الاجتماع', due: now()->addHours(4), accept: true, n: 1);
        $made += $this->commitment($life, $messages, $sara, $viewer, fromOther: false, title: 'تجهزي العرض للعميل', due: now()->addDays(2)->setTime(12, 0), accept: false, n: 2);
        $made += $this->commitment($life, $messages, $omar, $viewer, fromOther: false, title: 'أكد الحجز', due: now()->addDay()->setTime(9, 0), accept: true, n: 2);

        $made += $this->handoff($life, $messages, $sara, $viewer, toViewer: true, note: 'راجع العرض قبل ما يتبعت', accept: true, n: 3);
        $made += $this->handoff($life, $messages, $lina, $viewer, toViewer: true, note: 'تسليم فاتورة المورد', accept: false, n: 1);
        $made += $this->handoff($life, $messages, $lina, $viewer, toViewer: false, note: 'سلّمي الملفات للمحامي', accept: false, n: 2);

        for ($i = 1; $i <= 16; $i++) {
            $made += $this->commitment($life, $messages, $sara, $viewer, fromOther: true, title: "التزام تجريبي {$i}", due: now()->addDays($i)->setTime(10, 0), accept: $i % 2 === 0, n: 20 + $i);
            $made += $this->commitment($life, $messages, $omar, $viewer, fromOther: false, title: "مستني تجريبي {$i}", due: now()->addDays($i)->setTime(16, 0), accept: $i % 3 === 0, n: 40 + $i);
        }

        $this->command?->info("Seeded {$made} open life items for {$viewer->phone} (user {$viewer->id}).");
    }

    /**
     * @param  array{other: User, chat: \Modules\Chat\Models\ChatConversation, viewerP: \Modules\Chat\Models\ChatParticipant, otherP: \Modules\Chat\Models\ChatParticipant}  $pair
     */
    private function commitment(LifeService $life, MessageService $messages, array $pair, User $viewer, bool $fromOther, string $title, \DateTimeInterface $due, bool $accept, int $n): int
    {
        $author = $fromOther ? $pair['other'] : $viewer;
        $source = $messages->send($author, $pair['chat'], [
            'type' => 'text',
            'body' => $title,
            'uuid' => sprintf('lfdemo-c-%d-%d-%d', $viewer->id, $pair['other']->id, $n),
        ]);
        if (ChatCommitment::query()->where('source_message_id', $source->id)->exists()) {
            return 0;
        }
        $c = $life->commit($author, $pair['chat'], $source, [
            'title' => $title,
            'due_at' => $due->format(DATE_ATOM),
            'remind_minutes' => 60,
        ]);
        if ($accept && $c->status === 'proposed') {
            $life->respondCommitment($fromOther ? $viewer : $pair['other'], $c, true);
        }

        return 1;
    }

    /**
     * @param  array{other: User, chat: \Modules\Chat\Models\ChatConversation, viewerP: \Modules\Chat\Models\ChatParticipant, otherP: \Modules\Chat\Models\ChatParticipant}  $pair
     */
    private function handoff(LifeService $life, MessageService $messages, array $pair, User $viewer, bool $toViewer, string $note, bool $accept, int $n): int
    {
        $from = $toViewer ? $pair['other'] : $viewer;
        $toId = $toViewer ? $pair['viewerP']->id : $pair['otherP']->id;
        $source = $messages->send($from, $pair['chat'], [
            'type' => 'text',
            'body' => $note,
            'uuid' => sprintf('lfdemo-h-%d-%d-%d', $viewer->id, $pair['other']->id, $n),
        ]);
        if (ChatHandoff::query()->where('source_message_id', $source->id)->exists()) {
            return 0;
        }
        $h = $life->handOff($from, $source, $toId, null, $note);
        if ($accept) {
            $life->moveHandoff($toViewer ? $viewer : $pair['other'], $h, 'accepted');
        }

        return 1;
    }
}
