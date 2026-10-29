<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Protection across the scenarios (docs/groups_tasks.md GR-055, the Overview's section 09). The
 * single features have their own tests; these cross them:
 * - removed, or a role taken back: content, files, search and export stop at once;
 * - terms / subgroups: nothing leaks, even to a member holding several roles;
 * - an old file link is checked again on every download;
 * - a task made from a message stays, the message's words don't;
 * - turned off, archived, deleted and purged are separate states;
 * - a normal group is touched by none of it.
 */
class ChatGroupProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

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
        $this->teacher = $make('Teacher', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
    }

    public function test_removal_and_a_role_taken_back_stop_content_files_search_and_export_at_once(): void
    {
        $class = $this->group('education');
        $amal = $this->participant($class, 'Amal');
        $badr = $this->participant($class, 'Badr');
        $roles = collect($this->getJson("/api/mobile/v1/chat/groups/{$class}/roles", $this->headers())->json('data.roles'));

        // Badr is made assistant: he may post lessons.
        $this->putJson("/api/mobile/v1/chat/groups/{$class}/members/{$badr}/roles", ['roles' => [$roles->firstWhere('template', 'assistant')['id']]], $this->headers())->assertOk();
        $this->as($this->badr);
        $this->post("/api/mobile/v1/chat/groups/{$class}/lessons", ['title' => 'By the assistant'], $this->headers() + ['Accept' => 'application/json'])->assertCreated();

        // Taken back: the very next request is refused.
        $this->as($this->teacher);
        $this->putJson("/api/mobile/v1/chat/groups/{$class}/members/{$badr}/roles", ['roles' => [$roles->firstWhere('template', 'student')['id']]], $this->headers())->assertOk();
        $this->as($this->badr);
        $this->post("/api/mobile/v1/chat/groups/{$class}/lessons", ['title' => 'Again'], $this->headers() + ['Accept' => 'application/json'])->assertForbidden();

        // Amal holds a link to a lesson's file…
        $this->as($this->teacher);
        $lesson = $this->post("/api/mobile/v1/chat/groups/{$class}/lessons", ['title' => 'Fractions', 'files' => [UploadedFile::fake()->create('sheet.pdf', 20, 'application/pdf')]], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->as($this->amal);
        $url = $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons/{$lesson}", $this->headers())->assertOk()->json('data.files.0.url');
        $this->get($url)->assertOk();

        // …and is removed: the old link, the list, the search are all closed.
        $this->as($this->teacher);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$class}/members/{$amal}", [], $this->headers())->assertOk();
        $this->as($this->amal);
        $this->get($url)->assertNotFound();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons?q=Fractions", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons/{$lesson}", $this->headers())->assertForbidden();

        // The same for a work group's tasks and their export.
        $team = $this->group('work');
        $amalInTeam = $this->participant($team, 'Amal');
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/tasks", ['title' => 'Count stock', 'assignee_participant_id' => $amalInTeam], $this->headers())->assertCreated();
        $this->deleteJson("/api/mobile/v1/chat/groups/{$team}/members/{$amalInTeam}", [], $this->headers())->assertOk();
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/tasks?mine=1", $this->headers())->assertForbidden();
        $this->get("/api/mobile/v1/chat/groups/{$team}/tasks/export", $this->headers() + ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_subgroup_leaks_nothing_even_to_a_member_with_several_roles(): void
    {
        $class = $this->group('education');
        $amal = $this->participant($class, 'Amal');
        $badr = $this->participant($class, 'Badr');
        $roles = collect($this->getJson("/api/mobile/v1/chat/groups/{$class}/roles", $this->headers())->json('data.roles'));

        $sub = $this->postJson("/api/mobile/v1/chat/groups/{$class}/spaces", ['type' => 'subgroup', 'name' => '3A', 'members' => [$amal]], $this->headers())->json('data');
        $lesson = $this->post("/api/mobile/v1/chat/groups/{$class}/lessons", ['title' => 'Only 3A', 'space_id' => $sub['id'], 'files' => [UploadedFile::fake()->create('a.pdf', 20, 'application/pdf')]], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$sub['conversation_id']}/messages", ['type' => 'text', 'body' => 'For 3A'], $this->headers())->assertCreated();

        // Badr: student + grader + moderator — none of them reaches into 3A.
        $this->putJson("/api/mobile/v1/chat/groups/{$class}/members/{$badr}/roles", ['roles' => $roles->whereIn('template', ['student', 'grader', 'moderator'])->pluck('id')->values()->all()], $this->headers())->assertOk();
        $this->as($this->amal);
        $url = $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons/{$lesson}", $this->headers())->assertOk()->json('data.files.0.url');

        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons?q=3A", $this->headers())->assertOk()->assertJsonCount(0, 'data.lessons');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons/{$lesson}", $this->headers())->assertNotFound();
        $this->getJson("/api/mobile/v1/chat/conversations/{$sub['conversation_id']}/messages", $this->headers())->assertForbidden();
        // Amal's link names her: putting Badr in it breaks the signature.
        $this->assertStringContainsString('v=user%3A'.$this->amal->id, $url);
        $this->get(str_replace('v=user%3A'.$this->amal->id, 'v=user%3A'.$this->badr->id, $url))->assertForbidden();
    }

    public function test_turned_off_archived_deleted_and_purged_are_separate_states(): void
    {
        $team = $this->group('work');

        // The owner archives and un-archives at will…
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/archive", ['archived' => true], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/archive", ['archived' => false], $this->headers())->assertOk()->assertJsonPath('data.can_send', true);

        // …but an admin's "off" stays off whatever the owner does.
        $this->asAdmin();
        $this->patchJson("/api/admin/v1/chat-groups/{$team}/disable", ['disabled' => true])->assertOk();
        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/archive", ['archived' => true], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/archive", ['archived' => false], $this->headers())->assertOk()->assertJsonPath('data.can_send', false);
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/conversations/{$team}/messages", ['type' => 'text', 'body' => 'Hi'], $this->headers())->assertStatus(423);

        // Deleted and brought back: still off. Deleted and left: purged, for good.
        $this->as($this->teacher);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$team}", [], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/restore", [], $this->headers())->assertOk();
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/conversations/{$team}/messages", ['type' => 'text', 'body' => 'Hi'], $this->headers())->assertStatus(423);

        $other = $this->group('work');
        $this->deleteJson("/api/mobile/v1/chat/groups/{$other}", [], $this->headers())->assertOk();
        $this->travel(ChatSetting::current()->group_restore_days + 1)->days();
        $this->artisan('chat:purge-deleted-groups')->assertSuccessful();
        $this->assertNull(ChatConversation::withTrashed()->where('uuid', $other)->first());
        $this->assertNotNull(ChatConversation::query()->where('uuid', $team)->first(), 'a turned-off group is never purged');
    }

    public function test_a_normal_group_is_untouched(): void
    {
        $chat = $this->group(null);

        $this->getJson("/api/mobile/v1/chat/groups/{$chat}/lessons", $this->headers())->assertStatus(422);
        $this->getJson("/api/mobile/v1/chat/groups/{$chat}/tasks", $this->headers())->assertStatus(422);
        $this->postJson("/api/mobile/v1/chat/groups/{$chat}/archive", ['archived' => true], $this->headers())->assertStatus(422);

        // Every tool switched off changes nothing for it.
        ChatSetting::current()->update(['group_tools_disabled' => \Modules\Chat\Http\Middleware\EnsureGroupTool::all()]);
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'Hi'], $this->headers())->assertCreated();

        // Nor does the admin's groups page list it.
        $this->asAdmin();
        $this->getJson('/api/admin/v1/chat-groups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/v1/chat-groups/{$chat}")->assertNotFound();
    }

    private function group(?string $kind): string
    {
        $this->as($this->teacher);

        return $this->postJson('/api/mobile/v1/chat/groups', array_filter([
            'name' => 'Group', 'kind' => $kind, 'members' => [$this->amal->id, $this->badr->id],
        ]), $this->headers())->assertCreated()->json('data.conversation.id');
    }

    private function participant(string $group, string $name): int
    {
        $this->as($this->teacher);

        return collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->firstWhere('profile.account_name', $name)['participant_id'];
    }

    private function asAdmin(): void
    {
        $admin = Admin::query()->firstOrCreate(['email' => 'g@example.com'], ['name' => 'A', 'password' => 'secret123', 'status' => 'active']);
        foreach (['chat-groups.view', 'chat-groups.update'] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo(['chat-groups.view', 'chat-groups.update']);
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
