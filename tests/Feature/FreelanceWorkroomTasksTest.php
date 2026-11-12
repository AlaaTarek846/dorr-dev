<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Events\FreelanceWorkroomChanged;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectEvent;
use Modules\Freelance\Models\FreelanceProjectMember;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceWorkroomTask;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-150a: the Workroom's tasks - each side adds tasks for itself or the other side, the writer changes and deletes them, the side a task
 * is for moves its status; a part can own tasks; the admin's cap stops new ones only; after the agreement ends they are only read; a member of
 * the client's team reads them and a manager works on them; every step is on the project record and tells the live screens once.
 */
class FreelanceWorkroomTasksTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const TEAM = '/api/mobile/v1/freelance/team';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $owner;

    private User $omar;

    private User $huda;

    private FreelanceProject $project;

    private Freelancer $sara;

    private Freelancer $lina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'client_team', 'workroom'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->owner = $this->user('Mona', '+966500000001');
        $this->omar = $this->user('Omar', '+966500000002');
        $this->huda = $this->user('Huda', '+966500000003');
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->owner->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $phone): User
    {
        return User::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(User|Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], $who instanceof Freelancer ? 'freelance_api' : 'user_api');
    }

    /** The owner invites, the person accepts: an active member with this role. */
    private function join(User $who, string $role): FreelanceProjectMember
    {
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $who->phone, 'role' => $role])->assertOk();
        $member = FreelanceProjectMember::query()->where('user_id', $who->id)->firstOrFail();
        $this->as($who);
        $this->postJson(self::TEAM."/invitations/{$member->uuid}/accept")->assertOk();

        return $member->refresh();
    }

    /** Sara chosen, two parts, the agreement active; returns the parts' uuids. */
    private function active(): array
    {
        $this->as($this->owner);
        $offer = FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => [
            ['title' => 'Design', 'amount_minor' => 100000], ['title' => 'Build', 'amount_minor' => 250000],
        ]])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();

        return FreelanceMilestone::query()->orderBy('position')->pluck('uuid')->all();
    }

    private function url(string $prefix, string $suffix = ''): string
    {
        return $prefix."/{$this->project->uuid}/workroom/tasks".$suffix;
    }

    /** The task the client adds for himself or for Sara; returns its uuid. */
    private function clientTask(array $extra = []): string
    {
        $this->as($this->owner);

        return (string) $this->postJson($this->url(self::CLIENT), $extra + ['title' => 'Send the brand colours'])->assertCreated()->json('data.task.uuid');
    }

    private function freelancerTask(array $extra = []): string
    {
        $this->as($this->sara);

        return (string) $this->postJson($this->url(self::FREELANCER), $extra + ['title' => 'Draft the home page'])->assertCreated()->json('data.task.uuid');
    }

    public function test_the_workroom_is_off_until_the_admin_opens_it_for_the_country(): void
    {
        $this->active();
        FreelanceFeatureRule::query()->where('feature', 'workroom')->delete();
        FreelanceFeatures::forget();

        $this->as($this->owner);
        $this->getJson($this->url(self::CLIENT))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->postJson($this->url(self::CLIENT), ['title' => 'Send the brand colours'])->assertForbidden();
        $this->as($this->sara);
        $this->getJson($this->url(self::FREELANCER))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->assertSame(0, FreelanceWorkroomTask::query()->count());
    }

    public function test_each_side_adds_a_task_for_itself_or_for_the_other_side_and_the_other_side_is_told(): void
    {
        $parts = $this->active();
        Event::fake([FreelanceWorkroomChanged::class]);

        // The client writes a task for himself: nobody else is told.
        $this->as($this->owner);
        $mine = $this->postJson($this->url(self::CLIENT), ['title' => 'Send the brand colours', 'description' => 'The two hex codes and the logo.', 'milestone' => $parts[0], 'due_on' => now()->addDays(3)->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.task.title', 'Send the brand colours')->assertJsonPath('data.task.status', 'todo')->assertJsonPath('data.task.assignee_side', 'client')
            ->assertJsonPath('data.task.created_by_side', 'client')->assertJsonPath('data.task.mine', true)->assertJsonPath('data.task.milestone.title', 'Design')
            ->assertJsonPath('data.task.can.task_edit', true)->assertJsonPath('data.task.can.task_delete', true)->assertJsonPath('data.task.can.task_status', true)
            ->assertJsonPath('data.summary.todo', 1)->assertJsonPath('data.summary.total', 1)->json('data.task.uuid');
        $this->assertSame(0, $this->sara->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());

        // The client gives Sara a task: she is told, and for her it is not hers to edit.
        $forSara = $this->postJson($this->url(self::CLIENT), ['title' => 'Prepare the wireframes', 'assignee_side' => 'freelancer'])->assertCreated()->assertJsonPath('data.task.assignee_side', 'freelancer')
            ->assertJsonPath('data.task.mine', false)->json('data.task.uuid');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());
        $note = $this->sara->notifications()->where('data->event', 'freelance.workroom.task_assigned')->first();
        $this->assertStringContainsString('Prepare the wireframes', json_encode($note->data));

        $this->as($this->sara);
        $this->getJson($this->url(self::FREELANCER))->assertOk()->assertJsonCount(2, 'data.tasks')
            ->assertJsonPath('data.summary.total', 2)->assertJsonPath('data.can.task_create', true)->assertJsonPath('data.read_only', false);
        $this->getJson($this->url(self::FREELANCER).'?mine=1')->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.uuid', $forSara);

        // Sara gives the client one: the client (and his team) are told.
        $this->postJson($this->url(self::FREELANCER), ['title' => 'Approve the wireframes', 'assignee_side' => 'client'])->assertCreated();
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());
        $this->assertNotSame($mine, $forSara);

        // Each step told the live screens of the project once, with no text.
        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 3);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->action === 'created' && $e->by === 'client' && $e->projectUuid === $this->project->uuid && $e->broadcastWith()['kind'] === 'task');
    }

    public function test_a_task_can_belong_to_a_part_of_this_agreement_only(): void
    {
        $parts = $this->active();
        $this->as($this->owner);
        $this->postJson($this->url(self::CLIENT), ['title' => 'Check the build', 'milestone' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_milestone_invalid');
        $this->postJson($this->url(self::CLIENT), ['title' => 'Check the build', 'milestone' => 'not-a-uuid'])->assertUnprocessable();
        $uuid = $this->clientTask(['milestone' => $parts[1]]);

        // Moving it to the other part, then to none.
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['milestone' => $parts[0]])->assertOk()->assertJsonPath('data.task.milestone.title', 'Design');
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['milestone' => null])->assertOk()->assertJsonPath('data.task.milestone', null);

        // The list filters by part.
        $this->clientTask(['title' => 'Review the design', 'milestone' => $parts[0]]);
        $this->getJson($this->url(self::CLIENT).'?milestone='.$parts[0])->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.title', 'Review the design');
    }

    public function test_only_the_writer_edits_or_deletes_and_only_the_side_it_is_for_or_the_writer_moves_the_status(): void
    {
        $this->active();
        $forSara = $this->clientTask(['title' => 'Prepare the wireframes', 'assignee_side' => 'freelancer']);
        $mine = $this->clientTask(['title' => 'Send the brand colours']);

        // Sara: the task is hers to do, so she moves it; she does not edit or delete what the client wrote.
        $this->as($this->sara);
        $this->postJson($this->url(self::FREELANCER, "/{$forSara}/status"), ['status' => 'doing'])->assertOk()->assertJsonPath('data.task.status', 'doing')
            ->assertJsonPath('data.task.can.task_status', true)->assertJsonPath('data.task.can.task_edit', false)->assertJsonPath('data.task.can.task_delete', false);
        $this->putJson($this->url(self::FREELANCER, "/{$forSara}"), ['title' => 'Something else'])->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_forbidden');
        $this->deleteJson($this->url(self::FREELANCER, "/{$forSara}"))->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_forbidden');
        // A task that is the client's own is not hers to move.
        $this->postJson($this->url(self::FREELANCER, "/{$mine}/status"), ['status' => 'done'])->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_forbidden');
        $this->assertSame('todo', FreelanceWorkroomTask::query()->where('uuid', $mine)->value('status'));

        // The client, who wrote Sara's task, may still move it, change it and give it to himself.
        $this->as($this->owner);
        $this->postJson($this->url(self::CLIENT, "/{$forSara}/status"), ['status' => 'blocked'])->assertOk()->assertJsonPath('data.task.status', 'blocked');
        $this->putJson($this->url(self::CLIENT, "/{$forSara}"), ['title' => 'Prepare the mobile wireframes', 'assignee_side' => 'client'])->assertOk()
            ->assertJsonPath('data.task.title', 'Prepare the mobile wireframes')->assertJsonPath('data.task.assignee_side', 'client');
        // ... and now Sara has no part in it at all.
        $this->as($this->sara);
        $this->postJson($this->url(self::FREELANCER, "/{$forSara}/status"), ['status' => 'done'])->assertForbidden();

        // A task is found only inside its own project.
        $this->getJson($this->url(self::FREELANCER, '/'.\Illuminate\Support\Str::uuid()))->assertStatus(405);
        $this->putJson($this->url(self::FREELANCER, '/'.\Illuminate\Support\Str::uuid()), ['title' => 'Nothing here'])->assertNotFound();

        $this->as($this->owner);
        $this->deleteJson($this->url(self::CLIENT, "/{$mine}"))->assertOk()->assertJsonPath('data.summary.total', 1);
        $this->assertSoftDeleted('freelance_workroom_tasks', ['uuid' => $mine]);
        $this->getJson($this->url(self::CLIENT))->assertJsonCount(1, 'data.tasks');
    }

    public function test_a_task_given_to_the_other_side_by_an_edit_notifies_it_once(): void
    {
        $this->active();
        $uuid = $this->clientTask(['title' => 'Prepare the wireframes']);
        $this->as($this->owner);
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['title' => 'Prepare the wireframes please'])->assertOk();
        $this->assertSame(0, $this->sara->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['assignee_side' => 'freelancer'])->assertOk();
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['assignee_side' => 'freelancer', 'due_on' => now()->addDay()->toDateString()])->assertOk();
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());
    }

    public function test_the_status_moves_and_the_day_it_was_finished_follows_it(): void
    {
        $this->active();
        Event::fake([FreelanceWorkroomChanged::class]);
        $uuid = $this->freelancerTask();
        $this->as($this->sara);
        $this->postJson($this->url(self::FREELANCER, "/{$uuid}/status"), ['status' => 'doing'])->assertOk()->assertJsonPath('data.summary.doing', 1);
        $this->postJson($this->url(self::FREELANCER, "/{$uuid}/status"), ['status' => 'done'])->assertOk()->assertJsonPath('data.summary.done', 1)->assertJsonPath('data.summary.doing', 0);
        $this->assertNotNull(FreelanceWorkroomTask::query()->where('uuid', $uuid)->value('completed_at'));
        // The same status again changes nothing and says nothing.
        $this->postJson($this->url(self::FREELANCER, "/{$uuid}/status"), ['status' => 'done'])->assertOk();
        $this->postJson($this->url(self::FREELANCER, "/{$uuid}/status"), ['status' => 'todo'])->assertOk();
        $this->assertNull(FreelanceWorkroomTask::query()->where('uuid', $uuid)->value('completed_at'));
        $this->postJson($this->url(self::FREELANCER, "/{$uuid}/status"), ['status' => 'finished'])->assertUnprocessable();

        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 4); // created, doing, done, todo
    }

    public function test_every_step_is_on_the_project_record_with_who_took_it(): void
    {
        $this->active();
        $uuid = $this->clientTask(['title' => 'Send the brand colours']);
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['description' => 'Both hex codes'])->assertOk();
        $this->postJson($this->url(self::CLIENT, "/{$uuid}/status"), ['status' => 'doing'])->assertOk();
        $this->postJson($this->url(self::CLIENT, "/{$uuid}/status"), ['status' => 'done'])->assertOk();
        $this->deleteJson($this->url(self::CLIENT, "/{$uuid}"))->assertOk();

        // They are the Workroom's record, not the project's own history the client's page lists.
        $types = collect($this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->json('data.timeline'))->pluck('type');
        $this->assertNotEmpty($types);
        $this->assertTrue($types->every(fn ($t) => ! str_starts_with((string) $t, 'workroom.')));

        $rows = FreelanceProjectEvent::query()->where('project_id', $this->project->id)->where('type', 'like', 'workroom.%')->orderBy('id')->get();
        $this->assertSame(['workroom.task.created', 'workroom.task.updated', 'workroom.task.status', 'workroom.task.completed', 'workroom.task.deleted'], $rows->pluck('type')->all());
        $this->assertSame(['client'], $rows->pluck('actor_type')->unique()->values()->all());
        $this->assertSame($this->owner->id, (int) $rows->first()->actor_id);
        $this->assertSame($uuid, $rows->first()->payload['task']);
        $this->assertSame(['from' => 'doing', 'to' => 'done'], array_intersect_key($rows[3]->payload, ['from' => 1, 'to' => 1]));
    }

    public function test_the_title_and_the_day_are_checked(): void
    {
        $this->active();
        $this->as($this->owner);
        $this->postJson($this->url(self::CLIENT), [])->assertUnprocessable();
        $this->postJson($this->url(self::CLIENT), ['title' => 'ab'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_title_required');
        $this->postJson($this->url(self::CLIENT), ['title' => '<b></b>'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_title_required');
        $this->postJson($this->url(self::CLIENT), ['title' => str_repeat('a', 161)])->assertUnprocessable();
        $this->postJson($this->url(self::CLIENT), ['title' => 'Check it', 'assignee_side' => 'admin'])->assertUnprocessable();
        $this->postJson($this->url(self::CLIENT), ['title' => 'Check it', 'due_on' => '03/12/2026'])->assertUnprocessable();
        $this->postJson($this->url(self::CLIENT), ['title' => 'Check it', 'due_on' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_due_past');

        // Tags are stripped; a day today is fine.
        $this->postJson($this->url(self::CLIENT), ['title' => '<script>x</script>Check <i>it</i>', 'due_on' => now()->toDateString()])->assertCreated()->assertJsonPath('data.task.title', 'xCheck it');

        // A day that has passed stays on a task that already had it; a new past day is refused.
        $uuid = $this->clientTask(['title' => 'Send the logo', 'due_on' => now()->addDay()->toDateString()]);
        Carbon::setTestNow(now()->addDays(5));
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['title' => 'Send the logo file'])->assertOk()->assertJsonPath('data.task.overdue', true);
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['due_on' => now()->subDay()->toDateString()])->assertStatus(422);
        $this->putJson($this->url(self::CLIENT, "/{$uuid}"), ['due_on' => null])->assertOk()->assertJsonPath('data.task.due_on', null)->assertJsonPath('data.task.overdue', false);
    }

    public function test_the_admins_cap_stops_new_tasks_only(): void
    {
        $this->active();
        $this->as($this->owner);
        // No cap at first.
        $this->getJson($this->url(self::CLIENT))->assertOk()->assertJsonPath('data.limit.max', null);
        foreach (range(1, 3) as $i) {
            $this->clientTask(['title' => "Task number {$i}"]);
        }

        app(FreelancePolicies::class)->save($this->saudi->id, null, ['workroom.tasks_max' => 3]);
        FreelancePolicies::forget();
        $this->as($this->owner);
        $this->getJson($this->url(self::CLIENT))->assertOk()->assertJsonPath('data.limit.max', 3)->assertJsonCount(3, 'data.tasks');
        $this->postJson($this->url(self::CLIENT), ['title' => 'One too many'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_tasks_limit')->assertJsonPath('data.max', 3);
        $this->as($this->sara);
        $this->postJson($this->url(self::FREELANCER), ['title' => 'One too many'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_tasks_limit');

        // A lower cap keeps what is there: changing and deleting still work, and a place frees up when one is deleted.
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['workroom.tasks_max' => 1]);
        FreelancePolicies::forget();
        $this->as($this->owner);
        $first = FreelanceWorkroomTask::query()->orderBy('id')->first();
        $this->putJson($this->url(self::CLIENT, "/{$first->uuid}"), ['title' => 'Still here'])->assertOk();
        $this->getJson($this->url(self::CLIENT))->assertJsonCount(3, 'data.tasks');
        $this->postJson($this->url(self::CLIENT), ['title' => 'Over the cap'])->assertStatus(422);
        foreach (FreelanceWorkroomTask::query()->orderBy('id')->get() as $task) {
            $this->deleteJson($this->url(self::CLIENT, "/{$task->uuid}"))->assertOk();
        }
        $this->clientTask(['title' => 'Room again']);

        // An empty value in the admin's form means no cap again.
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['workroom.tasks_max' => null]);
        FreelancePolicies::forget();
        $this->as($this->owner);
        $this->getJson($this->url(self::CLIENT))->assertJsonPath('data.limit.max', null);
    }

    public function test_the_cap_is_validated_by_the_policy(): void
    {
        $this->expectException(\Modules\Freelance\Exceptions\FreelanceException::class);
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['workroom.tasks_max' => 0]);
    }

    public function test_the_workroom_exists_while_the_agreement_is_active_and_is_read_only_after_it(): void
    {
        // Not an agreement yet: nothing to open (the client chose nobody; Sara has no agreement).
        $this->as($this->owner);
        $this->getJson($this->url(self::CLIENT))->assertNotFound();
        $this->as($this->sara);
        $this->getJson($this->url(self::FREELANCER))->assertNotFound();

        $this->active();
        $uuid = $this->clientTask(['title' => 'Send the brand colours']);
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::COMPLETED]);

        foreach ([[self::CLIENT, $this->owner], [self::FREELANCER, $this->sara]] as [$prefix, $who]) {
            $this->as($who);
            $this->getJson($this->url($prefix))->assertOk()->assertJsonPath('data.read_only', true)->assertJsonPath('data.can.task_create', false)
                ->assertJsonPath('data.tasks.0.can.task_edit', false)->assertJsonPath('data.tasks.0.can.task_status', false)->assertJsonPath('data.tasks.0.can.task_delete', false);
            $this->postJson($this->url($prefix), ['title' => 'Too late now'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_workroom_not_active');
            $this->putJson($this->url($prefix, "/{$uuid}"), ['title' => 'Too late now'])->assertStatus(409);
            $this->postJson($this->url($prefix, "/{$uuid}/status"), ['status' => 'done'])->assertStatus(409);
            $this->deleteJson($this->url($prefix, "/{$uuid}"))->assertStatus(409);
        }
        $this->assertSame(1, FreelanceWorkroomTask::query()->count());

        // A cancelled agreement has no Workroom to show.
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::CANCELLED]);
        $this->as($this->owner);
        $this->getJson($this->url(self::CLIENT))->assertNotFound();
    }

    public function test_only_the_two_sides_of_the_agreement_reach_the_workroom(): void
    {
        $this->active();
        $uuid = $this->clientTask();

        // A freelancer who did not win the project.
        $this->as($this->lina);
        $this->getJson($this->url(self::FREELANCER))->assertNotFound();
        $this->postJson($this->url(self::FREELANCER), ['title' => 'Let me in'])->assertNotFound();
        $this->postJson($this->url(self::FREELANCER, "/{$uuid}/status"), ['status' => 'done'])->assertNotFound();

        // A client who is not of the project, and a person who is not on its team.
        $stranger = $this->user('Zed', '+966500000077');
        $this->as($stranger);
        $this->getJson($this->url(self::CLIENT))->assertNotFound();
        $this->postJson($this->url(self::CLIENT), ['title' => 'Let me in'])->assertNotFound();
        $this->assertSame(1, FreelanceWorkroomTask::query()->count());

        // A signed-out call.
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url(self::CLIENT))->assertUnauthorized();
    }

    public function test_the_list_filters_counts_and_pages(): void
    {
        $this->active();
        $a = $this->clientTask(['title' => 'Late one', 'due_on' => now()->toDateString()]);
        $this->clientTask(['title' => 'Later one', 'due_on' => now()->addDays(9)->toDateString()]);
        $this->clientTask(['title' => 'No day']);
        $forSara = $this->clientTask(['title' => 'For Sara', 'assignee_side' => 'freelancer']);
        $this->postJson($this->url(self::CLIENT, "/{$a}/status"), ['status' => 'doing'])->assertOk();
        $this->postJson($this->url(self::CLIENT, "/{$forSara}/status"), ['status' => 'done'])->assertOk();
        Carbon::setTestNow(now()->addDays(2));

        $this->getJson($this->url(self::CLIENT))->assertOk()->assertJsonPath('data.summary', ['total' => 4, 'todo' => 2, 'doing' => 1, 'done' => 1, 'blocked' => 0, 'overdue' => 1])
            // What is being worked on first, then what waits (the nearest day first, no day last), done last.
            ->assertJsonPath('data.tasks.0.title', 'Late one')->assertJsonPath('data.tasks.1.title', 'Later one')->assertJsonPath('data.tasks.2.title', 'No day')->assertJsonPath('data.tasks.3.title', 'For Sara');
        $this->getJson($this->url(self::CLIENT).'?status=todo')->assertJsonCount(2, 'data.tasks');
        $this->getJson($this->url(self::CLIENT).'?overdue=1')->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.title', 'Late one');
        $this->getJson($this->url(self::CLIENT).'?assignee=freelancer')->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.title', 'For Sara');
        $this->getJson($this->url(self::CLIENT).'?mine=1')->assertJsonCount(3, 'data.tasks');
        $this->getJson($this->url(self::CLIENT).'?status=late')->assertUnprocessable();
        $this->getJson($this->url(self::CLIENT).'?per_page=2')->assertOk()->assertJsonCount(2, 'data.tasks')->assertJsonPath('pagination.total', 4)->assertJsonPath('pagination.has_more_pages', true);
        $this->getJson($this->url(self::CLIENT).'?per_page=500')->assertUnprocessable();
    }

    public function test_a_member_of_the_clients_team_reads_and_a_manager_works_on_the_tasks(): void
    {
        $this->active();
        $this->join($this->omar, 'manager');
        $this->join($this->huda, 'collaborator');
        $forClient = $this->freelancerTask(['title' => 'Approve the wireframes', 'assignee_side' => 'client']);
        // The owner and the manager (who can work on tasks) are told; the collaborator (who only follows) is not.
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());
        $this->assertSame(1, $this->omar->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());
        $this->assertSame(0, $this->huda->notifications()->where('data->event', 'freelance.workroom.task_assigned')->count());

        // The collaborator reads, and sees every button off.
        $this->as($this->huda);
        $this->getJson($this->url(self::CLIENT))->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.can.task_create', false)
            ->assertJsonPath('data.tasks.0.can.task_status', false)->assertJsonPath('data.tasks.0.can.task_edit', false);
        $this->postJson($this->url(self::CLIENT), ['title' => 'Let me add one'])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->postJson($this->url(self::CLIENT, "/{$forClient}/status"), ['status' => 'done'])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->deleteJson($this->url(self::CLIENT, "/{$forClient}"))->assertForbidden();

        // The manager works on them as the owner and the record says who.
        $this->as($this->omar);
        $this->getJson($this->url(self::CLIENT))->assertOk()->assertJsonPath('data.can.task_create', true)->assertJsonPath('data.tasks.0.can.task_status', true);
        $this->postJson($this->url(self::CLIENT, "/{$forClient}/status"), ['status' => 'done'])->assertOk()->assertJsonPath('data.task.status', 'done');
        $mine = (string) $this->postJson($this->url(self::CLIENT), ['title' => 'Send the logo'])->assertCreated()->assertJsonPath('data.task.can.task_edit', true)->json('data.task.uuid');
        $this->putJson($this->url(self::CLIENT, "/{$mine}"), ['title' => 'Send the logo file'])->assertOk();

        $created = FreelanceProjectEvent::query()->where('type', 'workroom.task.created')->where('actor_type', 'client')->firstOrFail();
        $this->assertSame($this->owner->id, (int) $created->actor_id);
        $this->assertSame($this->omar->id, (int) $created->acted_by_user_id);
        $done = FreelanceProjectEvent::query()->where('type', 'workroom.task.completed')->firstOrFail();
        $this->assertSame($this->omar->id, (int) $done->acted_by_user_id);
        // The task is the owner's (the client side); the owner moves it as well, and Sara sees the client's side as one.
        $this->as($this->owner);
        $this->getJson($this->url(self::CLIENT))->assertJsonPath('data.summary.total', 2);
        $this->as($this->sara);
        $this->getJson($this->url(self::FREELANCER).'?assignee=client')->assertJsonCount(2, 'data.tasks');
    }

    public function test_a_person_who_left_the_team_no_longer_reaches_the_workroom(): void
    {
        $this->active();
        $member = $this->join($this->omar, 'manager');
        $this->as($this->omar);
        $this->getJson($this->url(self::CLIENT))->assertOk();
        $this->postJson(self::TEAM."/projects/{$this->project->uuid}/leave")->assertOk();
        $this->getJson($this->url(self::CLIENT))->assertNotFound();
        $this->assertSame('left', $member->refresh()->status);
    }

    public function test_the_live_channel_is_for_the_people_of_the_project_only(): void
    {
        $this->active();
        $this->join($this->omar, 'collaborator');
        $access = app(WorkroomAccess::class);
        $uuid = $this->project->uuid;

        $this->assertTrue($access->listens($this->owner->refresh(), $uuid));
        $this->assertTrue($access->listens($this->omar->refresh(), $uuid));
        $this->assertTrue($access->listens($this->sara->refresh(), $uuid));
        $this->assertFalse($access->listens($this->huda->refresh(), $uuid));
        $this->assertFalse($access->listens($this->lina->refresh(), $uuid));
        $this->assertFalse($access->listens($this->owner->refresh(), (string) \Illuminate\Support\Str::uuid()));
        $this->assertFalse($access->listens(null, $uuid));

        // Sara listens while the agreement is active or completed, no longer once it is cancelled.
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::COMPLETED]);
        $this->assertTrue($access->listens($this->sara->refresh(), $uuid));
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::CANCELLED]);
        $this->assertFalse($access->listens($this->sara->refresh(), $uuid));
    }

    public function test_the_notification_texts_exist_in_both_languages(): void
    {
        foreach (['freelance_client_workroom_task_assigned', 'freelance_freelancer_workroom_task_assigned'] as $key) {
            foreach (['ar', 'en'] as $locale) {
                foreach (['title', 'body'] as $part) {
                    $this->assertNotSame("notifications.{$key}_{$part}", __("notifications.{$key}_{$part}", [], $locale), "{$locale} {$key}_{$part}");
                }
            }
        }
        foreach (['workroom_not_active', 'workroom_forbidden', 'workroom_tasks_limit', 'workroom_title_required', 'workroom_due_past', 'workroom_milestone_invalid'] as $code) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertNotSame("freelance.errors.{$code}", __("freelance.errors.{$code}", [], $locale), "{$locale} {$code}");
            }
        }
    }
}
