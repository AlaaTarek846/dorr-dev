<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Joining (direct / approval / request with a reason — GR-003) and roles with permissions in
 * educational and work groups (GR-004): templates, roles made by the group, several roles per
 * member, no handing out what you don't hold, checked on the server, recorded.
 */
class ChatGroupRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private User $assistant;

    private User $newcomer;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->teacher = $make('Teacher', '+966500000001');
        $this->student = $make('Student', '+966500000002');
        $this->assistant = $make('Assistant', '+966500000003');
        $this->newcomer = $make('Newcomer', '+966500000004');
    }

    public function test_joining_by_request_keeps_the_reason_for_the_admins(): void
    {
        $group = $this->classGroup();
        $this->as($this->teacher);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['join_mode' => 'request'], $this->headers())->assertOk()
            ->assertJsonPath('data.group.join_mode', 'request')->assertJsonPath('data.group.approve_joins', true);
        $token = $this->getJson("/api/mobile/v1/chat/groups/{$group}/invite", $this->headers())->assertOk()->json('data.token');

        $this->as($this->newcomer);
        $this->getJson("/api/mobile/v1/chat/invites/{$token}", $this->headers())->assertOk()->assertJsonPath('data.join_mode', 'request');
        $this->postJson("/api/mobile/v1/chat/invites/{$token}/join", ['reason' => 'I am in class 3B'], $this->headers())->assertStatus(202);

        $this->as($this->teacher);
        $requests = $this->getJson("/api/mobile/v1/chat/groups/{$group}/join-requests", $this->headers())->assertOk()
            ->assertJsonPath('data.0.reason', 'I am in class 3B')->json('data');

        // Stopped since asking: no way in.
        $this->newcomer->update(['status' => 'blocked']);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/join-requests/{$requests[0]['id']}/approve", [], $this->headers())->assertNotFound();
        $this->newcomer->update(['status' => 'active']);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/join-requests/{$requests[0]['id']}/approve", [], $this->headers())->assertOk();

        // Approval without a reason: older apps that only send approve_joins keep working.
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['approve_joins' => false], $this->headers())->assertJsonPath('data.group.join_mode', 'direct');
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['approve_joins' => true], $this->headers())->assertJsonPath('data.group.join_mode', 'approval');
    }

    public function test_an_educational_group_starts_with_its_roles_and_the_maker_leads_it(): void
    {
        $group = $this->classGroup();

        $this->as($this->teacher);
        $roles = $this->getJson("/api/mobile/v1/chat/groups/{$group}/roles", $this->headers())->assertOk()
            ->assertJsonPath('data.roles.0.template', 'teacher')->assertJsonPath('data.roles.0.name', 'Teacher')
            ->json('data');
        $this->assertContains('roles.manage', $roles['my_permissions']);
        // A parent is no member of the class (D8): no "parent" role.
        $this->assertSame(['student'], array_values(array_intersect(['student', 'parent'], array_column($roles['roles'], 'template'))));

        // Everyone added gets the default role (student); my permissions come with the conversation.
        $this->as($this->student);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertOk()
            ->assertJsonPath('data.group.my_permissions', ['files.upload', 'assignments.submit', 'quizzes.take']);
        $mine = $this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data');
        $studentRole = collect($roles['roles'])->firstWhere('template', 'student')['id'];
        $this->assertSame([$studentRole], collect($mine)->firstWhere('profile.account_name', 'Student')['roles']);

        // A student can't manage roles.
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/roles", ['name' => 'Class head', 'permissions' => ['announcements.post']], $this->headers())
            ->assertForbidden()->assertJsonPath('error_code', 'chat_group_permission_denied');

        // Ordinary groups have no roles.
        $plain = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Friends', 'members' => [$this->teacher->id]], $this->headers())->json('data.conversation.id');
        $this->getJson("/api/mobile/v1/chat/groups/{$plain}/roles", $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_group_kind_required');
    }

    public function test_roles_are_made_given_several_at_a_time_and_never_beyond_what_i_hold(): void
    {
        $group = $this->classGroup();
        $this->as($this->teacher);
        $roles = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/roles", $this->headers())->json('data.roles'));
        $members = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'));
        $assistantRow = $members->firstWhere('profile.account_name', 'Assistant')['participant_id'];
        $studentRow = $members->firstWhere('profile.account_name', 'Student')['participant_id'];

        // A role of our own, and permissions that don't exist for this kind are refused.
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/roles", ['name' => 'Roles keeper', 'permissions' => ['tasks.assign']], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_permission_unknown');
        $keeper = $this->postJson("/api/mobile/v1/chat/groups/{$group}/roles", ['name' => 'Roles keeper', 'permissions' => ['roles.manage', 'announcements.post']], $this->headers())
            ->assertCreated()->assertJsonPath('data.members_count', 0)->json('data.id');

        // Two roles at once for the assistant.
        $assistantRole = $roles->firstWhere('template', 'assistant')['id'];
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/members/{$assistantRow}/roles", ['roles' => [$assistantRole, $keeper]], $this->headers())
            ->assertOk()->assertJsonPath('data.roles', [$assistantRole, $keeper]);

        // The assistant now manages roles — but can't give what they don't hold (grading is theirs; lessons too; quizzes aren't).
        $this->as($this->assistant);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/roles", ['name' => 'Quiz maker', 'permissions' => ['quizzes.manage']], $this->headers())
            ->assertForbidden();
        $teacherRole = $roles->firstWhere('template', 'teacher')['id'];
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/members/{$studentRow}/roles", ['roles' => [$teacherRole]], $this->headers())->assertForbidden();
        // Nor take away a role whose permissions they don't hold (student) — adding grader keeps it.
        $studentRole = $roles->firstWhere('template', 'student')['id'];
        $graderRole = $roles->firstWhere('template', 'grader')['id'];
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/members/{$studentRow}/roles", ['roles' => [$graderRole]], $this->headers())->assertForbidden();
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/members/{$studentRow}/roles", ['roles' => [$studentRole, $graderRole]], $this->headers())->assertOk();

        // A role someone holds can't be deleted until they're moved; the default role never.
        $this->as($this->teacher);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}/roles/{$keeper}", [], $this->headers())->assertStatus(409)->assertJsonPath('error_code', 'chat_role_in_use');
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}/roles/{$roles->firstWhere('template', 'student')['id']}", [], $this->headers())
            ->assertStatus(409)->assertJsonPath('error_code', 'chat_role_is_default');
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/members/{$assistantRow}/roles", ['roles' => [$assistantRole]], $this->headers())->assertOk();
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}/roles/{$keeper}", [], $this->headers())->assertOk();

        // Every change is on the record.
        $this->assertSame(
            ['role_created', 'member_roles_changed', 'member_roles_changed', 'member_roles_changed', 'role_deleted'],
            ChatGroupAuditLog::query()->where('action', 'like', 'role%')->orWhere('action', 'member_roles_changed')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_leaving_ends_every_permission_and_coming_back_starts_from_the_default_role(): void
    {
        $group = $this->classGroup();
        $this->as($this->teacher);
        $roles = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/roles", $this->headers())->json('data.roles'));
        $row = collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))->firstWhere('profile.account_name', 'Assistant')['participant_id'];
        $this->putJson("/api/mobile/v1/chat/groups/{$group}/members/{$row}/roles", ['roles' => [$roles->firstWhere('template', 'assistant')['id']]], $this->headers())->assertOk();

        $this->as($this->assistant);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/leave", [], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/roles", $this->headers())->assertForbidden();

        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/members", ['members' => [$this->assistant->id]], $this->headers())->assertOk();
        $this->as($this->assistant);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/roles", $this->headers())->assertOk()
            ->assertJsonPath('data.my_permissions', ['files.upload', 'assignments.submit', 'quizzes.take']);
    }

    private function classGroup(): string
    {
        $this->as($this->teacher);

        return $this->postJson('/api/mobile/v1/chat/groups', [
            'name' => 'Maths 3', 'kind' => 'education', 'members' => [$this->student->id, $this->assistant->id],
        ], $this->headers())->assertCreated()->json('data.conversation.id');
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
