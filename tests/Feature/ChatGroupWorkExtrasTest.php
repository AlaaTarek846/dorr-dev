<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupMeeting;
use Modules\Chat\Models\ChatGroupTask;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Work group extras (GR-040, 042, 043, 044, 046 without AI), anonymous polls (GR-045), tools switched
 * off by the admin (GR-051) and the admin's groups page (GR-050, 052, 053).
 */
class ChatGroupWorkExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    private User $amal;

    private User $badr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->boss = $make('Boss', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
    }

    public function test_shifts_hand_over_and_swap_only_when_the_other_member_agrees(): void
    {
        $team = $this->group('work');
        $amal = $this->participant($team, 'Amal');
        $badr = $this->participant($team, 'Badr');

        // A plain member can't set shifts.
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts", $this->shift($amal, 2), $this->headers())->assertForbidden();

        $this->as($this->boss);
        $first = $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts", $this->shift($amal, 2), $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts", $this->shift($badr, 12), $this->headers())->assertCreated();

        // Amal hands over; only the shift's own member may.
        $this->as($this->badr);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts/{$first}/handover", ['note' => 'Till is counted'], $this->headers())->assertForbidden();
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts/{$first}/handover", ['note' => 'Till is counted'], $this->headers())->assertOk()
            ->assertJsonPath('data.handover_note', 'Till is counted');

        // Amal asks Badr to swap; it stays hers until he agrees.
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts/{$first}/swap", ['with_participant_id' => $badr], $this->headers())->assertOk()
            ->assertJsonPath('data.member.participant_id', $amal)->assertJsonPath('data.swap_with.participant_id', $badr);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts/{$first}/swap/answer", ['accept' => true], $this->headers())->assertNotFound();

        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/shifts?mine=1", $this->headers())->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/shifts/{$first}/swap/answer", ['accept' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.member.participant_id', $badr)->assertJsonPath('data.swap_with', null);
        $this->assertTrue(ChatGroupAuditLog::query()->where('action', 'shift_swapped')->exists());
    }

    public function test_competitions_badges_and_the_ideas_bank(): void
    {
        $team = $this->group('work');
        $amal = $this->participant($team, 'Amal');
        $badr = $this->participant($team, 'Badr');

        $this->as($this->boss);
        $goal = $this->postJson("/api/mobile/v1/chat/groups/{$team}/goals", ['title' => 'Most sales'], $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/goals/{$goal}/points", ['participant_id' => $amal, 'points' => 5], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/goals/{$goal}/points", ['participant_id' => $badr, 'points' => 8], $this->headers())->assertOk()
            ->assertJsonPath('data.leaderboard.0.member.participant_id', $badr)->assertJsonPath('data.leaderboard.1.rank', 2);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/badges", ['participant_id' => $amal, 'title' => 'Fast hands', 'emoji' => '⚡'], $this->headers())->assertCreated();

        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/goals/{$goal}/points", ['participant_id' => $amal, 'points' => 100], $this->headers())->assertForbidden();
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/badges", ['participant_id' => $amal, 'title' => 'Me'], $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/badges?participant_id={$amal}", $this->headers())->assertOk()->assertJsonPath('data.0.title', 'Fast hands');

        // Anyone proposes and votes once; voting again takes it back.
        $idea = $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas", ['title' => 'Open on Fridays'], $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas/{$idea}/vote", [], $this->headers())->assertOk()->assertJsonPath('data.votes', 1)->assertJsonPath('data.voted', true);
        $this->as($this->badr);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas/{$idea}/vote", [], $this->headers())->assertJsonPath('data.votes', 2);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas/{$idea}/vote", [], $this->headers())->assertJsonPath('data.votes', 1)->assertJsonPath('data.voted', false);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas/{$idea}/status", ['status' => 'accepted'], $this->headers())->assertForbidden();

        $this->as($this->boss);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas/{$idea}/status", ['status' => 'accepted', 'note' => 'From next month'], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/ideas?status=accepted", $this->headers())->assertJsonCount(1, 'data');
    }

    public function test_meetings_minutes_tasks_from_the_minutes_and_the_reminder(): void
    {
        $team = $this->group('work');
        $amal = $this->participant($team, 'Amal');

        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/meetings", ['title' => 'Weekly', 'starts_at' => now()->addMinutes(20)->toIso8601String()], $this->headers())->assertForbidden();

        $this->as($this->boss);
        $meeting = $this->postJson("/api/mobile/v1/chat/groups/{$team}/meetings", ['title' => 'Weekly', 'starts_at' => now()->addMinutes(20)->toIso8601String(), 'agenda' => 'Stock'], $this->headers())
            ->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/meetings/{$meeting}/minutes", ['minutes' => 'Order more boxes'], $this->headers())->assertOk()
            ->assertJsonPath('data.minutes', 'Order more boxes');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/meetings/{$meeting}/tasks", ['items' => [['title' => 'Order boxes', 'assignee_participant_id' => $amal]]], $this->headers())
            ->assertCreated();
        $this->assertSame('Order boxes', ChatGroupTask::query()->value('title'));

        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/meetings/{$meeting}/minutes", ['minutes' => 'Mine'], $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/meetings", $this->headers())->assertOk()->assertJsonPath('data.0.can_edit', false);

        $this->artisan('chat:remind-group-sessions')->assertSuccessful();
        $this->assertNotNull(ChatGroupMeeting::query()->value('reminded_at'));
    }

    public function test_work_tools_are_for_work_groups_an_anonymous_poll_hides_its_voters_and_the_admin_can_switch_a_tool_off(): void
    {
        $class = $this->group('education');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/ideas", $this->headers())->assertStatus(422);

        $team = $this->group('work');
        $poll = $this->postJson("/api/mobile/v1/chat/groups/{$team}/polls", ['question' => 'Lunch?', 'options' => ['Pizza', 'Rice'], 'anonymous' => true], $this->headers())
            ->assertCreated()->json('data.message_id');
        $option = data_get(ChatMessage::query()->where('uuid', $poll)->first()->meta, 'options.0.id');

        $this->as($this->amal);
        $this->putJson("/api/mobile/v1/chat/messages/{$poll}/vote", ['options' => [$option]], $this->headers())->assertOk();
        $this->as($this->boss);
        $this->getJson("/api/mobile/v1/chat/messages/{$poll}/votes", $this->headers())->assertForbidden();

        // GR-051: switched off for everyone, its data kept.
        ChatSetting::current()->update(['group_tools_disabled' => ['ideas']]);
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/ideas", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/goals", $this->headers())->assertOk();
    }

    public function test_the_admin_sees_the_groups_turns_one_off_and_brings_a_deleted_one_back(): void
    {
        $team = $this->group('work');
        $this->group('education');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas", ['title' => 'Before'], $this->headers())->assertCreated();

        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/chat-groups')->assertForbidden();

        $this->asAdmin(['chat-groups.view'], 'v@example.com');
        $this->getJson('/api/admin/v1/chat-groups?kind=work')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.owner.name', 'Boss');
        $this->getJson('/api/admin/v1/chat-groups/stats')->assertOk()->assertJsonPath('data.education', 1)->assertJsonPath('data.work', 1);
        $this->getJson("/api/admin/v1/chat-groups/{$team}")->assertOk()->assertJsonCount(3, 'data.members');
        $this->getJson('/api/admin/v1/chat-groups/templates')->assertOk()->assertJsonStructure(['data' => ['education' => ['roles'], 'work' => ['roles']]]);
        $this->assertTrue(ChatGroupAuditLog::query()->where('action', 'admin_viewed')->where('actor_type', 'admin')->exists());
        $this->patchJson("/api/admin/v1/chat-groups/{$team}/disable", ['disabled' => true])->assertForbidden();

        $this->asAdmin(['chat-groups.view', 'chat-groups.update'], 'u@example.com');
        $this->patchJson("/api/admin/v1/chat-groups/{$team}/disable", ['disabled' => true])->assertOk()->assertJsonPath('data.status', 'disabled');

        // Turned off: read only, and the owner's own log says it was the administration.
        $this->as($this->boss);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/ideas", ['title' => 'After'], $this->headers())->assertStatus(423);
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/audit-log", $this->headers())->assertOk()->assertJsonPath('data.items.0.actor.is_admin', true);

        $this->asAdmin(['chat-groups.view', 'chat-groups.update'], 'w@example.com');
        $this->patchJson("/api/admin/v1/chat-groups/{$team}/disable", ['disabled' => false])->assertOk()->assertJsonPath('data.status', 'active');

        $this->as($this->boss);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$team}", [], $this->headers())->assertOk();
        $this->asAdmin(['chat-groups.view', 'chat-groups.update'], 'x@example.com');
        $this->getJson('/api/admin/v1/chat-groups?status=deleted')->assertJsonCount(1, 'data');
        $this->postJson("/api/admin/v1/chat-groups/{$team}/restore")->assertOk()->assertJsonPath('data.status', 'active');
    }

    /** @return array<string, mixed> a shift starting in that many hours, 8 hours long */
    private function shift(int $participantId, int $inHours): array
    {
        return ['participant_id' => $participantId, 'starts_at' => now()->addHours($inHours)->toIso8601String(), 'ends_at' => now()->addHours($inHours + 8)->toIso8601String()];
    }

    private function group(string $kind): string
    {
        $this->as($this->boss);

        return $this->postJson('/api/mobile/v1/chat/groups', [
            'name' => 'Team', 'kind' => $kind, 'members' => [$this->amal->id, $this->badr->id],
        ], $this->headers())->assertCreated()->json('data.conversation.id');
    }

    private function participant(string $group, string $name): int
    {
        $this->as($this->boss);

        return collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->firstWhere('profile.account_name', $name)['participant_id'];
    }

    /** @param list<string> $permissions */
    private function asAdmin(array $permissions, string $email = 'a@example.com'): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => $email, 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
