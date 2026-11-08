<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Events\FreelanceTeamWorkroomChanged;
use Modules\Freelance\Events\FreelanceWorkroomChanged;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamProjects;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-207: the team's own space in the Workroom (tasks, files and notes the client never sees) and what each member carries
 * (the workload: an Owner or a PM reads everybody, a member his own row). Team items are the freelancers team only: tasks, files and notes
 * never reach the client (lists, downloads, timeline, live events) and are not evidence in a dispute.
 */
class FreelanceTeamWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private FreelanceTeam $team;

    private FreelanceProject $project;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $huda;

    private Freelancer $omar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'commission', 'teams', 'workroom'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['commission.type' => 'percent', 'commission.value' => 1000]);
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        $this->sara = $this->freelancer('+966500000091', 'Sara Ali');
        $this->lina = $this->freelancer('+966500000092', 'Lina Hassan');
        $this->noor = $this->freelancer('+966500000093', 'Noor Said');
        $this->huda = $this->freelancer('+966500000094', 'Huda Omar');
        $this->omar = $this->freelancer('+966500000095', 'Omar Khaled'); // not in the team
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
        $teams->join($this->team, $this->huda); // a member who does not work on the project

        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->activate();
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    /** Sara sends the team's offer (Lina PM, Noor on it), the client chooses it and Sara approves the agreement. */
    private function activate(): void
    {
        $p = $this->project;
        $this->as($this->sara);
        $uuid = $this->postJson(self::API."/projects/{$p->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'We built three booking sites like this; we will deliver in three steps with a demo each week.',
            'team' => $this->team->uuid,
        ])->assertCreated()->json('data.uuid');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/offers/{$uuid}/choose")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/agreement/approve")->assertOk()->assertJsonPath('data.status', 'active');
        // FS-213: the offer names nobody any more - Noor works on the project by being put on its part (FS-206b); Lina is the team's PM.
        $agreement = \Modules\Freelance\Models\FreelanceAgreement::query()->where('project_id', $p->id)->latest('id')->firstOrFail();
        app(\Modules\Freelance\Services\FreelancerTeam\TeamAssignments::class)->setMilestone($agreement, $agreement->milestones()->orderBy('position')->firstOrFail(), [$this->noor->uuid], $this->sara);
    }

    private function fund(): FreelanceMilestone
    {
        $m = FreelanceMilestone::query()->firstOrFail();
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $w->credit($w->firstOrCreateWallet($this->client, $this->saudi), $m->amount_minor + $m->client_fee_minor, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
        app(\Modules\Freelance\Services\Projects\MilestonePayments::class)->fund($m->load('agreement'), $this->client);

        return $m->refresh();
    }

    private function url(string $suffix = ''): string
    {
        return self::API."/projects/{$this->project->uuid}{$suffix}";
    }

    private function part(): FreelanceMilestone
    {
        return FreelanceMilestone::query()->firstOrFail();
    }

    private function pdf(string $name = 'notes.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
    }

    private function clientUrl(string $suffix): string
    {
        return self::CLIENT."/{$this->project->uuid}/workroom".$suffix;
    }

    public function test_a_team_task_is_hidden_from_the_client_and_never_goes_to_him(): void
    {
        $this->as($this->lina);
        $team = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Internal: check the prices', 'scope' => 'team', 'assignee_side' => 'client'])->assertCreated()
            ->assertJsonPath('data.task.scope', 'team')->assertJsonPath('data.task.assignee_side', 'freelancer')->json('data.task.uuid');
        $shared = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Send the logo'])->assertCreated()->assertJsonPath('data.task.scope', 'shared')->json('data.task.uuid');

        $this->as($this->noor); // the team reads and works on it
        $this->getJson($this->url('/workroom/tasks'))->assertOk()->assertJsonCount(2, 'data.tasks')->assertJsonPath('data.summary.total', 2);
        $this->postJson($this->url("/workroom/tasks/{$team}/status"), ['status' => 'doing'])->assertOk();

        $this->assertSame('team', DB::table('freelance_project_events')->where('type', 'workroom.task.created')->orderBy('id')->value('audience'));
        $this->asClient();
        $this->getJson($this->clientUrl('/tasks'))->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.uuid', $shared)->assertJsonPath('data.summary.total', 1);
        $this->postJson($this->clientUrl("/tasks/{$team}/status"), ['status' => 'done'])->assertNotFound();
        $titles = collect($this->getJson($this->clientUrl('/timeline'))->assertOk()->json('data.events'))->pluck('subject.title')->all();
        $this->assertNotContains('Internal: check the prices', $titles);
        // The client cannot make a team item, and a solo freelancer has no team to keep one for.
        $this->postJson($this->clientUrl('/tasks'), ['title' => 'Mine', 'scope' => 'team'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_scope_invalid');
        // A team task stays the team's: it cannot be handed to the client.
        $this->as($this->lina);
        $this->putJson($this->url("/workroom/tasks/{$team}"), ['assignee_side' => 'client'])->assertOk()->assertJsonPath('data.task.assignee_side', 'freelancer');
    }

    public function test_a_team_file_and_its_notes_stay_inside_the_team(): void
    {
        Storage::fake('local');
        $this->as($this->lina);
        $file = $this->postJson($this->url('/workroom/files'), ['file' => $this->pdf('costs.pdf'), 'title' => 'Our costs', 'scope' => 'team'])->assertCreated()
            ->assertJsonPath('data.file.scope', 'team')->json('data.file.uuid');
        $open = $this->postJson($this->url('/workroom/files'), ['file' => $this->pdf('demo.pdf'), 'title' => 'Demo'])->assertCreated()->assertJsonPath('data.file.scope', 'shared')->json('data.file.uuid');
        $this->postJson($this->url('/workroom/comments'), ['file' => $file, 'body' => 'Keep this from the client'])->assertCreated()->assertJsonPath('data.comment.scope', 'team');

        $this->as($this->noor);
        $this->getJson($this->url('/workroom/files'))->assertOk()->assertJsonCount(2, 'data.files');
        $this->getJson($this->url('/workroom/comments').'?file='.$file)->assertOk()->assertJsonCount(1, 'data.comments');

        $this->asClient();
        $this->getJson($this->clientUrl('/files'))->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.uuid', $open);
        $this->getJson($this->clientUrl("/files/{$file}"))->assertNotFound();
        $this->getJson($this->clientUrl("/files/{$file}/versions/1/download"))->assertNotFound();
        $this->getJson($this->clientUrl('/comments').'?file='.$file)->assertNotFound();
        $this->postJson($this->clientUrl('/comments'), ['file' => $file, 'body' => 'Can I see?'])->assertNotFound();
        $this->postJson($this->clientUrl('/files'), ['file' => $this->pdf(), 'scope' => 'team'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_scope_invalid');
        $types = DB::table('freelance_project_events')->whereIn('type', ['workroom.file.uploaded', 'workroom.comment.created'])->orderBy('id')->pluck('audience')->all();
        $this->assertSame(['team', 'both', 'team'], $types);
    }

    public function test_a_team_item_goes_live_to_the_team_channel_and_never_to_the_clients(): void
    {
        Storage::fake('local');
        Event::fake([FreelanceWorkroomChanged::class, FreelanceTeamWorkroomChanged::class]);
        $this->as($this->lina);
        $task = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Internal: check the prices', 'scope' => 'team'])->assertCreated()->json('data.task.uuid');
        $file = $this->postJson($this->url('/workroom/files'), ['file' => $this->pdf('costs.pdf'), 'title' => 'Our costs', 'scope' => 'team'])->assertCreated()->json('data.file.uuid');
        $this->postJson($this->url('/workroom/comments'), ['file' => $file, 'body' => 'Keep this from the client'])->assertCreated();
        $this->postJson($this->url('/workroom/tasks'), ['title' => 'Send the logo'])->assertCreated();

        // The project's channel (the client listens to it) hears the shared task only; the team's channel hears the three inside steps.
        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 1);
        Event::assertDispatchedTimes(FreelanceTeamWorkroomChanged::class, 3);
        Event::assertDispatched(FreelanceTeamWorkroomChanged::class, fn (FreelanceTeamWorkroomChanged $e) => $e->teamUuid === $this->team->uuid && $e->projectUuid === $this->project->uuid
            && $e->kind === 'task' && $e->action === 'created' && $e->uuid === $task && $e->by === 'freelancer'
            && $e->broadcastAs() === 'freelance.workroom' && $e->broadcastOn()->name === 'private-freelance-team.'.$this->team->uuid
            && array_keys($e->broadcastWith()) === ['project', 'kind', 'action', 'uuid', 'by']);
        Event::assertDispatched(FreelanceTeamWorkroomChanged::class, fn (FreelanceTeamWorkroomChanged $e) => $e->kind === 'file' && $e->uuid === $file);
        Event::assertDispatched(FreelanceTeamWorkroomChanged::class, fn (FreelanceTeamWorkroomChanged $e) => $e->kind === 'comment' && $e->uuid === $file);
    }

    public function test_the_workload_shows_what_each_member_carries(): void
    {
        $m = $this->part();
        $this->as($this->lina);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->noor->uuid]])->assertOk();
        $a = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Build the page', 'assignee' => $this->noor->uuid])->assertCreated()->json('data.task.uuid');
        $b = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Fix the form', 'assignee' => $this->noor->uuid, 'scope' => 'team'])->assertCreated()->json('data.task.uuid');
        $c = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Old task', 'assignee' => $this->noor->uuid])->assertCreated()->json('data.task.uuid');
        DB::table('freelance_workroom_tasks')->where('uuid', $b)->update(['due_on' => now()->subDays(2)->toDateString(), 'status' => 'blocked']);
        $this->postJson($this->url("/workroom/tasks/{$c}/status"), ['status' => 'done'])->assertOk();

        $rows = collect($this->getJson(self::API."/teams/{$this->team->uuid}/workload")->assertOk()->json('data.members'))->keyBy('freelancer.name');
        $this->assertCount(4, $rows);
        $this->assertSame(['projects' => 1, 'parts_open' => 1, 'tasks_open' => 2, 'tasks_overdue' => 1, 'tasks_blocked' => 1, 'tasks_done' => 1], collect($rows['Noor Said'])->only(['projects', 'parts_open', 'tasks_open', 'tasks_overdue', 'tasks_blocked', 'tasks_done'])->all());
        $this->assertSame(0, $rows['Sara Ali']['tasks_open']);
        $this->assertSame('owner', $rows['Sara Ali']['role']);

        $this->as($this->noor); // a plain member reads his own row only
        $mine = $this->getJson(self::API."/teams/{$this->team->uuid}/workload")->assertOk()->assertJsonCount(1, 'data.members')->json('data.members.0');
        $this->assertSame('Noor Said', $mine['freelancer']['name']);
        $this->assertSame($a !== '' ? 2 : 0, $mine['tasks_open']);

        $this->as($this->omar); // outside the team
        $this->getJson(self::API."/teams/{$this->team->uuid}/workload")->assertNotFound();
    }
}
