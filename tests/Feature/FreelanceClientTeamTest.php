<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectMember;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-104 / FS-003: the people a client brings into his project (docs/modules/freelance/PERMISSIONS.md) - invited by phone, each role seeing
 * and doing only its part, a delegate approving within his limit and time from the owner's wallet, every step saying who took it.
 */
class FreelanceClientTeamTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'disputes', 'client_team'] as $feature) {
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
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
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

    private function as(User|Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], $who instanceof Freelancer ? 'freelance_api' : 'user_api');
    }

    /** The owner invites, the person accepts: an active member with this role. */
    private function join(User $who, string $role, array $limits = []): FreelanceProjectMember
    {
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $who->phone, 'role' => $role] + $limits)->assertOk();
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

    private function topUp(User $who, int $minor): void
    {
        $w = app(\Modules\Wallet\Services\WalletService::class);
        $w->credit($w->firstOrCreateWallet($who, $this->saudi), $minor, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup);
    }

    private function balance(User $who): int
    {
        return (int) app(\Modules\Wallet\Services\WalletService::class)->firstOrCreateWallet($who, $this->saudi)->fresh()->spendableMinor();
    }

    private function delivered(string $part): void
    {
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$part}/start")->assertOk();
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/milestones/{$part}/deliver", ['note' => 'Done, see the demo.'])->assertOk();
    }

    public function test_the_owner_invites_by_phone_and_the_person_accepts(): void
    {
        $this->as($this->owner);
        $page = $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => '966500000002', 'role' => 'reviewer'])->assertOk()
            ->assertJsonPath('data.members.0.name', 'Omar')->assertJsonPath('data.members.0.status', 'invited')->assertJsonPath('data.members.0.role', 'reviewer');
        $this->assertStringNotContainsString('500000002', (string) $page->json('data.members.0.phone'));
        $this->assertSame(1, $this->omar->notifications()->where('data->event', 'freelance.team.invited')->count());

        $this->as($this->omar);
        $invite = $this->getJson(self::TEAM.'/invitations')->assertOk()->assertJsonCount(1, 'data.invitations')
            ->assertJsonPath('data.invitations.0.project.title', 'A booking website')->assertJsonPath('data.invitations.0.owner.name', 'Mona')->json('data.invitations.0.uuid');
        // Not a member yet: the project is not his.
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertNotFound();
        $this->postJson(self::TEAM."/invitations/{$invite}/accept")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.team.joined')->count());
        $this->getJson(self::TEAM.'/projects')->assertOk()->assertJsonPath('data.0.uuid', $this->project->uuid)->assertJsonPath('data.0.access.role', 'reviewer');
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->assertJsonPath('data.access.role', 'reviewer')
            ->assertJsonPath('data.access.owner.name', 'Mona')->assertJsonPath('data.access.money', false);
        // An answered invitation is closed.
        $this->postJson(self::TEAM."/invitations/{$invite}/decline")->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_invitation_closed');

        $this->as($this->owner);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.access.role', 'owner')->assertJsonPath('data.team.members', 1);
    }

    public function test_who_can_be_invited(): void
    {
        $this->as($this->owner);
        $team = self::CLIENT."/{$this->project->uuid}/team";
        $this->postJson($team, ['phone' => '+966599999999', 'role' => 'reviewer'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_user_not_found');
        $this->postJson($team, ['phone' => $this->owner->phone, 'role' => 'reviewer'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_self');
        $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'boss'])->assertUnprocessable();
        $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'delegate'])->assertUnprocessable();
        // The freelancer who bid on the project, with a Dorr account on the same phone: a conflict of interest.
        $saraUser = $this->user('Sara', $this->sara->phone);
        $this->postJson($team, ['phone' => $saraUser->phone, 'role' => 'collaborator'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_conflict');

        $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'collaborator'])->assertOk();
        $this->postJson($team, ['phone' => $this->omar->phone, 'role' => 'manager'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_already');
        config(['freelance.client_team.max_members' => 1]);
        $this->postJson($team, ['phone' => $this->huda->phone, 'role' => 'collaborator'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_full');

        // Only the owner manages the team.
        $this->as($this->omar);
        $this->postJson(self::TEAM.'/invitations/'.FreelanceProjectMember::query()->where('user_id', $this->omar->id)->value('uuid').'/accept')->assertOk();
        $this->getJson($team)->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->postJson($team, ['phone' => $this->huda->phone, 'role' => 'collaborator'])->assertForbidden();
    }

    public function test_an_invitation_ends_after_a_week(): void
    {
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $this->omar->phone, 'role' => 'collaborator'])->assertOk();
        Carbon::setTestNow(now()->addDays(8));
        $this->getJson(self::CLIENT."/{$this->project->uuid}/team")->assertJsonPath('data.members.0.status', 'expired');
        $this->as($this->omar);
        $this->getJson(self::TEAM.'/invitations')->assertJsonCount(0, 'data.invitations');
        $uuid = FreelanceProjectMember::query()->value('uuid');
        $this->postJson(self::TEAM."/invitations/{$uuid}/accept")->assertStatus(409);
        // Asked again, it is a new week.
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $this->omar->phone, 'role' => 'collaborator'])->assertOk()->assertJsonPath('data.members.0.status', 'invited');
    }

    public function test_a_collaborator_reads_without_amounts_or_buttons(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 1_000_000);
        $this->join($this->omar, 'collaborator');

        $this->as($this->omar);
        $agreement = $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertOk()
            ->assertJsonPath('data.price_minor', null)->assertJsonPath('data.money.total_minor', null)->assertJsonPath('data.money.lines', [])
            ->assertJsonPath('data.milestones.0.amount_minor', null)->assertJsonPath('data.wallet_available_minor', null)
            ->assertJsonPath('data.milestones.0.can.fund', false)->assertJsonPath('data.milestones.0.can.cancel', false)
            ->assertJsonPath('data.access.role', 'collaborator');
        $this->assertSame('Design', $agreement->json('data.milestones.0.title'));
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->assertJsonPath('data.budget.min_minor', null)->assertJsonPath('data.can.cancel', false);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement/trail")->assertOk();

        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertForbidden();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/cancel")->assertForbidden();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/dispute", ['reason' => 'quality', 'details' => 'Nothing was delivered at all.'])->assertForbidden();
        $this->assertSame(1_000_000, $this->balance($this->owner));
    }

    public function test_a_finance_viewer_sees_the_money_but_not_the_owners_wallet(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 1_000_000);
        $this->join($this->omar, 'finance');

        $this->as($this->omar);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertOk()
            ->assertJsonPath('data.price_minor', 350000)->assertJsonPath('data.milestones.0.amount_minor', 100000)
            ->assertJsonPath('data.wallet_available_minor', null)->assertJsonPath('data.milestones.0.can.fund', false)->assertJsonPath('data.access.money', true);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertForbidden();
    }

    public function test_a_reviewer_checks_and_asks_for_a_revision_and_the_record_names_him(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 1_000_000);
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertOk();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/checklist", ['items' => ['The logo is on every page']])->assertOk();
        $this->join($this->omar, 'reviewer');
        $this->delivered($design);
        // He hears of the delivery like the owner does.
        $this->assertSame(1, $this->omar->notifications()->where('data->event', 'freelance.milestone.delivered')->count());

        $this->as($this->omar);
        $agreement = $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.can.revision', true)
            ->assertJsonPath('data.milestones.0.can.approve', false)->assertJsonPath('data.milestones.0.can.checklist_tick', true);
        $item = $agreement->json('data.milestones.0.checklist.0.uuid');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/checklist/{$item}/check")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/approve")->assertForbidden();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/revision", ['reason' => 'The header logo is blurry.'])->assertOk();

        $row = collect($this->getJson(self::CLIENT."/{$this->project->uuid}/agreement/trail")->json('data'))->firstWhere('action', 'milestone.revision');
        $this->assertSame(['name' => 'Omar', 'team' => true], $row['by']);
        $this->assertSame('client', $row['actor']);
        $this->assertSame($this->owner->id, (int) DB::table('freelance_project_approvals')->where('action', 'milestone.revision')->value('actor_id'));
        $this->assertSame($this->omar->id, (int) DB::table('freelance_milestone_events')->where('type', 'milestone.revision')->value('acted_by_user_id'));
        // The freelancer reads the name, nothing else about him.
        $this->as($this->sara);
        $seen = collect($this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement/trail")->json('data'))->firstWhere('action', 'milestone.revision');
        $this->assertSame(['name' => 'Omar', 'team' => true], $seen['by']);
    }

    public function test_a_delegate_funds_and_approves_within_his_limit_from_the_owners_wallet(): void
    {
        [$design, $build] = $this->active();
        $this->topUp($this->owner, 1_000_000);
        $this->topUp($this->omar, 50_000);
        $this->join($this->omar, 'delegate', ['max_amount_minor' => 150000, 'days' => 7]);

        $this->as($this->omar);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.milestones.0.can.fund', true)
            ->assertJsonPath('data.milestones.1.can.fund', false)->assertJsonPath('data.milestones.1.over_limit', true);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$build}/fund")->assertForbidden()->assertJsonPath('error_code', 'freelance_team_over_limit');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertOk()->assertJsonPath('data.access.role', 'delegate');
        $this->assertSame(1_000_000 - 100000, $this->balance($this->owner));
        $this->assertSame(50_000, $this->balance($this->omar));
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.team.acted_fund')->count());
        $this->assertSame($this->omar->id, (int) DB::table('freelance_project_approvals')->where('action', 'milestone.funded')->value('acted_by_user_id'));

        $this->delivered($design);
        $this->as($this->omar);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/approve")->assertOk();
        $this->assertSame('approved', FreelanceMilestone::query()->where('uuid', $design)->value('status')->value);
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.team.acted_release')->count());
        // Still never the owner's balance, and never the steps that stay his.
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.wallet_available_minor', null);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/cancel")->assertForbidden();
    }

    public function test_a_delegation_ends_on_its_date(): void
    {
        [$design] = $this->active();
        $this->topUp($this->owner, 1_000_000);
        $this->join($this->omar, 'delegate', ['max_amount_minor' => 500000, 'days' => 2]);
        Carbon::setTestNow(now()->addDays(3));

        $this->as($this->omar);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertOk()->assertJsonPath('data.access.delegation_over', true)
            ->assertJsonPath('data.milestones.0.can.fund', false)->assertJsonPath('data.price_minor', 350000);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$design}/fund")->assertForbidden()->assertJsonPath('error_code', 'freelance_team_delegation_over');
    }

    public function test_a_change_is_answered_by_a_delegate_up_to_his_limit(): void
    {
        [, $build] = $this->active();
        $this->join($this->omar, 'delegate', ['max_amount_minor' => 20000, 'days' => 7]);
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/changes", ['type' => 'milestone_price', 'milestone' => $build, 'amount_minor' => 300000, 'reason' => 'More pages than planned.'])->assertCreated();
        $change = \Modules\Freelance\Models\FreelanceChangeRequest::query()->value('uuid');

        $this->as($this->omar);
        // The button follows the limit, and says why it is off.
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.changes.0.can.accept', false)
            ->assertJsonPath('data.changes.0.can.reject', true)->assertJsonPath('data.changes.0.over_limit', true);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/changes/{$change}/accept")->assertForbidden()->assertJsonPath('error_code', 'freelance_team_over_limit');
        // A part's new price is an amount too: hidden from a role without money (the delegate sees it).
        $this->assertSame(300000, $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->json('data.changes.0.payload.to'));
        $this->join($this->huda, 'reviewer');
        $this->as($this->huda);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertJsonPath('data.changes.0.payload.to', null)->assertJsonPath('data.changes.0.payload.from', null);

        $this->as($this->omar);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/changes/{$change}/reject")->assertOk();
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.team.acted_change_reject')->count());
    }

    public function test_a_manager_proposes_changes_and_sorts_offers_without_money(): void
    {
        $this->join($this->omar, 'manager');
        $offer = FreelanceOffer::query()->value('uuid');
        $this->as($this->omar);
        $list = $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->assertNull(data_get($list->json('data'), '0.price_minor'));
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$offer}/shortlist")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$offer}/choose")->assertForbidden();
        $this->assertSame($this->omar->id, (int) DB::table('freelance_offer_events')->where('type', 'like', '%shortlist%')->value('acted_by_user_id'));

        [, $build] = $this->active();
        $this->as($this->omar);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/changes", ['type' => 'extend_days', 'days' => 5, 'reason' => 'The content comes late.'])->assertCreated();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/milestones/{$build}/fund")->assertForbidden();
    }

    public function test_taking_someone_out_or_leaving_ends_access_at_once(): void
    {
        $this->join($this->omar, 'collaborator');
        $this->join($this->huda, 'collaborator');
        $member = FreelanceProjectMember::query()->where('user_id', $this->omar->id)->value('uuid');

        $this->as($this->owner);
        $this->putJson(self::CLIENT."/{$this->project->uuid}/team/{$member}", ['role' => 'manager'])->assertOk();
        $this->assertSame(1, $this->omar->notifications()->where('data->event', 'freelance.team.role_changed')->count());
        $this->deleteJson(self::CLIENT."/{$this->project->uuid}/team/{$member}")->assertOk()->assertJsonCount(1, 'data.members');
        $this->as($this->omar);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertNotFound();
        $this->getJson(self::TEAM.'/projects')->assertJsonCount(0, 'data');

        $this->as($this->huda);
        $this->postJson(self::TEAM."/projects/{$this->project->uuid}/leave")->assertOk();
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertNotFound();
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'freelance.team.left')->count());
    }

    public function test_the_feature_switch_closes_the_team(): void
    {
        $this->join($this->omar, 'collaborator');
        FreelanceFeatureRule::query()->where('feature', 'client_team')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->as($this->omar);
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertNotFound();
        $this->getJson(self::TEAM.'/projects')->assertForbidden();
    }
}
