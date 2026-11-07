<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
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
 * FS-206b: an Owner or a PM puts people of the team on a part (and gives them tasks); the person put on a part or a task reaches the project
 * (D9), a member starts the part he is on, a plain member takes a task for himself only, and leaving the team lets all of it go at once (D5).
 * The client never reads any of it.
 */
class FreelanceTeamAssignmentsTest extends TestCase
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
            'team' => $this->team->uuid, 'staff' => [['freelancer' => $this->lina->uuid, 'role' => 'pm'], ['freelancer' => $this->noor->uuid, 'role' => 'executor', 'responsibility' => 'Screens']],
        ])->assertCreated()->json('data.uuid');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/offers/{$uuid}/choose")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/agreement/approve")->assertOk()->assertJsonPath('data.status', 'active');
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

    public function test_a_pm_puts_people_on_a_part_and_they_reach_the_project(): void
    {
        $m = $this->part();
        $this->as($this->huda);
        $this->getJson($this->url('/agreement'))->assertNotFound(); // a member who does not work on it (D9)

        $this->as($this->lina);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->huda->uuid]])->assertOk()
            ->assertJsonPath('data.assignees.0.uuid', $this->huda->uuid);
        $this->getJson($this->url("/milestones/{$m->uuid}/assignees"))->assertOk()->assertJsonCount(1, 'data.assignees');
        $this->assertSame($this->lina->id, (int) DB::table('freelance_milestone_assignees')->value('assigned_by_freelancer_id'));

        $this->as($this->huda); // now on it: she reads the project
        $this->getJson($this->url('/agreement'))->assertOk()->assertJsonPath('data.team_access.role', 'member')
            ->assertJsonPath('data.milestones.0.assignees.0.uuid', $this->huda->uuid); // FS-209b: the parts carry who is on them
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => []])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_project_forbidden');

        $this->as($this->sara); // the signing Owner sets it too; taking everybody off closes the door again
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => []])->assertOk()->assertJsonCount(0, 'data.assignees');
        $this->as($this->huda);
        $this->getJson($this->url('/agreement'))->assertNotFound();
    }

    public function test_only_active_members_of_the_team_can_be_put_on_a_part_and_the_client_never_sees_it(): void
    {
        $m = $this->part();
        $this->as($this->lina);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->omar->uuid]])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_assignee_invalid');
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->noor->uuid, $this->noor->uuid]])->assertOk()->assertJsonCount(1, 'data.assignees');

        $this->assertSame('team', DB::table('freelance_project_events')->where('type', 'team.milestone.assignees')->value('audience'));
        $this->asClient();
        $types = collect($this->getJson(self::CLIENT."/{$this->project->uuid}/workroom/timeline")->assertOk()->json('data.events'))->pluck('type')->all();
        $this->assertNotContains('team.milestone.assignees', $types);
        // FS-209b: nor does the client's agreement page carry them.
        $this->assertArrayNotHasKey('assignees', $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertOk()->json('data.milestones.0'));
    }

    public function test_a_member_starts_only_the_part_he_is_put_on(): void
    {
        $m = $this->fund();
        $this->as($this->noor); // on the offer staff, not put on the part
        $this->postJson($this->url("/milestones/{$m->uuid}/start"))->assertForbidden()->assertJsonPath('error_code', 'freelance_team_project_forbidden');

        $this->as($this->lina);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->noor->uuid]])->assertOk();
        $this->as($this->noor);
        $this->getJson($this->url('/agreement'))->assertOk()->assertJsonPath('data.milestones.0.can.start', true);
        $this->postJson($this->url("/milestones/{$m->uuid}/start"))->assertOk();
        $row = DB::table('freelance_milestone_events')->where('type', 'milestone.started')->first();
        $this->assertSame($this->noor->id, (int) $row->acted_by_freelancer_id);
        $this->postJson($this->url("/milestones/{$m->uuid}/deliver"), ['note' => 'Done.'])->assertForbidden(); // delivering stays the Owner (D11)
    }

    public function test_tasks_can_be_given_to_a_person_of_the_team(): void
    {
        $this->as($this->lina);
        $uuid = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Design the screens', 'assignee' => $this->noor->uuid])->assertCreated()
            ->assertJsonPath('data.task.assignee.uuid', $this->noor->uuid)->assertJsonPath('data.task.assignee.name', 'Noor Said')->assertJsonPath('data.task.assignee_side', 'freelancer')->json('data.task.uuid');
        $this->postJson($this->url('/workroom/tasks'), ['title' => 'Somebody else', 'assignee' => $this->omar->uuid])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_assignee_invalid');

        $this->as($this->noor); // a plain member takes a task for himself only
        $this->getJson($this->url('/workroom/tasks'))->assertOk()->assertJsonPath('data.tasks.0.assignee.uuid', $this->noor->uuid);
        $this->postJson($this->url("/workroom/tasks/{$uuid}/status"), ['status' => 'doing'])->assertOk();
        $this->postJson($this->url('/workroom/tasks'), ['title' => 'For Lina', 'assignee' => $this->lina->uuid])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_assign_forbidden');
        $this->postJson($this->url('/workroom/tasks'), ['title' => 'My own task', 'assignee' => $this->noor->uuid])->assertCreated();

        $this->as($this->lina);
        $this->putJson($this->url("/workroom/tasks/{$uuid}"), ['assignee' => $this->huda->uuid])->assertOk()->assertJsonPath('data.task.assignee.uuid', $this->huda->uuid);
        $this->putJson($this->url("/workroom/tasks/{$uuid}"), ['assignee_side' => 'client'])->assertOk()->assertJsonPath('data.task.assignee', null);

        $this->asClient(); // the client cannot hand a task to the team people, and reads the first name only
        $this->postJson(self::CLIENT."/{$this->project->uuid}/workroom/tasks", ['title' => 'Mine', 'assignee' => $this->noor->uuid])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_assignee_invalid');
        $names = collect($this->getJson(self::CLIENT."/{$this->project->uuid}/workroom/tasks")->assertOk()->json('data.tasks'))->pluck('assignee.name')->filter()->all();
        $this->assertSame(['Noor'], array_values($names));
    }

    public function test_leaving_the_team_lets_everything_go_at_once(): void
    {
        $m = $this->part();
        $this->as($this->lina);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->huda->uuid, $this->noor->uuid]])->assertOk();
        $task = $this->postJson($this->url('/workroom/tasks'), ['title' => 'Write the copy', 'assignee' => $this->huda->uuid])->assertCreated()->json('data.task.uuid');

        $this->as($this->huda);
        $this->postJson(self::API."/teams/{$this->team->uuid}/leave")->assertOk();

        $this->assertSame([$this->noor->id], DB::table('freelance_milestone_assignees')->pluck('freelancer_id')->map(fn ($i) => (int) $i)->all());
        $this->assertNull(DB::table('freelance_workroom_tasks')->where('uuid', $task)->value('assignee_freelancer_id'));
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.team_assignments_released')->count());
        $this->assertSame(0, $this->lina->notifications()->where('data->event', 'freelance.team_assignments_released')->count()); // Owners only
    }

    public function test_a_removed_member_lets_go_too_and_nobody_is_put_on_after_the_agreement_ends(): void
    {
        $m = $this->part();
        $this->as($this->sara);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->noor->uuid]])->assertOk();
        $this->deleteJson(self::API."/teams/{$this->team->uuid}/members/{$this->noor->uuid}")->assertOk();
        $this->assertSame(0, DB::table('freelance_milestone_assignees')->count());

        DB::table('freelance_agreements')->update(['status' => 'completed']);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->huda->uuid]])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_assign_closed');
    }
}
