<?php

namespace Modules\Chat\Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatTask;
use Modules\Chat\Services\CalendarService;
use Modules\Chat\Services\ContactService;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Services\TaskService;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;

/**
 * Local demo: reply, pay, and appointments in «صندوق الحياة».
 *
 *   php artisan db:seed --class="Modules\\Chat\\Database\\Seeders\\ChatLifeInboxDemoSeeder"
 *
 * Targets the last mobile-app login. Message uuids and calendar dedupe make re-run safe.
 */
class ChatLifeInboxDemoSeeder extends Seeder
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

        $country = $viewer->country
            ?? Country::query()->find($viewer->country_id)
            ?? Country::query()->where('is_default', true)->first()
            ?? Country::query()->where('code', 'SA')->first();
        if ($country === null) {
            $this->command?->error('No country to attach to money requests.');

            return;
        }
        $country->loadMissing('currency');
        app()->instance('resolved_country', $country);

        $contacts = app(ContactService::class);
        $conversations = app(ConversationService::class);
        $messages = app(MessageService::class);
        $calendar = app(CalendarService::class);
        $tasks = app(TaskService::class);
        $made = 0;

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
                    'country_id' => $viewer->country_id,
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
            $other->refresh();
            $chats[] = ['other' => $other, 'chat' => $mine->conversation];
        }
        [$sara, $omar, $lina] = $chats;

        foreach ([
            [$sara, 1, 'تقدري تكلمي المالك النهاردة؟ الإيجار اتأخر.'],
            [$omar, 1, 'المدرسة سألت على اجتماع ولي الأمر، تردي؟'],
            [$lina, 1, 'العقد بينتهي بكرة، وقّعتي النسخة الأخيرة؟'],
        ] as [$pair, $n, $body]) {
            $msg = $messages->send($pair['other'], $pair['chat'], [
                'type' => 'text',
                'body' => $body,
                'uuid' => sprintf('lfdemo-in-r-%d-%d-%d', $viewer->id, $pair['other']->id, $n),
            ]);
            $messages->followUp($viewer, $msg, true);
            $made++;
        }

        foreach ([
            [$sara, 1, 450000, 'الإيجار — 4500'],
            [$omar, 1, 1280000, 'تحويل المورد — 12,800'],
            [$lina, 1, 89000, 'فاتورة الكهربا — 890'],
        ] as [$pair, $n, $amount, $body]) {
            $messages->send($pair['other'], $pair['chat'], [
                'type' => 'money_request',
                'amount_minor' => $amount,
                'body' => $body,
                'uuid' => sprintf('lfdemo-in-p-%d-%d-%d', $viewer->id, $pair['other']->id, $n),
            ]);
            $made++;
        }

        $zone = $viewer->timezone ?: config('app.timezone', 'UTC');
        foreach ([
            ['كشف أسنان', now()->setTime(17, 0)->toIso8601String(), 'عيادة الملك'],
            ['اجتماع الفريق', now()->addDay()->setTime(10, 30)->toIso8601String(), 'القاعة 3'],
        ] as [$title, $at, $location]) {
            $calendar->create($viewer, [
                'title' => $title,
                'starts_at' => $at,
                'timezone' => $zone,
                'location' => $location,
                'category' => 'appointment',
            ]);
            $made++;
        }
        $calendar->create($viewer, [
            'title' => 'رحلة قصيرة',
            'all_day' => true,
            'date' => now()->addDay()->toDateString(),
            'timezone' => $zone,
            'category' => 'trip',
        ]);
        $made++;

        $type = ParticipantType::aliasFor($viewer);
        foreach ([
            ['ابعت العقد للمحامي', now()->addHours(3)->toIso8601String()],
            ['جددي حجز القاعة', now()->subHours(2)->toIso8601String()],
        ] as [$text, $due]) {
            $exists = ChatTask::query()->where('owner_type', $type)->where('owner_id', $viewer->id)->where('text', $text)->whereNull('done_at')->exists();
            if (! $exists) {
                $tasks->create($viewer, [['text' => $text, 'due_at' => $due]], null);
            }
            $made++;
        }

        $this->command?->info("Seeded life-inbox demo items for {$viewer->phone} (user {$viewer->id}).");
    }
}
