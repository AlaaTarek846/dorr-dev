<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatGroupTask;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Work groups: a task made from a message, without ever showing that message to someone who can't
 * read it (GR-038); the team report, the export and the due reminder (GR-039).
 */
class ChatGroupTaskFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $amal;

    private User $badr;

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
        $this->manager = $make('Manager', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
    }

    public function test_a_task_made_from_a_message_keeps_a_safe_reference(): void
    {
        $group = $this->group();
        $amal = $this->participant($group, 'Amal');

        // A message in a project only Amal (and the manager) is in.
        $this->as($this->manager);
        $project = $this->postJson("/api/mobile/v1/chat/groups/{$group}/spaces", ['type' => 'project', 'name' => 'Launch', 'members' => [$amal]], $this->headers())->json('data');
        $message = $this->postJson("/api/mobile/v1/chat/conversations/{$project['conversation_id']}/messages", ['type' => 'text', 'body' => 'Print the posters by Thursday'], $this->headers())->json('data.id');

        // Made into a group task (everyone sees the task) from that message.
        $task = $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Print the posters', 'source_message_id' => $message], $this->headers())
            ->assertCreated()->assertJsonPath('data.source.available', true)->assertJsonPath('data.source.excerpt', 'Print the posters by Thursday')->json('data.id');

        // Badr (not in the project) sees the task, never the message's words.
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task}", $this->headers())->assertOk()
            ->assertJsonPath('data.source.available', false)->assertJsonMissingPath('data.source.excerpt');
        // Nor can he make a task from a message he can't read.
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'x', 'source_message_id' => $message], $this->headers())->assertNotFound();

        // Deleted for everyone: the task stays, the words go.
        $this->as($this->manager);
        $this->deleteJson("/api/mobile/v1/chat/messages/{$message}", [], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks/{$task}", $this->headers())->assertOk()
            ->assertJsonPath('data.title', 'Print the posters')->assertJsonPath('data.source.available', false);
    }

    public function test_the_manager_sees_the_team_report_and_exports_it_and_assignees_are_reminded(): void
    {
        $group = $this->group();
        $amal = $this->participant($group, 'Amal');

        $this->as($this->manager);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Late one', 'assignee_participant_id' => $amal, 'due_at' => now()->subHour()->toIso8601String()], $this->headers())->assertCreated();
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/tasks", ['title' => 'Soon', 'assignee_participant_id' => $amal, 'due_at' => now()->addMinutes(30)->toIso8601String()], $this->headers())->assertCreated();

        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks/report", $this->headers())->assertOk()
            ->assertJsonPath('data.totals.open', 2)->assertJsonPath('data.totals.late', 1)
            ->assertJsonPath('data.members.0.participant_id', $amal)->assertJsonPath('data.late.0.title', 'Late one');

        $csv = $this->get("/api/mobile/v1/chat/groups/{$group}/tasks/export", $this->headers())->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('Late one', $csv);
        $this->assertStringContainsString('Amal', $csv);

        // A team member has no report.
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/tasks/report", $this->headers())->assertForbidden();

        // Due within the hour: reminded once.
        $this->artisan('chat:remind-group-tasks')->assertSuccessful();
        $this->assertNotNull(ChatGroupTask::query()->where('title', 'Soon')->value('reminded_at'));
        $this->assertNull(ChatGroupTask::query()->where('title', 'Late one')->value('reminded_at'));
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
