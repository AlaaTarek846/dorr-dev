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
use Modules\Chat\Models\ChatGroupTask;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Tasks in work groups (docs/groups_tasks.md GR-033..036): who may make, give, move and approve
 * them; checklist and proof; repeating; and project tasks for project members only.
 */
class ChatGroupTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

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
        $this->manager = $make('Manager', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
    }

    public function test_a_task_goes_from_the_manager_to_the_member_through_proof_and_approval(): void
    {
        $group = $this->group();
        $amal = $this->participant($group, 'Amal');

        $this->as($this->manager);
        $task = $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", [
            'title' => 'Stock count', 'assignee_participant_id' => $amal, 'priority' => 'high', 'due_at' => now()->addDay()->toIso8601String(),
            'needs_approval' => true, 'requires_proof' => true, 'items' => ['Shelf A', 'Shelf B'],
        ], $this->headers())->assertCreated()->assertJsonPath('data.status', 'new')->assertJsonPath('data.items.1.text', 'Shelf B')->json('data');

        // A team member can make a task for themselves, not give one to someone else.
        $this->as($this->badr);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Mine'], $this->headers())->assertCreated();
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Yours', 'assignee_participant_id' => $amal], $this->headers())->assertForbidden();
        // …nor move someone else's task.
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/status", ['status' => 'in_progress'], $this->headers())->assertForbidden();

        // Amal works on it: no finishing without proof.
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks?mine=1", $this->headers())->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.can.work', true);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/status", ['status' => 'in_progress'], $this->headers())->assertOk();
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/items/{$task['items'][0]['id']}", ['done' => true], $this->headers())->assertJsonPath('data.items_done', 1);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/status", ['status' => 'done'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_task_proof_required');
        $proof = $this->post("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/proof", [
            'files' => [UploadedFile::fake()->image('shelf.jpg', 200, 200)], 'note' => 'All counted',
        ], $this->headers() + ['Accept' => 'application/json'])->assertOk()->json('data.proof');
        $this->assertStringContainsString('/chat/media/', $proof['files'][0]['url']);
        $this->get($proof['files'][0]['url'])->assertOk();

        // Done waits for the manager.
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/status", ['status' => 'done'], $this->headers())->assertJsonPath('data.status', 'in_review');
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/review", ['approve' => true], $this->headers())->assertForbidden();

        // Sent back with a note, then approved.
        $this->as($this->manager);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/review", ['approve' => false], $this->headers())->assertStatus(422);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/review", ['approve' => false, 'note' => 'Shelf B missing'], $this->headers())
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.review_note', 'Shelf B missing');
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/status", ['status' => 'done'], $this->headers())->assertJsonPath('data.status', 'in_review');
        $this->as($this->manager);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks?status=review", $this->headers())->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.can.review', true);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task['id']}/review", ['approve' => true], $this->headers())->assertJsonPath('data.status', 'done');
    }

    public function test_a_repeating_task_makes_the_next_one_when_it_is_due(): void
    {
        $group = $this->group();

        $this->as($this->manager);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Daily report', 'repeat' => 'daily'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_task_repeat_needs_due');
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", [
            'title' => 'Daily report', 'repeat' => 'daily', 'due_at' => now()->addHour()->toIso8601String(), 'repeat_until' => now()->addDays(2)->toDateString(), 'items' => ['Sales'],
        ], $this->headers())->assertCreated();

        $this->artisan('chat:spawn-recurring-tasks')->assertSuccessful();
        $this->assertSame(1, ChatGroupTask::query()->count());

        $this->travel(2)->hours();
        $this->artisan('chat:spawn-recurring-tasks')->assertSuccessful();
        $this->assertSame(2, ChatGroupTask::query()->count());
        $this->assertSame('Sales', ChatGroupTask::query()->latest('id')->first()->items()->first()->text);

        // Again tomorrow, then the series ends.
        $this->travel(1)->days();
        $this->artisan('chat:spawn-recurring-tasks');
        $this->travel(1)->days();
        $this->artisan('chat:spawn-recurring-tasks');
        $this->assertSame(3, ChatGroupTask::query()->count());
    }

    public function test_a_projects_tasks_are_for_its_members_only_and_tasks_are_for_work_groups(): void
    {
        $group = $this->group();
        $amal = $this->participant($group, 'Amal');

        $this->as($this->manager);
        $project = $this->postJson("/api/mobile/v1/chat/groups/{$group}/spaces", ['type' => 'project', 'name' => 'Launch', 'members' => [$amal]], $this->headers())->json('data.id');
        $badr = $this->participant($group, 'Badr');
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Poster', 'space_id' => $project, 'assignee_participant_id' => $badr], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_task_assignee_not_in_project');
        $task = $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Poster', 'space_id' => $project, 'assignee_participant_id' => $amal], $this->headers())
            ->assertCreated()->assertJsonPath('data.space.name', 'Launch')->json('data.id');

        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks", $this->headers())->assertJsonCount(0, 'data.tasks');
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task}", $this->headers())->assertNotFound();
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks", $this->headers())->assertJsonCount(1, 'data.tasks');

        $this->as($this->manager);
        $class = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Class', 'kind' => 'education', 'members' => [$this->amal->id]], $this->headers())->json('data.conversation.id');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/tasks", $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_work_group_only');
    }

    private function group(): string
    {
        $this->as($this->manager);

        return $this->postJson('/api/mobile/v1/chat/groups', [
            'name' => 'Store team', 'kind' => 'work', 'members' => [$this->amal->id, $this->badr->id],
        ], $this->headers())->assertCreated()->json('data.conversation.id');
    }

    private function participant(string $group, string $name): int
    {
        $this->as($this->manager);

        return collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->firstWhere('profile.account_name', $name)['participant_id'];
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
