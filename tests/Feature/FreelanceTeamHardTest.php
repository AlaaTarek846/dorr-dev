<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-212: the freelancers' teams held to their rules as a whole, the way the parts are not. Each part has its own test file; this one asks the
 * four questions the parts cannot answer alone -
 *  1. the roles: for every team route, who gets in, who is told "not yours" (403) and who is told nothing exists (404), and that a refusal
 *     changes nothing;
 *  2. a member leaving, or being removed, while a project is running (D5): he is cut off at once and the work, the money and the client's
 *     agreement go on;
 *  3. the last Owner (D6): no way out, by any door, until somebody else is made an Owner;
 *  4. isolation: a member sees only what is his (D9), a rival team's identifiers never open anything, and a former member finds nothing left;
 *  5. the audit: every step that changes a team says what it was and who did it.
 */
class FreelanceTeamHardTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    /** The people a route is tried with, in the order of the tables below. */
    private const WHO = ['owner', 'pm', 'member', 'idle', 'outsider', 'rival'];

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private FreelanceTeam $team;

    private FreelanceTeam $rivalTeam;

    private FreelanceProject $project;

    /** Owner and signer of the team's agreement. */
    private Freelancer $sara;

    private Freelancer $lina;

    /** Works on the project. */
    private Freelancer $noor;

    /** A member of the team who does not work on the project. */
    private Freelancer $huda;

    /** Not in any team. */
    private Freelancer $omar;

    /** Owner of another team. */
    private Freelancer $zain;

    private Freelancer $reem;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'commission', 'teams', 'workroom', 'subscriptions'] as $feature) {
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
        $this->omar = $this->freelancer('+966500000095', 'Omar Khaled');
        $this->zain = $this->freelancer('+966500000096', 'Zain Fahd');
        $this->reem = $this->freelancer('+966500000097', 'Reem Adel');
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);
        $teams->join($this->team, $this->huda);
        $this->rivalTeam = $teams->create($this->zain, 'Rival Studio');
        $teams->join($this->rivalTeam, $this->reem);

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

    private function t(string $suffix = '', ?FreelanceTeam $team = null): string
    {
        return self::API.'/teams/'.($team ?? $this->team)->uuid.$suffix;
    }

    private function url(string $suffix = ''): string
    {
        return self::API."/projects/{$this->project->uuid}{$suffix}";
    }

    /** @return array<string, Freelancer> */
    private function people(): array
    {
        return ['owner' => $this->sara, 'pm' => $this->lina, 'member' => $this->noor, 'idle' => $this->huda, 'outsider' => $this->omar, 'rival' => $this->zain];
    }

    /** A snapshot of everything a team route could change: a refused call must leave it exactly as it was. */
    private function fingerprint(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'teams' => $rows('freelance_teams'), 'members' => $rows('freelance_team_members'), 'invitations' => $rows('freelance_team_invitations'),
            'threads' => DB::table('freelance_internal_threads')->count(), 'messages' => DB::table('freelance_internal_messages')->count(),
            'portfolio' => FreelancerPortfolioItem::query()->count(), 'papers' => DB::table('freelance_team_verification_documents')->count(),
            'requests' => DB::table('freelance_team_verification_requests')->count(), 'subscriptions' => DB::table('freelance_team_subscriptions')->count(),
            'audit' => AuditLog::query()->count(),
        ];
    }

    /**
     * Tries every route with every kind of person and says where the answer is not the one the rule gives. Rows are
     * `[label, method, path (after the team), body, [owner, pm, member, idle, outsider, rival]]`; a null skips that person.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: list<int|null>}>  $rows
     * @return list<string>
     */
    private function wrong(array $rows, bool $writes): array
    {
        $wrong = [];
        foreach ($rows as [$label, $method, $path, $body, $expected]) {
            foreach (self::WHO as $i => $who) {
                if ($expected[$i] === null) {
                    continue;
                }
                $before = $writes ? $this->fingerprint() : null;
                $this->as($this->people()[$who]);
                $status = $this->json($method, $this->t($path), $body)->getStatusCode();
                if ($status !== $expected[$i]) {
                    $wrong[] = "{$label} as {$who}: {$status}, the rule says {$expected[$i]}";
                }
                if ($writes && $expected[$i] >= 400 && $this->fingerprint() !== $before) {
                    $wrong[] = "{$label} as {$who}: refused ({$status}) but something changed";
                }
            }
        }

        return $wrong;
    }

    // ------------------------------------------------------------------------------------------------------ 1. roles

    public function test_who_reads_what_across_the_roles(): void
    {
        // The people of the team read it; the Owners read the money and the papers; nobody outside is told it exists.
        $this->assertSame([], $this->wrong([
            ['profile', 'GET', '/profile', [], [200, 200, 200, 200, 404, 404]],
            ['members', 'GET', '/members', [], [200, 200, 200, 200, 404, 404]],
            ['projects', 'GET', '/projects', [], [200, 200, 200, 200, 404, 404]],
            ['workload', 'GET', '/workload', [], [200, 200, 200, 200, 404, 404]],
            ['chat threads', 'GET', '/chat/threads', [], [200, 200, 200, 200, 404, 404]],
            ['portfolio', 'GET', '/portfolio', [], [200, 200, 200, 200, 404, 404]],
            ['invitations', 'GET', '/invitations', [], [200, 403, 403, 403, 404, 404]], // `invite_members` is off for a PM until an Owner gives it
            ['verification', 'GET', '/verification', [], [200, 404, 404, 404, 404, 404]], // the papers are the Owners' - for anybody else they do not exist
            ['subscription', 'GET', '/subscription', [], [200, 403, 403, 403, 404, 404]],
        ], false));
    }

    public function test_who_writes_what_and_a_refusal_changes_nothing(): void
    {
        $this->as($this->sara);
        $this->postJson($this->t('/chat/threads'), ['project' => $this->project->uuid])->assertOk(); // so the thread exists before the tries
        $noor = $this->noor->uuid;
        $none = [null, 403, 403, 403, 404, 404];

        $this->assertSame([], $this->wrong([
            ['edit the profile', 'PUT', '/profile', ['bio' => 'Hacked'], $none],
            ['hide the logo', 'DELETE', '/profile/logo', [], $none],
            ['add a work', 'POST', '/portfolio', ['title' => 'A work', 'rights_confirmed' => true], $none],
            ['order the works', 'PUT', '/portfolio/order', ['ids' => [1]], $none],
            ['send the papers', 'POST', '/verification/submit', [], [null, 404, 404, 404, 404, 404]],
            ['open a project thread', 'POST', '/chat/threads', ['project' => $this->project->uuid], $none],
            ['switch an ability', 'PUT', "/members/{$noor}/permissions", ['permissions' => ['view_price' => true]], $none],
            ['change a role', 'PUT', "/members/{$noor}/role", ['role' => 'pm'], $none],
            ['remove somebody', 'DELETE', "/members/{$noor}", [], $none],
            ['take a plan', 'POST', '/subscription', ['plan' => 'team_starter', 'period' => 'monthly'], $none],
            ['stop the renewal', 'POST', '/subscription/cancel', [], $none],
            ['invite without the right', 'POST', '/invitations', ['phone' => $this->omar->phone, 'role' => 'member'], [null, 403, 403, 403, 404, 404]],
        ], true));
    }

    public function test_a_pm_invites_only_when_an_owner_switches_it_on_and_never_an_owner(): void
    {
        $this->as($this->lina);
        $this->postJson($this->t('/invitations'), ['phone' => $this->omar->phone, 'role' => 'member'])->assertForbidden();

        $this->as($this->sara);
        $this->putJson($this->t("/members/{$this->lina->uuid}/permissions"), ['permissions' => ['invite_members' => true]])->assertOk();
        $this->as($this->lina);
        $this->postJson($this->t('/invitations'), ['phone' => $this->omar->phone, 'role' => 'owner'])->assertStatus(422);
        $this->postJson($this->t('/invitations'), ['phone' => $this->omar->phone, 'role' => 'member'])->assertCreated();

        // He can not hand somebody the Owner role either, nor switch abilities on (an Owner's call).
        $this->putJson($this->t("/members/{$this->noor->uuid}/role"), ['role' => 'owner'])->assertForbidden();
        $this->putJson($this->t("/members/{$this->noor->uuid}/permissions"), ['permissions' => ['view_price' => true]])->assertForbidden();
        $this->assertSame(Member::MEMBER, Member::query()->where('freelancer_id', $this->noor->id)->value('role'));
    }

    // ------------------------------------------------------------------------------------------------------ 2. leaving mid-project (D5)

    public function test_a_member_who_leaves_mid_project_is_cut_off_at_once_and_the_work_goes_on(): void
    {
        $m = $this->fund();
        $this->as($this->sara);
        $this->postJson($this->url("/milestones/{$m->uuid}/start"))->assertOk();
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->noor->uuid]])->assertOk();
        $this->as($this->noor);
        $this->postJson($this->url('/workroom/tasks'), ['title' => 'My screens', 'assignee' => $this->noor->uuid])->assertCreated();

        $before = DB::table('freelance_agreements')->first();
        $status = DB::table('freelance_milestones')->where('id', $m->id)->value('status');
        $wallets = DB::table('wallet_transactions')->count();

        // He goes - no question asked, nothing held back (D5).
        $this->postJson($this->t('/leave'), ['reason' => 'Other work'])->assertOk();

        // At once: no project, no team, no thread, no workroom channel.
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->getJson($this->url('/workroom/tasks'))->assertNotFound();
        $this->getJson($this->t('/projects'))->assertNotFound();
        $this->getJson($this->t('/chat/threads'))->assertNotFound();
        $this->assertFalse(app(WorkroomAccess::class)->listens($this->noor->refresh(), $this->project->uuid));

        // What he held lets go; the row stays with who and why.
        $this->assertSame(0, DB::table('freelance_milestone_assignees')->where('freelancer_id', $this->noor->id)->count());
        $this->assertNull(DB::table('freelance_workroom_tasks')->where('title', 'My screens')->value('assignee_freelancer_id'));
        $row = Member::query()->where('freelancer_id', $this->noor->id)->firstOrFail();
        $this->assertSame(Member::LEFT, $row->status);
        $this->assertSame('Other work', $row->left_reason);

        // The client's agreement, the milestone and the money are as they were - nobody asked him; the Owners are told.
        $after = DB::table('freelance_agreements')->first();
        $this->assertSame([$before->status, $before->price_minor, $before->freelancer_id, $before->team_id], [$after->status, $after->price_minor, $after->freelancer_id, $after->team_id]);
        $this->assertSame($status, DB::table('freelance_milestones')->where('id', $m->id)->value('status'));
        $this->assertSame($wallets, DB::table('wallet_transactions')->count());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.team_member_left')->count());

        // The Owner delivers and the client approves: the project ends well without him.
        $this->as($this->sara);
        $this->postJson($this->url("/milestones/{$m->uuid}/deliver"), ['note' => 'Done.'])->assertOk()->assertJsonPath('data.milestones.0.status', 'delivered');
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$m->uuid}/approve")->assertOk();
        $this->assertSame('approved', (string) DB::table('freelance_milestones')->where('id', $m->id)->value('status'));
    }

    public function test_the_pm_leaving_an_owner_removing_and_the_staff_removing_all_cut_off_the_same_way(): void
    {
        $this->as($this->lina);
        $this->getJson($this->url('/agreement'))->assertOk();
        $this->postJson($this->t('/leave'))->assertOk();
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->getJson($this->t('/members'))->assertNotFound();

        $this->as($this->sara);
        $this->deleteJson($this->t("/members/{$this->noor->uuid}"), ['reason' => 'Not a fit'])->assertOk();
        $this->as($this->noor);
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->getJson($this->t('/projects'))->assertNotFound();
        $this->assertSame(Member::REMOVED, Member::query()->where('freelancer_id', $this->noor->id)->value('status'));
        $this->assertSame(1, $this->noor->notifications()->where('data->event', 'freelance.team_member_removed')->count());

        // The third door: staff of the platform take a member out - what he held lets go, he is told, and it is on the log.
        $this->as($this->sara);
        $m = FreelanceMilestone::query()->firstOrFail();
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->huda->uuid]])->assertOk();
        app(\Modules\Freelance\Services\FreelancerTeam\TeamModeration::class)->removeMember($this->team, $this->huda, 'Policy');
        $this->as($this->huda);
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->getJson($this->t('/members'))->assertNotFound();
        $this->assertSame(0, DB::table('freelance_milestone_assignees')->where('freelancer_id', $this->huda->id)->count());
        $this->assertSame(1, $this->huda->notifications()->where('data->event', 'freelance.team_member_removed_by_staff')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'freelance.team.member_removed_by_staff')->count());

        // The project belongs to the Owner and goes on with him alone.
        $this->as($this->sara);
        $this->getJson($this->url('/agreement'))->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_coming_back_starts_fresh_and_holds_nothing_he_was_put_on_before(): void
    {
        $teams = app(FreelancerTeams::class);
        $m = FreelanceMilestone::query()->firstOrFail();
        $this->as($this->sara);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->huda->uuid]])->assertOk();
        $this->putJson($this->t("/members/{$this->huda->uuid}/permissions"), ['permissions' => ['view_price' => true]])->assertOk();
        $this->as($this->huda);
        $this->getJson($this->url('/agreement'))->assertOk();

        $this->postJson($this->t('/leave'))->assertOk();
        $teams->join($this->team, $this->huda); // back on the same row

        // D9: the part he held let go when he left, so nothing of the project is his until somebody puts him on it again; and his switches are gone.
        $this->as($this->huda);
        $this->getJson($this->t('/members'))->assertOk();
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $this->assertSame(0, count($this->getJson($this->t('/projects'))->assertOk()->json('data')));
        $this->assertSame(Member::MEMBER, Member::query()->where('freelancer_id', $this->huda->id)->value('role'));
        $this->assertEmpty((array) json_decode((string) Member::query()->where('freelancer_id', $this->huda->id)->value('permissions'), true));
        $this->assertSame(1, Member::query()->where('freelancer_id', $this->huda->id)->where('team_id', $this->team->id)->count(), 'one row per person and team');

        // The person the client's agreement names stays named on it: coming back puts him on it again, as the contract says.
        $this->as($this->noor);
        $this->postJson($this->t('/leave'))->assertOk();
        $this->getJson($this->url('/agreement'))->assertNotFound();
        $teams->join($this->team, $this->noor);
        $this->getJson($this->url('/agreement'))->assertOk();
    }

    // ------------------------------------------------------------------------------------------------------ 3. the last Owner (D6)

    public function test_the_last_owner_can_not_go_by_any_door(): void
    {
        $this->as($this->sara);
        $mine = $this->sara->uuid;
        $before = $this->fingerprint();

        $this->postJson($this->t('/leave'))->assertStatus(409);
        $this->assertContains($this->deleteJson($this->t("/members/{$mine}"))->getStatusCode(), [403, 409]); // a person takes himself out by leaving, not by removing
        $this->putJson($this->t("/members/{$mine}/role"), ['role' => 'pm'])->assertStatus(409);
        $this->putJson($this->t("/members/{$mine}/role"), ['role' => 'member'])->assertStatus(409);
        $this->assertSame($before, $this->fingerprint());

        // A staff member may not take the last one out either.
        $this->assertSame(1, Member::query()->where('team_id', $this->team->id)->where('role', Member::OWNER)->where('status', Member::ACTIVE)->count());
        $this->expectException(\Modules\Freelance\Exceptions\FreelanceException::class);
        app(FreelancerTeams::class)->removeByStaff($this->team, $this->sara, 'x');
    }

    public function test_ownership_moves_by_promotion_and_the_one_left_alone_is_the_last_again(): void
    {
        $teams = app(FreelancerTeams::class);
        $this->as($this->sara);
        $this->putJson($this->t("/members/{$this->lina->uuid}/role"), ['role' => 'owner'])->assertOk();

        // Two Owners: the one who signed nothing may step down - once (Sara signed the running agreement, see the signer test below).
        $this->as($this->lina);
        $this->putJson($this->t("/members/{$this->lina->uuid}/role"), ['role' => 'member'])->assertOk();
        $this->assertSame(Member::MEMBER, Member::query()->where('freelancer_id', $this->lina->id)->value('role'));

        // Lina has lost the Owner's powers at once: she no longer reads the papers or the plan.
        $this->getJson($this->t('/subscription'))->assertForbidden();
        $this->getJson($this->t('/verification'))->assertNotFound();

        // Sara is the last Owner again: no way out until somebody else is made one.
        $this->as($this->sara);
        $this->postJson($this->t('/leave'))->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_last_owner');
        $this->putJson($this->t("/members/{$this->sara->uuid}/role"), ['role' => 'pm'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_last_owner');

        // She moves on by making somebody else an Owner - and, as the signer, once the agreement has ended.
        $this->putJson($this->t("/members/{$this->huda->uuid}/role"), ['role' => 'owner'])->assertOk();
        $this->postJson($this->t('/leave'))->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_signer_active');
        DB::table('freelance_agreements')->update(['status' => 'completed']);
        $this->postJson($this->t('/leave'))->assertOk();
        $this->assertSame(1, Member::query()->where('team_id', $this->team->id)->where('role', Member::OWNER)->where('status', Member::ACTIVE)->count());
        $this->assertTrue($teams->isOwner($this->team, $this->huda));
    }

    public function test_a_signing_owner_stays_until_the_agreement_ends(): void
    {
        $teams = app(FreelancerTeams::class);
        // Three Owners, so the last-Owner rule is never what stops Sara: Sara signed the agreement, Lina and Huda signed nothing.
        $teams->changeRole($this->team, $this->sara, $this->lina, Member::OWNER);
        $teams->changeRole($this->team, $this->sara, $this->huda, Member::OWNER);
        $before = $this->fingerprint();
        $code = 'freelance_freelancer_team_signer_active';

        // Every door out is shut for Sara while it lives: leaving, stepping down, and being removed by another Owner.
        $this->as($this->sara);
        $this->postJson($this->t('/leave'))->assertStatus(409)->assertJsonPath('error_code', $code)->assertJsonPath('data.agreements', 1);
        $this->putJson($this->t("/members/{$this->sara->uuid}/role"), ['role' => 'pm'])->assertStatus(409)->assertJsonPath('error_code', $code);
        $this->as($this->lina);
        $this->deleteJson($this->t("/members/{$this->sara->uuid}"), ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error_code', $code);
        $this->putJson($this->t("/members/{$this->sara->uuid}/role"), ['role' => 'member'])->assertStatus(409)->assertJsonPath('error_code', $code);
        $this->assertSame($before, $this->fingerprint(), 'a refusal changed something');

        // The list says so before anybody tries: it is Sara's number, nobody else's.
        $list = $this->getJson($this->t('/members'))->assertOk()->assertJsonPath('data.me.signed_agreements', 0);
        $signed = collect($list->json('data.members'))->mapWithKeys(fn ($m) => [$m['freelancer']['uuid'] => $m['signed_agreements']])->all();
        $this->assertSame([$this->sara->uuid => 1, $this->lina->uuid => 0, $this->huda->uuid => 0, $this->noor->uuid => 0], array_intersect_key($signed, array_flip([$this->sara->uuid, $this->lina->uuid, $this->huda->uuid, $this->noor->uuid])));
        $this->as($this->sara);
        $this->getJson($this->t('/members'))->assertJsonPath('data.me.signed_agreements', 1);

        // The Owner who signed nothing is free to step down or leave while the agreement lives; so is anyone who is not an Owner.
        $this->as($this->lina);
        $this->putJson($this->t("/members/{$this->lina->uuid}/role"), ['role' => 'pm'])->assertOk();
        $this->as($this->noor);
        $this->postJson($this->t('/leave'))->assertOk();

        // What counts as "not ended": a draft, one waiting for the signature and a running one. The rest let him go.
        foreach (['draft', 'pending_approval', 'active'] as $status) {
            DB::table('freelance_agreements')->update(['status' => $status]);
            $this->assertSame(1, $teams->liveAgreements($this->team, $this->sara), $status);
        }
        foreach (['declined', 'cancelled', 'expired', 'completed'] as $status) {
            DB::table('freelance_agreements')->update(['status' => $status]);
            $this->assertSame(0, $teams->liveAgreements($this->team, $this->sara), $status);
        }

        // Another team's agreement signed by the same person does not hold him in this one.
        $this->assertSame(0, $teams->liveAgreements($this->rivalTeam, $this->sara));

        // Moderation is not stopped by it: staff can take the signer out even while the agreement runs.
        DB::table('freelance_agreements')->update(['status' => 'active']);
        app(\Modules\Freelance\Services\FreelancerTeam\TeamModeration::class)->removeMember($this->team, $this->sara, 'Policy');
        $this->assertSame(Member::REMOVED, Member::query()->where('freelancer_id', $this->sara->id)->value('status'));

        // And once it ends he can go on his own: the same signer, the same team, a different state.
        $teams->join($this->team, $this->sara);
        $teams->changeRole($this->team, $this->huda, $this->sara, Member::OWNER);
        $this->as($this->sara);
        $this->postJson($this->t('/leave'))->assertStatus(409)->assertJsonPath('error_code', $code);
        DB::table('freelance_agreements')->update(['status' => 'completed']);
        $this->postJson($this->t('/leave'))->assertOk();
    }

    public function test_an_owner_with_an_open_offer_stays_until_he_takes_it_back(): void
    {
        $teams = app(FreelancerTeams::class);
        $teams->changeRole($this->team, $this->sara, $this->lina, Member::OWNER);

        // Lina, an Owner who signed no agreement, sends an offer in the team's name on a second project.
        $second = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A clinic app',
            'description' => 'An app where patients book a visit and read their results.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->as($this->lina);
        $offer = $this->postJson(self::API."/projects/{$second->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'We built three clinic apps like this; we will deliver in three steps with a demo each week.',
            'team' => $this->team->uuid, 'staff' => [['freelancer' => $this->noor->uuid, 'role' => 'executor', 'responsibility' => 'Screens']],
        ])->assertCreated()->json('data.uuid');
        $this->assertNotEmpty($offer);
        $before = $this->fingerprint();
        $code = 'freelance_freelancer_team_signer_active';

        // Every door out is shut for her while it is open - and the reply says what holds her.
        $this->postJson($this->t('/leave'))->assertStatus(409)->assertJsonPath('error_code', $code)->assertJsonPath('data.offers', 1)->assertJsonPath('data.agreements', 0);
        $this->putJson($this->t("/members/{$this->lina->uuid}/role"), ['role' => 'pm'])->assertStatus(409)->assertJsonPath('error_code', $code);
        $this->as($this->sara);
        $this->deleteJson($this->t("/members/{$this->lina->uuid}"), ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error_code', $code);
        $this->assertSame($before, $this->fingerprint(), 'a refusal changed something');

        // The list says so before anybody tries.
        $list = $this->getJson($this->t('/members'))->assertOk();
        $open = collect($list->json('data.members'))->mapWithKeys(fn ($m) => [$m['freelancer']['uuid'] => $m['open_offers']])->all();
        $this->assertSame(1, $open[$this->lina->uuid]);
        $this->assertSame(0, $open[$this->sara->uuid]);
        $this->as($this->lina);
        $this->getJson($this->t('/members'))->assertJsonPath('data.me.open_offers', 1)->assertJsonPath('data.me.signed_agreements', 0);

        // Open means the client may yet choose it; the others let her go.
        foreach (['submitted', 'viewed', 'shortlisted'] as $status) {
            DB::table('freelance_offers')->where('uuid', $offer)->update(['status' => $status]);
            $this->assertSame(1, $teams->openOffers($this->team, $this->lina), $status);
        }
        foreach (['rejected', 'accepted', 'withdrawn'] as $status) {
            DB::table('freelance_offers')->where('uuid', $offer)->update(['status' => $status]);
            $this->assertSame(0, $teams->openOffers($this->team, $this->lina), $status);
        }
        DB::table('freelance_offers')->where('uuid', $offer)->update(['status' => 'submitted']);

        // She takes it back herself (the place on the team's quota comes back with it) and goes free.
        $this->postJson(self::API."/offers/{$offer}/withdraw")->assertOk();
        $this->assertSame(0, $teams->openOffers($this->team, $this->lina));
        $this->putJson($this->t("/members/{$this->lina->uuid}/role"), ['role' => 'pm'])->assertOk();
        $this->assertSame(Member::PM, Member::query()->where('freelancer_id', $this->lina->id)->value('role'));
    }

    public function test_two_owners_who_both_try_to_leave_leave_one_behind(): void
    {
        $teams = app(FreelancerTeams::class);
        $teams->changeRole($this->team, $this->sara, $this->lina, Member::OWNER);
        DB::table('freelance_agreements')->update(['status' => 'completed']); // Sara signed it; with it over, nothing but the last-Owner rule holds her

        $this->as($this->sara);
        $first = $this->postJson($this->t('/leave'))->getStatusCode();
        $this->as($this->lina);
        $second = $this->postJson($this->t('/leave'))->getStatusCode();

        $this->assertSame([200, 409], [$first, $second]);
        $this->assertSame(1, Member::query()->where('team_id', $this->team->id)->where('role', Member::OWNER)->where('status', Member::ACTIVE)->count());
    }

    public function test_the_owner_role_is_never_given_by_an_invitation_or_by_joining(): void
    {
        $this->as($this->sara);
        $this->postJson($this->t('/invitations'), ['phone' => $this->omar->phone, 'role' => 'owner'])->assertStatus(422);
        $this->expectException(\Modules\Freelance\Exceptions\FreelanceException::class);
        $this->expectExceptionMessage('freelancer_team_role_invalid');
        app(FreelancerTeams::class)->join($this->team, $this->omar, Member::OWNER);
    }

    // ------------------------------------------------------------------------------------------------------ 4. isolation

    public function test_the_identifiers_of_another_team_never_open_anything(): void
    {
        // Dorr's things, each with its identifier.
        $this->as($this->sara);
        $thread = $this->postJson($this->t('/chat/threads'), ['project' => $this->project->uuid])->assertOk()->json('data.thread.uuid');
        $message = $this->postJson($this->t("/chat/threads/{$thread}/messages"), ['body' => 'Private to Dorr'])->assertCreated()->json('data.message.id');
        $item = $this->postJson($this->t('/portfolio'), ['title' => 'A work', 'rights_confirmed' => true])->assertCreated()->json('data.id');
        $invitation = $this->postJson($this->t('/invitations'), ['phone' => $this->omar->phone, 'role' => 'member'])->assertCreated()->json('data.invitation.uuid');
        $paper = $this->postJson($this->t('/verification/documents'), ['title' => 'Register', 'file' => UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\n%%EOF")])->assertCreated()->json('data.uuid');
        foreach (['thread' => $thread, 'message' => $message, 'item' => $item, 'invitation' => $invitation, 'paper' => $paper] as $what => $id) {
            $this->assertNotEmpty($id, "the {$what} was not made - the tries below would prove nothing");
        }
        $before = $this->fingerprint();

        // Zain, an Owner of his own team, tries them through his own team's routes - and through Dorr's.
        $this->as($this->zain);
        $rival = fn (string $s) => $this->t($s, $this->rivalTeam);
        $tries = [
            ['GET', $rival("/chat/threads/{$thread}/messages"), []],
            ['POST', $rival("/chat/threads/{$thread}/messages"), ['body' => 'Hello from the rival']],
            ['POST', $rival("/chat/threads/{$thread}/read"), []],
            ['DELETE', $rival("/chat/messages/{$message}"), []],
            ['PUT', $rival("/portfolio/{$item}"), ['title' => 'Mine now']],
            ['DELETE', $rival("/portfolio/{$item}"), []],
            ['DELETE', $rival("/invitations/{$invitation}"), []],
            ['GET', $rival("/verification/documents/{$paper}"), []],
            ['DELETE', $rival("/verification/documents/{$paper}"), []],
            ['PUT', $rival("/members/{$this->noor->uuid}/role"), ['role' => 'owner']],
            ['PUT', $rival("/members/{$this->noor->uuid}/permissions"), ['permissions' => ['view_price' => true]]],
            ['DELETE', $rival("/members/{$this->noor->uuid}"), []],
            // And straight at Dorr's team.
            ['GET', $this->t('/members'), []],
            ['PUT', $this->t('/profile'), ['bio' => 'Taken over']],
            ['POST', $this->t('/leave'), []],
            ['GET', $this->url('/agreement'), []],
            ['GET', $this->url('/workroom/tasks'), []],
        ];
        $answered = [];
        foreach ($tries as [$method, $path, $body]) {
            $answered[] = $method.' '.str_replace(self::API, '', $path).' -> '.$this->json($method, $path, $body)->getStatusCode();
        }
        foreach ($answered as $line) {
            $this->assertMatchesRegularExpression('/-> (403|404)$/', $line, 'the rival got in: '.$line);
        }
        $this->assertSame($before, $this->fingerprint(), 'a rival\'s try changed something');

        // The other way round: Sara knows Rival Studio's uuid and finds nothing.
        $this->as($this->sara);
        foreach (['/profile', '/members', '/projects', '/workload', '/chat/threads', '/portfolio', '/invitations', '/subscription'] as $suffix) {
            $this->getJson($this->t($suffix, $this->rivalTeam))->assertNotFound();
        }
    }

    public function test_a_member_sees_only_what_is_his_on_the_projects_the_threads_and_the_workload(): void
    {
        $this->as($this->sara);
        $this->postJson($this->t('/chat/threads'), ['project' => $this->project->uuid])->assertOk();
        $m = FreelanceMilestone::query()->firstOrFail();
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->noor->uuid]])->assertOk();

        // Threads: the general one for everybody, the project's only for those who work on it (D9).
        $threads = fn (Freelancer $who) => collect($this->actingAsFresh($who)->getJson($this->t('/chat/threads'))->assertOk()->json('data.threads'))->map(fn ($th) => $th['general'] ? 'general' : $th['project']['uuid'])->sort()->values()->all();
        $both = collect(['general', $this->project->uuid])->sort()->values()->all();
        $this->assertSame($both, $threads($this->sara));
        $this->assertSame($both, $threads($this->lina));
        $this->assertSame($both, $threads($this->noor));
        $this->assertSame(['general'], $threads($this->huda));

        // The project thread's messages are not Huda's to read or to write in.
        $this->as($this->sara);
        $thread = collect($this->getJson($this->t('/chat/threads'))->json('data.threads'))->firstWhere('general', false)['uuid'];
        $this->postJson($this->t("/chat/threads/{$thread}/messages"), ['body' => 'About the booking page'])->assertCreated();
        $this->as($this->huda);
        $this->getJson($this->t("/chat/threads/{$thread}/messages"))->assertNotFound();
        $this->postJson($this->t("/chat/threads/{$thread}/messages"), ['body' => 'Let me in'])->assertNotFound();
        $this->as($this->noor);
        $this->getJson($this->t("/chat/threads/{$thread}/messages"))->assertOk();

        // The workload: the Owner and the PM see everybody, a member only his own row.
        $rows = fn (Freelancer $who) => collect($this->actingAsFresh($who)->getJson($this->t('/workload'))->assertOk()->json('data.members'));
        $this->assertGreaterThan(1, $rows($this->sara)->count());
        $this->assertGreaterThan(1, $rows($this->lina)->count());
        foreach ([$this->noor, $this->huda] as $who) {
            $mine = $rows($who);
            $this->assertCount(1, $mine);
            $this->assertSame($who->uuid, $mine->first()['freelancer']['uuid'] ?? $mine->first()['uuid'] ?? null);
        }

        // The live channel and the project's list say the same.
        $access = app(WorkroomAccess::class);
        $this->assertTrue($access->listens($this->noor, $this->project->uuid));
        $this->assertFalse($access->listens($this->huda, $this->project->uuid));
        $this->assertFalse($access->listens($this->zain, $this->project->uuid));
    }

    public function test_a_former_member_finds_nothing_of_the_team_left(): void
    {
        $teams = app(FreelancerTeams::class);
        $this->as($this->sara);
        $this->postJson($this->t('/chat/threads'), ['project' => $this->project->uuid])->assertOk();
        $teams->leave($this->team, $this->huda);
        $teams->remove($this->team, $this->sara, $this->noor);

        // Every read answers as it does to a stranger; the rows of what they did stay.
        foreach ([$this->huda, $this->noor] as $who) {
            foreach (['/profile', '/members', '/projects', '/workload', '/chat/threads', '/portfolio', '/invitations', '/verification', '/subscription'] as $suffix) {
                $this->as($who);
                $this->getJson($this->t($suffix))->assertNotFound();
            }
            $this->getJson($this->url('/agreement'))->assertNotFound();
        }
        $this->assertSame(2, Member::query()->where('team_id', $this->team->id)->whereIn('status', [Member::LEFT, Member::REMOVED])->count());

        // And nothing a former member sends goes in.
        $this->as($this->huda);
        $this->putJson($this->t('/profile/me'), ['show_in_profile' => true])->assertNotFound();
        $this->postJson($this->t('/leave'))->assertNotFound();
    }

    // ------------------------------------------------------------------------------------------------------ 5. the audit

    public function test_every_step_that_changes_a_team_is_on_the_audit_log_with_who_did_it(): void
    {
        $this->as($this->omar);
        $this->omar->update(['status' => 'verified']);
        $side = $this->postJson(self::API.'/teams', ['name' => 'Side Studio'])->assertCreated()->json('data.team.uuid');
        $team = FreelanceTeam::query()->where('uuid', $side)->firstOrFail();
        $this->putJson(self::API."/teams/{$side}/profile", ['bio' => 'We build small sites.'])->assertOk();
        $this->postJson(self::API."/teams/{$side}/invitations", ['phone' => $this->huda->phone, 'role' => 'member'])->assertCreated();
        $inviteLine = self::API."/teams/{$side}/invitations";
        $this->assertNotNull($inviteLine);

        $this->as($this->huda);
        $this->getJson(self::API.'/team-invitations')->assertOk();
        $invitation = DB::table('freelance_team_invitations')->where('team_id', $team->id)->value('uuid');
        $this->postJson(self::API."/team-invitations/{$invitation}/accept")->assertOk();
        $this->putJson(self::API."/teams/{$side}/profile/me", ['show_in_profile' => true])->assertOk();

        $this->as($this->omar);
        $this->putJson(self::API."/teams/{$side}/members/{$this->huda->uuid}/permissions", ['permissions' => ['view_price' => true]])->assertOk();
        $this->putJson(self::API."/teams/{$side}/members/{$this->huda->uuid}/role", ['role' => 'pm'])->assertOk();
        $this->deleteJson(self::API."/teams/{$side}/members/{$this->huda->uuid}", ['reason' => 'Trying'])->assertOk();

        $actions = AuditLog::query()->orderBy('id')->pluck('action')->all();
        foreach ([
            'freelance.team.created', 'freelance.team.profile_updated', 'freelance.team.invited', 'freelance.team.invitation_accepted',
            'freelance.team.consent_changed', 'freelance.team.permissions_changed', 'freelance.team.role_changed', 'freelance.team.member_removed',
        ] as $action) {
            $this->assertContains($action, $actions, "{$action} is not on the audit log");
        }

        // Each of them names the person who did it (the keys differ by what the step is, but one of them is always there).
        $named = ['by_freelancer_id', 'by', 'freelancer', 'created_by_freelancer_id'];
        foreach (AuditLog::query()->where('action', 'like', 'freelance.team.%')->get() as $row) {
            $after = (array) $row->after;
            $this->assertNotEmpty(array_intersect($named, array_keys($after)), "{$row->action} does not say who did it");
        }

        // A role change keeps both sides; a removal the reason.
        $role = AuditLog::query()->where('action', 'freelance.team.role_changed')->firstOrFail();
        $this->assertSame('member', $role->before['role']);
        $this->assertSame('pm', $role->after['role']);
        $this->assertSame($this->omar->id, (int) $role->after['by_freelancer_id']);
        $removed = AuditLog::query()->where('action', 'freelance.team.member_removed')->firstOrFail();
        $this->assertSame('Trying', $removed->after['reason']);
        $this->assertSame($this->omar->id, (int) $removed->after['by']);
    }

    public function test_a_leaving_and_an_assignment_are_on_the_record_too(): void
    {
        $m = FreelanceMilestone::query()->firstOrFail();
        $this->as($this->lina);
        $this->putJson($this->url("/milestones/{$m->uuid}/assignees"), ['freelancers' => [$this->huda->uuid]])->assertOk();

        // The project's own record says who put him on, and that it was the PM acting for the Owner.
        $event = DB::table('freelance_project_events')->where('type', 'team.milestone.assignees')->first();
        $this->assertNotNull($event, 'an assignment is not on the project record');
        $this->assertSame($this->lina->id, (int) $event->acted_by_freelancer_id);

        $this->as($this->huda);
        $this->postJson($this->t('/leave'), ['reason' => 'Busy'])->assertOk();
        $left = AuditLog::query()->where('action', 'freelance.team.member_left')->firstOrFail();
        $this->assertSame($this->huda->id, (int) $left->after['freelancer']);
        $this->assertSame('Busy', $left->after['reason']);
        $released = DB::table('freelance_project_events')->where('type', 'team.assignments.released')->first();
        $this->assertNotNull($released, 'what he held being let go is not on the project record');
    }

    private function actingAsFresh(Freelancer $who): static
    {
        $this->as($who);

        return $this;
    }
}
