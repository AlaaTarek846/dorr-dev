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
 * FS-206a: the people of a freelancers' team reach the team's project through the ordinary project routes, acting for the Owner who signed
 * it - an Owner for everything on the work (the delivery included, D11), a PM for reading, the Workroom, starting a part and reports, a member
 * only on a project he works on (D9) for reading and the Workroom. Amounts follow `view_price`, the net is the Owners'. Every record row
 * keeps who really acted; a route the map does not know stays the signer's; a team not active, or `teams` off, lets only the signer in.
 */
class FreelanceTeamProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    /** The project routes only the signing Owner uses (his signature, his rating): never listed in the team's map. */
    private const SIGNER_ONLY = [
        'FreelancerAgreementController@approve', 'FreelancerAgreementController@decline',
        'ReviewController@store', 'ReviewController@object', 'ReviewController@survey', 'ReviewController@answerSurvey',
    ];

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

    public function test_every_project_route_is_in_the_teams_map_or_left_to_the_signer(): void
    {
        $known = array_merge(TeamProjects::mapped(), self::SIGNER_ONLY);
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/freelance/v1/projects/{uuid}')) {
                continue;
            }
            $this->assertContains('freelance.team-project-access', $route->gatherMiddleware(), $route->uri().' runs without the team check');
            $action = $route->getActionName();
            $key = class_basename(explode('@', $action)[0]).'@'.(explode('@', $action)[1] ?? '');
            if (! in_array($key, $known, true)) {
                $missing[] = $key.' ('.$route->uri().')';
            }
        }
        $this->assertSame([], $missing, 'a project route must say what the team needs for it (TeamProjects::ROUTES) or be the signer\'s alone');
    }

    public function test_the_signer_sees_his_place_and_the_pm_works_for_him_without_the_net(): void
    {
        $this->getJson($this->url('/agreement'))->assertOk()->assertJsonPath('data.team_access.signer', true)->assertJsonPath('data.team_access.role', 'owner')
            ->assertJsonPath('data.money.net_minor', 315000);

        $this->as($this->lina);
        $this->getJson($this->url('/agreement'))->assertOk()
            ->assertJsonPath('data.team_access.role', 'pm')->assertJsonPath('data.team_access.signer', false)->assertJsonPath('data.team_access.team.name', 'Dorr Studio')
            ->assertJsonPath('data.price_minor', 350000)->assertJsonPath('data.money.price_minor', 350000) // `view_price` is on for a PM by default
            ->assertJsonPath('data.money.net_minor', null)->assertJsonPath('data.money.commission_minor', null)->assertJsonPath('data.money.lines', [])
            ->assertJsonPath('data.can.approve', false)->assertJsonPath('data.can.decline', false);

        // The Workroom: the task is written for the Owner, and the record keeps who did it.
        $this->postJson($this->url('/workroom/tasks'), ['title' => 'Build the booking page'])->assertCreated();
        $event = DB::table('freelance_project_events')->where('type', 'workroom.task.created')->first();
        $this->assertSame($this->sara->id, (int) $event->actor_id);
        $this->assertSame($this->lina->id, (int) $event->acted_by_freelancer_id);
        $this->getJson($this->url('/workroom/timeline'))->assertOk()->assertJsonPath('data.events.0.by.name', 'Lina Hassan');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/workroom/timeline")->assertOk()->assertJsonPath('data.events.0.by.name', 'Lina');

        // A PM starts a part; he never delivers it (D11) nor signs.
        $m = $this->fund();
        $this->as($this->lina);
        $this->postJson($this->url("/milestones/{$m->uuid}/start"))->assertOk()->assertJsonPath('data.milestones.0.status', 'in_progress')
            ->assertJsonPath('data.milestones.0.can.deliver', false);
        $this->assertSame($this->lina->id, (int) DB::table('freelance_milestone_events')->where('type', 'milestone.started')->value('acted_by_freelancer_id'));
        $this->postJson($this->url("/milestones/{$m->uuid}/deliver"), ['note' => 'Done here.'])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_project_forbidden');
        $this->postJson($this->url("/milestones/{$m->uuid}/withdraw"))->assertForbidden();
        $this->postJson($this->url('/agreement/approve'))->assertForbidden();
        $this->postJson($this->url("/milestones/{$m->uuid}/dispute"), ['reason' => 'x'])->assertForbidden();
        $this->assertSame('in_progress', (string) DB::table('freelance_milestones')->where('id', $m->id)->value('status'));
    }

    public function test_a_member_reaches_only_a_project_he_works_on_and_sees_the_price_only_when_switched_on(): void
    {
        $this->as($this->noor);
        $this->getJson($this->url('/agreement'))->assertOk()->assertJsonPath('data.team_access.role', 'member')
            ->assertJsonPath('data.price_minor', null)->assertJsonPath('data.money.price_minor', null)->assertJsonPath('data.money.net_minor', null);
        $this->postJson($this->url('/workroom/tasks'), ['title' => 'My screens'])->assertCreated();
        $m = $this->fund();
        $this->as($this->noor);
        $this->postJson($this->url("/milestones/{$m->uuid}/start"))->assertForbidden()->assertJsonPath('error_code', 'freelance_team_project_forbidden');

        // The Owner switches the price on for him.
        Member::query()->where('freelancer_id', $this->noor->id)->update(['permissions' => json_encode(['view_price' => true])]);
        $this->getJson($this->url('/agreement'))->assertJsonPath('data.price_minor', 350000)->assertJsonPath('data.money.net_minor', null);

        // D9: a member of the team who does not work on it, and somebody outside the team, do not find it.
        foreach ([$this->huda, $this->omar] as $who) {
            $this->as($who);
            $this->getJson($this->url('/agreement'))->assertNotFound();
            $this->getJson($this->url('/workroom/tasks'))->assertNotFound();
        }

        // The team's list says the same.
        $count = fn (Freelancer $who) => count($this->actingAsFresh($who)->getJson(self::API."/teams/{$this->team->uuid}/projects")->assertOk()->json('data'));
        $this->assertSame(1, $count($this->sara));
        $this->assertSame(1, $count($this->lina));
        $this->assertSame(1, $count($this->noor));
        $this->assertSame(0, $count($this->huda));
        $this->actingAsFresh($this->omar)->getJson(self::API."/teams/{$this->team->uuid}/projects")->assertNotFound();
        $this->actingAsFresh($this->noor)->getJson(self::API."/teams/{$this->team->uuid}/projects")
            ->assertJsonPath('data.0.project.uuid', $this->project->uuid)->assertJsonPath('data.0.team_access.role', 'member')
            ->assertJsonPath('data.0.signed_by.name', 'Sara Ali');

        // Leaving takes it away at once (D5).
        app(FreelancerTeams::class)->leave($this->team, $this->noor);
        $this->actingAsFresh($this->noor)->getJson($this->url('/agreement'))->assertNotFound();
    }

    public function test_any_owner_of_the_team_delivers_and_the_signature_stays_the_signers(): void
    {
        app(FreelancerTeams::class)->changeRole($this->team, $this->sara, $this->huda, Member::OWNER);
        $m = $this->fund();
        $this->as($this->sara);
        $this->postJson($this->url("/milestones/{$m->uuid}/start"))->assertOk();

        $this->as($this->huda);
        $this->getJson($this->url('/agreement'))->assertOk()->assertJsonPath('data.team_access.role', 'owner')->assertJsonPath('data.money.net_minor', 315000);
        $this->postJson($this->url('/agreement/approve'))->assertForbidden()->assertJsonPath('error_code', 'freelance_team_project_forbidden');
        $this->postJson($this->url("/milestones/{$m->uuid}/deliver"), ['note' => 'The booking page.'])->assertOk()->assertJsonPath('data.milestones.0.status', 'delivered');

        $row = DB::table('freelance_milestone_events')->where('type', 'milestone.delivered')->first();
        $this->assertSame($this->sara->id, (int) $row->actor_id, 'delivered in the signer\'s name - his agreement, his money (D2)');
        $this->assertSame($this->huda->id, (int) $row->acted_by_freelancer_id);
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement/trail")->assertOk();
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.milestone.delivered')->count());
    }

    public function test_a_team_not_active_or_teams_off_lets_only_the_signer_in(): void
    {
        $this->team->update(['status' => FreelanceTeam::SUSPENDED]);
        $this->as($this->lina);
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->as($this->sara);
        $this->getJson($this->url('/agreement'))->assertOk();

        $this->team->update(['status' => FreelanceTeam::ACTIVE]);
        FreelanceFeatureRule::query()->where('feature', 'teams')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->as($this->lina);
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->as($this->sara);
        $this->getJson($this->url('/agreement'))->assertOk();
    }

    public function test_the_live_workroom_channel_follows_the_same_rule(): void
    {
        $access = app(WorkroomAccess::class);
        $this->assertTrue($access->listens($this->sara, $this->project->uuid));
        $this->assertTrue($access->listens($this->lina, $this->project->uuid));
        $this->assertTrue($access->listens($this->noor, $this->project->uuid));
        $this->assertFalse($access->listens($this->huda, $this->project->uuid), 'D9: a member not on it');
        $this->assertFalse($access->listens($this->omar, $this->project->uuid));
    }

    public function test_his_own_routes_stay_his_own(): void
    {
        // Favouriting and offering are the person's own, never done for the Owner.
        $this->as($this->lina);
        $this->getJson($this->url('/my-offer'))->assertNotFound();
        $this->assertSame(0, DB::table('freelance_project_events')->whereNotNull('acted_by_freelancer_id')->count());
    }

    private function actingAsFresh(Freelancer $who): static
    {
        $this->as($who);

        return $this;
    }
}
