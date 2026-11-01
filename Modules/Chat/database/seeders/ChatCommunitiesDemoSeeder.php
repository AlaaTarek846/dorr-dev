<?php

namespace Modules\Chat\Database\Seeders;

use App\Enums\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Models\ChatCommunity;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Models\ChatGroupJoinRequest;
use Modules\Chat\Services\CommunityService;
use Modules\Chat\Services\ContactService;
use Modules\Chat\Services\GroupService;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;

/**
 * Local demo: the Communities list (every row kind, enough rows for pages of 15).
 *
 *   php artisan db:seed --class="Modules\\Chat\\Database\\Seeders\\ChatCommunitiesDemoSeeder"
 *
 * Targets the last mobile-app login. Re-run skips names that already exist.
 */
class ChatCommunitiesDemoSeeder extends Seeder
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
        $groups = app(GroupService::class);
        $communities = app(CommunityService::class);

        $sara = $this->person('سارة علي', '501444001', $countryId);
        $omar = $this->person('عمر حسن', '501444002', $countryId);
        $lina = $this->person('لينا محمود', '501444003', $countryId);
        foreach ([$sara, $omar, $lina] as $other) {
            $contacts->add($viewer, $other->name, $other->phone, $viewer->country);
            $contacts->add($other, $viewer->name ?: 'أنا', $viewer->phone, $other->country ?? $viewer->country);
        }

        $made = 0;

        // Creator: description + two groups I'm in.
        $made += $this->mine($communities, $groups, $viewer, 'مدرسة النور', 'مجتمع المدرسة: أولياء الأمور والمعلمين', [
            ['أولياء الأمور', [$sara, $omar]],
            ['المعلمين', [$lina]],
        ]);

        // Creator: long description, one group.
        $made += $this->mine($communities, $groups, $viewer, 'نادي الجيران', 'كل اللي في العمارة: صيانة، اجتماعات، وإعلانات السلم.', [
            ['سكان العمارة', [$sara]],
        ]);

        // Creator: no description, three groups.
        $made += $this->mine($communities, $groups, $viewer, 'فريق المبيعات', null, [
            ['المبيعات', [$omar]],
            ['التوصيل', [$lina]],
            ['الدعم', [$sara]],
        ]);

        // Long name so the list row truncates.
        $made += $this->mine($communities, $groups, $viewer, 'مجتمع بأسماء طويلة جدا جدا للتجربة على سطر القائمة', 'اسم طويل', [
            ['مجموعة واحدة', [$sara]],
        ]);

        // Member only (سارة owns it): leave instead of delete.
        $made += $this->theirs($communities, $groups, $sara, $viewer, 'حيّ الورود', 'مجتمع الحي — أنا عضو مش منشئ', 'جيران الورود');

        // Groups I can hop into / ask to join (95).
        $made += $this->hops($communities, $groups, $viewer, $sara, $omar);

        // Groups I run that are not in a community — «إضافة مجموعات موجودة».
        $loose = $this->looseGroups($groups, $viewer, $sara, $omar, $lina);

        for ($i = 1; $i <= 12; $i++) {
            $made += $this->mine($communities, $groups, $viewer, sprintf('تجريبي مجتمع %02d', $i), $i % 2 === 0 ? 'وصف تجريبي '.$i : null, []);
        }

        $this->command?->info("Communities demo: +{$made} for {$viewer->phone}; +{$loose} free groups.");
    }

    /**
     * @param  list<array{0: string, 1: list<User>}>  $groupSpecs
     */
    private function mine(CommunityService $communities, GroupService $groups, User $viewer, string $name, ?string $description, array $groupSpecs): int
    {
        if ($this->named($name)) {
            return 0;
        }
        $ids = [];
        foreach ($groupSpecs as [$groupName, $members]) {
            $ids[] = $groups->create($viewer, $groupName, null, $members)['participant']->conversation->uuid;
        }
        $communities->create($viewer, $name, $description, $ids);

        return 1;
    }

    private function theirs(CommunityService $communities, GroupService $groups, User $owner, User $viewer, string $name, string $description, string $groupName): int
    {
        if ($this->named($name)) {
            return 0;
        }
        $group = $groups->create($owner, $groupName, null, [$viewer])['participant']->conversation;
        $communities->create($owner, $name, $description, [$group->uuid]);

        return 1;
    }

    /** Groups I own that sit outside a community, so the add-groups sheet has rows. */
    private function looseGroups(GroupService $groups, User $viewer, User $sara, User $omar, User $lina): int
    {
        $n = 0;
        foreach ([
            ['فريق الكرة', [$sara, $omar]],
            ['جمعية الحي', [$lina]],
            ['مشروع التخرج', [$sara]],
        ] as [$name, $members]) {
            if ($this->looseNamed($viewer, $name)) {
                continue;
            }
            $groups->create($viewer, $name, 'جروب برّه مجتمع', $members);
            $n++;
        }

        return $n;
    }

    private function looseNamed(User $viewer, string $name): bool
    {
        return ChatGroup::query()
            ->where('name', $name)
            ->whereNull('community_id')
            ->whereHas('conversation', function ($q) use ($viewer) {
                $q->where('created_by_id', $viewer->id);
            })
            ->exists();
    }

    private function hops(CommunityService $communities, GroupService $groups, User $viewer, User $sara, User $omar): int
    {
        $name = 'طلبات الانضمام';
        if ($this->named($name)) {
            return 0;
        }

        $open = $groups->create($sara, 'نادي القراءة', null, [$omar])['participant']->conversation;
        $ask = $groups->create($sara, 'اللجنة المالية', null, [$omar])['participant']->conversation;
        $groups->addMembers($sara, $open, [$viewer]);
        $groups->addMembers($sara, $ask, [$viewer]);
        $openRow = $open->activeParticipants()->of($viewer)->first();
        $askRow = $ask->activeParticipants()->of($viewer)->first();
        if ($openRow === null || $askRow === null) {
            $this->command?->warn('Could not add the viewer to hop-in groups.');

            return 0;
        }
        $groups->setRole($sara, $open, $openRow->id, ParticipantRole::Admin);
        $groups->setRole($sara, $ask, $askRow->id, ParticipantRole::Admin);

        $mine = $groups->create($viewer, 'غرفة التنسيق', null, [$sara])['participant']->conversation;
        $communities->create($viewer, $name, 'جروبات أقدر أدخلها أو أطلب أدخلها', [$mine->uuid, $open->uuid, $ask->uuid]);

        $groups->removeMember($sara, $open, $openRow->id);
        $groups->removeMember($sara, $ask, $askRow->id);
        $ask->group->update(['approve_joins' => true]);
        ChatGroupJoinRequest::query()->create([
            'conversation_id' => $ask->id,
            'requester_type' => ParticipantType::aliasFor($viewer),
            'requester_id' => $viewer->id,
            'status' => ChatGroupJoinRequest::PENDING,
        ]);

        return 1;
    }

    private function named(string $name): bool
    {
        return ChatCommunity::query()->where('name', $name)->exists();
    }

    private function person(string $name, string $local, ?int $countryId): User
    {
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

        return $other;
    }
}
