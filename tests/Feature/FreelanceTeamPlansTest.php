<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\FreelanceTeamSubscription as TeamSub;
use Modules\Freelance\Models\FreelanceTeamSubscriptionInvoice as TeamInvoice;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamPlans;
use Modules\Freelance\Services\FreelancerTeam\TeamSubscriptions;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Policy\FreelancePolicyData;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FS-211: a freelancers' team's paid plan (Team Starter and the ones after it). Priced per team, never per member, and taken by an Owner out of
 * his own wallet (D7). A plan raises what the country's policy gives - the people, the projects running at once, the offers in a month - and
 * never lowers it; a limit stops what is new and never cuts what is there. The plan has the freelancers' plan life (trial once, upgrade now with
 * the unused time taken off, downgrade at the end of the period, renewal with grace days) and stays apart from the freelancers' own plans.
 */
class FreelanceTeamPlansTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const ADMIN = '/api/admin/v1';

    private Country $saudi;

    private User $client;

    private FreelanceSpecialty $web;

    private FreelanceTeam $team;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $huda;

    private FreelancePlan $plus;

    private FreelancePlan $starter;

    private FreelancePlan $growth;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment', 'free_offer_quota', 'commission', 'teams', 'subscriptions'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);

        $this->sara = $this->freelancer('+966500000091', 'Sara Ali');
        $this->lina = $this->freelancer('+966500000092', 'Lina Hassan');
        $this->noor = $this->freelancer('+966500000093', 'Noor Said');
        $this->huda = $this->freelancer('+966500000094', 'Huda Omar');
        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, Member::PM);
        $teams->join($this->team, $this->noor);

        // A freelancer's plan (to prove the two audiences stay apart) and two team plans.
        FreelancePlan::query()->create(['slug' => 'free', 'rank' => 0, 'name' => ['ar' => 'مجانية', 'en' => 'Free']]);
        $this->plus = FreelancePlan::query()->create(['slug' => 'plus', 'rank' => 1, 'name' => ['ar' => 'بلس', 'en' => 'Plus'], 'offers_monthly' => 40, 'commission_bp' => 800, 'trial_days' => 14]);
        $this->starter = FreelancePlan::query()->create([
            'slug' => 'team_starter', 'audience' => FreelancePlan::TEAM, 'rank' => 1, 'name' => ['ar' => 'ستارتر للفرق', 'en' => 'Team Starter'],
            'offers_monthly' => 60, 'team_members_max' => 10, 'team_projects_max' => 5, 'trial_days' => 7,
        ]);
        $this->growth = FreelancePlan::query()->create([
            'slug' => 'team_growth', 'audience' => FreelancePlan::TEAM, 'rank' => 2, 'name' => ['ar' => 'نمو للفرق', 'en' => 'Team Growth'],
            'offers_unlimited' => true, 'team_members_max' => 25, 'team_projects_max' => 20, 'trial_days' => 0,
        ]);
        foreach ([[$this->plus, 3400, 32500], [$this->starter, 2900, 27000], [$this->growth, 7900, 75000]] as [$plan, $monthly, $yearly]) {
            FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'period' => 'monthly', 'price_minor' => $monthly]);
            FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'period' => 'yearly', 'price_minor' => $yearly]);
        }

        // The free baseline: three people, one project at a time, two offers a month. Without these the plans open nothing and are not sold.
        $this->policy(['teams.members_max' => 3, 'teams.projects_max' => 1, 'teams.offers_monthly' => 2]);
    }

    // ------------------------------------------------------------------ helpers

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

    /** What the country's policy holds now: `save()` replaces the whole set, so each call adds to this and saves it all. */
    private array $policyData = [];

    /** @param  array<string, mixed>  $values */
    private function policy(array $values): void
    {
        $this->policyData = $values + $this->policyData;
        app(FreelancePolicies::class)->save($this->saudi->id, null, $this->policyData);
        FreelancePolicies::forget();
    }

    private function url(string $tail = ''): string
    {
        return self::API."/teams/{$this->team->uuid}/subscription".$tail;
    }

    private function topUp(Freelancer $who, int $minor): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($who, $this->saudi), $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    private function balance(Freelancer $who): int
    {
        return (int) app(WalletService::class)->firstOrCreateWallet($who, $this->saudi)->fresh()->withdrawable_minor;
    }

    /** The Owner takes a plan for the team over the API. */
    private function take(string $plan, string $period = 'monthly', ?Freelancer $by = null)
    {
        $this->as($by ?? $this->sara);

        return $this->postJson($this->url(), ['plan' => $plan, 'period' => $period]);
    }

    /** The team's one trial is used: an old, ended subscription is on record. */
    private function trialUsed(): void
    {
        TeamSub::query()->create([
            'team_id' => $this->team->id, 'paid_by_freelancer_id' => $this->sara->id, 'plan_id' => $this->starter->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR',
            'period' => 'monthly', 'price_minor' => 2900, 'status' => 'expired', 'auto_renew' => false, 'started_at' => now()->subMonths(3), 'period_start' => now()->subMonths(3),
            'period_end' => now()->subMonths(2), 'ended_at' => now()->subMonths(2),
        ]);
    }

    private function live(): ?TeamSub
    {
        return TeamSub::query()->live()->where('team_id', $this->team->id)->first();
    }

    /** A second Owner (lina, who was the PM): the plan is every Owner's to read and cancel, and only its payer's to spend on. */
    private function lina_owns(): void
    {
        Member::query()->where(['team_id' => $this->team->id, 'freelancer_id' => $this->lina->id])->update(['role' => Member::OWNER]);
    }

    private function told(Freelancer $who, string $event): int
    {
        return $who->notifications()->where('data->event', $event)->count();
    }

    private function refused(callable $do, string $code): void
    {
        try {
            $do();
            $this->fail("expected {$code}");
        } catch (FreelanceException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function project(string $title = 'A booking website'): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    /** The Owner offers in the team's name, with two people on it. */
    private function sendTeam(FreelanceProject $p)
    {
        return $this->postJson(self::API."/projects/{$p->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'We built three booking sites like this; we will deliver in three steps with a demo each week.',
            'team' => $this->team->uuid,
            'staff' => [['freelancer' => $this->lina->uuid, 'role' => 'pm', 'responsibility' => 'Runs the project'], ['freelancer' => $this->noor->uuid, 'role' => 'executor', 'responsibility' => 'Builds the screens']],
        ]);
    }

    /** The client chooses the team's offer, sends the agreement and the Owner approves it: one project running. */
    private function active(FreelanceProject $p): void
    {
        $this->as($this->sara);
        $uuid = $this->sendTeam($p)->assertCreated()->json('data.uuid');
        $this->asClient();
        $this->getJson(self::CLIENT."/{$p->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/offers/{$uuid}/choose")->assertOk();
        $this->postJson(self::CLIENT."/{$p->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::API."/projects/{$p->uuid}/agreement/approve")->assertOk()->assertJsonPath('data.status', 'active');
    }

    // ------------------------------------------------------------------ apart from the freelancers' plans

    public function test_a_team_plan_is_never_sold_to_a_freelancer_and_a_freelancer_plan_never_to_a_team(): void
    {
        $this->topUp($this->sara, 100000);

        // The freelancer's own catalog shows his plans only.
        $this->as($this->sara);
        $this->assertSame(['free', 'plus'], array_column($this->getJson(self::API.'/plans')->assertOk()->json('data.plans'), 'slug'));
        $this->postJson(self::API.'/subscription', ['plan' => 'team_starter', 'period' => 'monthly'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_plan_unavailable');

        // The team's catalog shows its plans only (the free baseline first).
        $slugs = array_column($this->getJson($this->url())->assertOk()->json('data.catalog.plans'), 'slug');
        $this->assertSame(['free', 'team_starter', 'team_growth'], $slugs);
        $this->postJson($this->url(), ['plan' => 'plus', 'period' => 'monthly'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_plan_unavailable');
        $this->postJson($this->url(), ['plan' => 'nope', 'period' => 'monthly'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_plan_unavailable');
        $this->postJson($this->url(), ['plan' => 'team_starter', 'period' => 'weekly'])->assertStatus(422);
        $this->assertSame(0, TeamSub::query()->count());
        $this->assertSame(0, FreelanceSubscription::query()->count());

        // A freelancer's subscription and a team's are different rows: the team on a plan does not move the Owner's own.
        $this->take('team_growth')->assertOk();
        $this->assertSame(0, FreelanceSubscription::query()->count());
        $this->assertSame('free', app(\Modules\Freelance\Services\Plans\FreelancePlans::class)->planFor($this->sara)->slug);
    }

    // ------------------------------------------------------------------ who may

    public function test_only_an_owner_reads_and_takes_the_plan_and_where_the_switches_are_on(): void
    {
        $this->topUp($this->sara, 100000);

        $this->as($this->lina); // the PM
        $this->getJson($this->url())->assertForbidden()->assertJsonPath('error_code', 'freelance_team_plan_forbidden');
        $this->take('team_growth', by: $this->lina)->assertForbidden()->assertJsonPath('error_code', 'freelance_team_plan_forbidden');
        $this->take('team_growth', by: $this->noor)->assertForbidden()->assertJsonPath('error_code', 'freelance_team_plan_forbidden');
        $this->as($this->huda); // outside the team
        $this->getJson($this->url())->assertNotFound();
        $this->take('team_growth', by: $this->huda)->assertNotFound();
        $this->assertSame(0, TeamSub::query()->count());

        // A suspended team does not take a plan.
        $this->team->update(['status' => FreelanceTeam::SUSPENDED]);
        $this->take('team_growth')->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_inactive');
        $this->team->update(['status' => FreelanceTeam::ACTIVE]);

        // Either switch off closes it.
        foreach (['subscriptions', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->where('feature', $feature)->update(['enabled' => false]);
            FreelanceFeatures::forget();
            $this->as($this->sara);
            $this->getJson($this->url())->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
            FreelanceFeatureRule::query()->where('feature', $feature)->update(['enabled' => true]);
            FreelanceFeatures::forget();
        }
        $this->take('team_growth')->assertOk();
    }

    // ------------------------------------------------------------------ what is on sale

    public function test_a_plan_is_sold_only_where_it_opens_more_room_than_the_free_baseline(): void
    {
        $this->topUp($this->sara, 100000);

        // No policy at all: nothing is capped, so no plan has anything to lift and nothing is sold.
        $this->policy(['teams.members_max' => null, 'teams.projects_max' => null, 'teams.offers_monthly' => null]);
        $this->as($this->sara);
        $this->assertSame(['free'], array_column($this->getJson($this->url())->assertOk()->json('data.catalog.plans'), 'slug'));
        $this->take('team_starter')->assertStatus(422)->assertJsonPath('error_code', 'freelance_plan_unavailable');

        // Only the offers are capped: both plans lift that (Starter to 60, Growth to no cap), so both are sold, each with what it opens.
        $this->policy(['teams.offers_monthly' => 2]);
        $plans = collect($this->getJson($this->url())->assertOk()->json('data.catalog.plans'))->keyBy('slug');
        $this->assertSame(['free', 'team_starter', 'team_growth'], $plans->keys()->all());
        $this->assertSame(['members_max' => null, 'projects_max' => null, 'offers_monthly' => 2], $plans['free']['benefits']);
        $this->assertSame(['members_max' => null, 'projects_max' => null, 'offers_monthly' => 60], $plans['team_starter']['benefits']);
        $this->assertSame(['members_max' => null, 'projects_max' => null, 'offers_monthly' => null], $plans['team_growth']['benefits']);

        // All three capped: a plan shows the bigger number of its own and the policy's.
        $this->policy(['teams.members_max' => 3, 'teams.projects_max' => 1, 'teams.offers_monthly' => 2]);
        $plans = collect($this->getJson($this->url())->json('data.catalog.plans'))->keyBy('slug');
        $this->assertSame(['members_max' => 10, 'projects_max' => 5, 'offers_monthly' => 60], $plans['team_starter']['benefits']);
        $this->assertSame(['members_max' => 25, 'projects_max' => 20, 'offers_monthly' => null], $plans['team_growth']['benefits']);
        $this->assertSame([2900, 27000], [$plans['team_starter']['prices']['monthly'], $plans['team_starter']['prices']['yearly']]);
        $this->assertSame(2900 * 12 - 27000, $plans['team_starter']['yearly_saving_minor']);
        $this->assertSame([7, 0], [$plans['team_starter']['trial_days'], $plans['team_growth']['trial_days']]);
        $this->assertTrue($plans['free']['current']);

        // A plan that is switched off, or has no price, is not on sale.
        $this->growth->update(['status' => false]);
        FreelancePlanPrice::query()->where('plan_id', $this->starter->id)->delete();
        $this->assertSame(['free'], array_column($this->getJson($this->url())->json('data.catalog.plans'), 'slug'));
    }

    // ------------------------------------------------------------------ taking a plan

    public function test_taking_a_plan_charges_the_owner_wallet_and_leaves_an_invoice_the_ledger_and_an_audit_line(): void
    {
        $this->topUp($this->sara, 10000);
        $income = fn () => (int) FinancialEntry::query()->whereHas('category', fn ($q) => $q->where('slug', 'freelance_subscriptions'))->sum('amount_minor');

        $res = $this->take('team_growth')->assertOk()
            ->assertJsonPath('data.subscription.plan.slug', 'team_growth')->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.subscription.active', true)
            ->assertJsonPath('data.subscription.price_minor', 7900)->assertJsonPath('data.subscription.paid_by.name', 'Sara Ali')->assertJsonPath('data.subscription.paid_by_me', true)
            ->assertJsonPath('data.subscription.auto_renew', true)->assertJsonPath('data.subscription.pending', null)
            ->assertJsonPath('data.usage.plan.slug', 'team_growth')->assertJsonPath('data.usage.plan.free', false)
            ->assertJsonPath('data.usage.members.used', 3)->assertJsonPath('data.usage.members.max', 25)
            ->assertJsonPath('data.usage.projects.used', 0)->assertJsonPath('data.usage.projects.max', 20)->assertJsonPath('data.usage.offers.limit', null)
            ->assertJsonPath('data.invoices.0.kind', 'purchase')->assertJsonPath('data.invoices.0.status', 'paid')->assertJsonPath('data.invoices.0.amount_minor', 7900)
            ->assertJsonPath('data.catalog.plans.2.current', true)->assertJsonPath('data.catalog.plans.0.current', false);
        $this->assertNotNull($res->json('data.subscription.period_end'));
        $this->assertNull($res->json('data.subscription.trial_ends_at'));

        $this->assertSame(10000 - 7900, $this->balance($this->sara));
        $this->assertSame(7900, $income(), 'the platform books the plan as income');
        $invoice = TeamInvoice::query()->firstOrFail();
        $this->assertSame([$this->team->id, $this->sara->id, 7900, 0], [$invoice->team_id, $invoice->paid_by_freelancer_id, $invoice->amount_minor, $invoice->credit_minor]);
        $this->assertNotNull($invoice->wallet_transaction_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'freelance.team.plan_taken')->count());
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_started'));

        // One live plan per team: a second tap on the same plan changes nothing and costs nothing.
        $this->take('team_growth')->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_subscription_unchanged');
        $this->assertSame(1, TeamInvoice::query()->count());
        $this->assertSame(2100, $this->balance($this->sara));

        // The other Owners read the same plan and see whose wallet pays.
        $this->lina_owns();
        $this->as($this->lina);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.subscription.paid_by.name', 'Sara Ali')->assertJsonPath('data.subscription.paid_by_me', false);
    }

    public function test_a_wallet_with_too_little_takes_nothing(): void
    {
        $this->topUp($this->sara, 500);

        $this->take('team_growth')->assertStatus(422)->assertJsonPath('error_code', 'freelance_subscription_insufficient')
            ->assertJsonPath('data.shortfall_minor', 7400)->assertJsonPath('data.needed_minor', 7900);
        $this->assertSame(0, TeamSub::query()->count());
        $this->assertSame(0, TeamInvoice::query()->count());
        $this->assertSame(500, $this->balance($this->sara));
        $this->assertNull(app(TeamPlans::class)->plan($this->team));
    }

    public function test_the_first_plan_starts_with_a_free_trial_once_and_later_ones_are_paid(): void
    {
        $this->topUp($this->sara, 10000);

        $this->take('team_starter')->assertOk()->assertJsonPath('data.subscription.status', 'trialing')->assertJsonPath('data.subscription.active', true)
            ->assertJsonPath('data.invoices', [])->assertJsonPath('data.catalog.plans.1.current', true);
        $this->assertSame(10000, $this->balance($this->sara), 'a trial costs nothing');
        $trial = $this->live();
        $this->assertTrue($trial->trial_ends_at->isSameDay(now()->addDays(7)));
        $this->assertSame(10, app(TeamPlans::class)->membersMax($this->team), 'and gives the plan at once');

        // Switching plan during the trial is free too.
        $this->take('team_growth')->assertOk()->assertJsonPath('data.subscription.plan.slug', 'team_growth')->assertJsonPath('data.subscription.status', 'trialing');
        $this->assertSame(10000, $this->balance($this->sara));

        // The trial is used up once: after it ended, the same plan is bought, not tried again.
        $this->live()->update(['status' => 'expired', 'ended_at' => now()]);
        $this->as($this->sara);
        $this->assertSame(0, (int) collect($this->getJson($this->url())->json('data.catalog.plans'))->firstWhere('slug', 'team_starter')['trial_days']);
        $this->take('team_starter')->assertOk()->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.invoices.0.amount_minor', 2900);
        $this->assertSame(7100, $this->balance($this->sara));
    }

    // ------------------------------------------------------------------ what a plan changes

    public function test_a_plan_raises_the_policy_never_lowers_it_and_leaves_no_cap_where_there_is_none(): void
    {
        $plans = app(TeamPlans::class);
        $limits = fn (?FreelancePlan $plan) => $plans->limitsFor($this->team, $plan);

        // Under the policy of 3 / 1 / 2: Starter lifts every number, Growth leaves the offers uncapped.
        $this->assertSame(['members' => 3, 'projects' => 1, 'offers' => 2], $limits(null));
        $this->assertSame(['members' => 10, 'projects' => 5, 'offers' => 60], $limits($this->starter));
        $this->assertSame(['members' => 25, 'projects' => 20, 'offers' => null], $limits($this->growth));

        // A policy above the plan's number stays: the plan never lowers it.
        $this->policy(['teams.members_max' => 40, 'teams.projects_max' => 30, 'teams.offers_monthly' => 100]);
        $this->assertSame(['members' => 40, 'projects' => 30, 'offers' => 100], $limits($this->starter));
        $this->assertFalse($plans->raises($this->team, $this->starter), 'a plan that opens nothing more is not sold');
        $this->assertTrue($plans->raises($this->team, $this->growth), 'Growth still lifts the offers');

        // A policy with no cap stays with no cap, whatever the plan says.
        $this->policy(['teams.members_max' => null, 'teams.projects_max' => null, 'teams.offers_monthly' => null]);
        $this->assertSame(['members' => null, 'projects' => null, 'offers' => null], $limits($this->starter));
        $this->assertFalse($plans->raises($this->team, $this->growth));

        // A plan with no number of its own for a thing it does not lift leaves the policy's number there.
        $this->policy(['teams.members_max' => 3, 'teams.projects_max' => 1, 'teams.offers_monthly' => 2]);
        $this->starter->update(['team_members_max' => null, 'team_projects_max' => null, 'offers_monthly' => null]);
        $this->assertSame(['members' => null, 'projects' => null, 'offers' => 2], $limits($this->starter->refresh()));
    }

    public function test_the_members_limit_stops_a_new_person_and_the_plan_makes_room_for_one(): void
    {
        $this->topUp($this->sara, 10000);
        $teams = app(FreelancerTeams::class);

        // Three people, the policy's cap of three: a fourth is refused.
        $this->refused(fn () => $teams->join($this->team, $this->huda), 'freelancer_team_limit_members');

        // On Starter the team holds ten.
        $this->take('team_starter')->assertOk();
        $teams->join($this->team, $this->huda);
        $this->assertSame(4, $this->team->activeMembers()->count());
        $this->as($this->sara);
        $this->getJson($this->url())->assertJsonPath('data.usage.members.used', 4)->assertJsonPath('data.usage.members.max', 10);

        // The plan ends: nobody is removed, but nobody new comes in.
        $this->live()->update(['status' => 'expired', 'ended_at' => now()]);
        $this->assertSame(4, $this->team->activeMembers()->count());
        $this->assertSame(3, app(TeamPlans::class)->membersMax($this->team));
        $extra = $this->freelancer('+966500000095', 'Dina Fahd');
        $this->refused(fn () => $teams->join($this->team, $extra), 'freelancer_team_limit_members');
    }

    public function test_the_projects_limit_stops_a_new_offer_but_never_a_running_project(): void
    {
        $this->topUp($this->sara, 10000);
        [$p1, $p2, $p3] = [$this->project('One'), $this->project('Two'), $this->project('Three')];

        // One project running is the policy's limit of one.
        $this->active($p1);
        $this->assertSame(1, FreelanceAgreement::query()->where('team_id', $this->team->id)->where('status', FreelanceAgreement::ACTIVE)->count());
        $this->as($this->sara);
        $this->getJson(self::API."/projects/{$p2->uuid}/offer-preview?team={$this->team->uuid}")->assertOk()->assertJsonPath('data.blocked.code', 'freelance_team_projects_limit');
        $this->sendTeam($p2)->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_projects_limit');
        $this->getJson($this->url())->assertJsonPath('data.usage.projects.used', 1)->assertJsonPath('data.usage.projects.max', 1);

        // The Owner's own offers are not the team's: they are not stopped by it.
        $this->postJson(self::API."/projects/{$p3->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => 'A solo offer, in my own name, with a clear plan and a fair price for the work.'])->assertCreated();

        // On Starter the team runs five: the offer goes out and the running project is untouched.
        $this->take('team_starter')->assertOk();
        $this->getJson(self::API."/projects/{$p2->uuid}/offer-preview?team={$this->team->uuid}")->assertOk()->assertJsonPath('data.blocked', null);
        $this->sendTeam($p2)->assertCreated();
        $this->assertSame(1, FreelanceAgreement::query()->where('team_id', $this->team->id)->where('status', FreelanceAgreement::ACTIVE)->count());

        // The plan ends with a project running: it goes on, and nothing new starts until it is done.
        $this->live()->update(['status' => 'expired', 'ended_at' => now()]);
        $this->assertSame(1, app(TeamPlans::class)->running($this->team));
        $this->refused(fn () => app(TeamPlans::class)->assertProjectsRoom($this->team), 'team_projects_limit');
    }

    public function test_the_offers_quota_follows_the_plan(): void
    {
        $this->topUp($this->sara, 20000);
        [$p1, $p2, $p3] = [$this->project('One'), $this->project('Two'), $this->project('Three')];
        $this->policy(['teams.projects_max' => null]);

        $this->as($this->sara);
        $this->sendTeam($p1)->assertCreated();
        $this->sendTeam($p2)->assertCreated();
        $this->sendTeam($p3)->assertStatus(422)->assertJsonPath('error_code', 'freelance_team_offer_quota_exhausted');

        // Growth lifts the cap: no limit at all.
        $this->take('team_growth')->assertOk();
        $this->getJson(self::API."/projects/{$p3->uuid}/offer-preview?team={$this->team->uuid}")->assertOk()
            ->assertJsonPath('data.team_quota.limit', null)->assertJsonPath('data.blocked', null);
        $this->sendTeam($p3)->assertCreated();
    }

    // ------------------------------------------------------------------ upgrade, downgrade, cancel

    public function test_an_upgrade_is_at_once_with_the_unused_time_taken_off_and_a_downgrade_waits_for_the_end(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->trialUsed();
        $this->topUp($this->sara, 100000);

        $this->take('team_starter')->assertOk()->assertJsonPath('data.subscription.status', 'active');
        $this->assertSame(97100, $this->balance($this->sara));

        // Better plan: now. Nothing of the first period is used yet, so all 2,900 is credited and the difference is charged.
        $this->take('team_growth')->assertOk()->assertJsonPath('data.subscription.plan.slug', 'team_growth')->assertJsonPath('data.subscription.price_minor', 7900)
            ->assertJsonPath('data.invoices.0.kind', 'upgrade')->assertJsonPath('data.invoices.0.credit_minor', 2900)->assertJsonPath('data.invoices.0.amount_minor', 5000);
        $this->assertSame(97100 - 5000, $this->balance($this->sara));
        $this->assertSame(1, TeamSub::query()->live()->count(), 'the same subscription moved up: not a second one');
        $this->assertSame(25, app(TeamPlans::class)->membersMax($this->team), 'and the room is there at once');

        // Lower plan: it waits; until the period ends the team keeps Growth and nothing is charged.
        $this->take('team_starter')->assertOk()->assertJsonPath('data.subscription.plan.slug', 'team_growth')->assertJsonPath('data.subscription.pending.plan.slug', 'team_starter');
        $this->assertSame(92100, $this->balance($this->sara));
        $this->assertSame(25, app(TeamPlans::class)->membersMax($this->team));

        // Choosing the plan it is on takes the waiting change back.
        $this->take('team_growth')->assertOk()->assertJsonPath('data.subscription.pending', null);

        // The same change again, and then the end of the period: it renews into Starter at Starter's price.
        $this->take('team_starter')->assertOk();
        $this->travelTo($this->live()->period_end->copy()->addMinute());
        $this->assertSame(['renewed' => 1, 'failed' => 0, 'ended' => 0, 'reminded' => 0], app(TeamSubscriptions::class)->run());
        $this->as($this->sara);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.subscription.plan.slug', 'team_starter')->assertJsonPath('data.subscription.pending', null)
            ->assertJsonPath('data.invoices.0.kind', 'renewal')->assertJsonPath('data.invoices.0.amount_minor', 2900);
        $this->assertSame(92100 - 2900, $this->balance($this->sara));
        $this->assertSame(10, app(TeamPlans::class)->membersMax($this->team));
    }

    public function test_monthly_to_yearly_is_an_upgrade_and_yearly_to_monthly_waits(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->trialUsed();
        $this->topUp($this->sara, 100000);

        $this->take('team_starter', 'monthly')->assertOk();
        $this->take('team_starter', 'yearly')->assertOk()->assertJsonPath('data.subscription.period', 'yearly')->assertJsonPath('data.invoices.0.kind', 'upgrade')
            ->assertJsonPath('data.invoices.0.credit_minor', 2900)->assertJsonPath('data.invoices.0.amount_minor', 27000 - 2900);
        $this->take('team_starter', 'monthly')->assertOk()->assertJsonPath('data.subscription.period', 'yearly')->assertJsonPath('data.subscription.pending.period', 'monthly');
    }

    public function test_every_owner_cancels_but_only_the_payer_spends_on_the_plan(): void
    {
        $this->topUp($this->sara, 100000);
        $this->take('team_growth')->assertOk();
        $this->lina_owns();
        $this->topUp($this->lina, 100000);

        // The other Owner can switch renewal off (it costs nothing) but not change, resume or retry (those spend the payer's wallet).
        $this->as($this->lina);
        $this->postJson($this->url('/cancel'))->assertOk()->assertJsonPath('data.subscription.auto_renew', false)->assertJsonPath('data.subscription.paid_by_me', false);
        $this->postJson($this->url('/resume'))->assertForbidden()->assertJsonPath('error_code', 'freelance_team_plan_payer_only');
        $this->postJson($this->url(), ['plan' => 'team_starter', 'period' => 'monthly'])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_plan_payer_only');
        $this->postJson($this->url('/renew'))->assertStatus(409);
        $this->assertSame(100000, $this->balance($this->lina), 'her wallet is never touched');
        $this->assertSame(1, AuditLog::query()->where('action', 'freelance.team.plan_canceled')->count());

        // A plain member of the team cannot do any of it.
        $this->as($this->noor);
        $this->postJson($this->url('/cancel'))->assertForbidden()->assertJsonPath('error_code', 'freelance_team_plan_forbidden');

        // The payer brings renewal back; there is nothing to resume once it is on.
        $this->as($this->sara);
        $this->postJson($this->url('/resume'))->assertOk()->assertJsonPath('data.subscription.auto_renew', true)->assertJsonPath('data.subscription.canceled_at', null);
        $this->postJson($this->url('/resume'))->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_subscription_not_resumable');

        // A cancelled plan runs to the end of the paid period and then ends; the team is back on the free baseline.
        $this->postJson($this->url('/cancel'))->assertOk();
        $this->assertSame(25, app(TeamPlans::class)->membersMax($this->team));
        $this->travelTo($this->live()->period_end->copy()->addMinute());
        $this->assertSame(1, app(TeamSubscriptions::class)->run()['ended']);
        $this->assertSame(TeamSub::EXPIRED, TeamSub::query()->firstOrFail()->status);
        $this->assertSame(3, app(TeamPlans::class)->membersMax($this->team));
        $this->assertSame(1, TeamInvoice::query()->count(), 'nothing more was charged');
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_expired'));
        $this->assertSame(1, $this->told($this->lina, 'freelance.team_subscription_expired'), 'every Owner is told it ended');
        $this->assertSame(0, $this->told($this->noor, 'freelance.team_subscription_expired'));

        // Nothing is live: the app's cancel has nothing to act on.
        $this->postJson($this->url('/cancel'))->assertNotFound()->assertJsonPath('error_code', 'freelance_team_subscription_none');
    }

    // ------------------------------------------------------------------ the clock

    public function test_the_plan_renews_from_the_payers_wallet_and_reminds_before(): void
    {
        $this->trialUsed();
        $this->topUp($this->sara, 10000);
        $this->take('team_starter')->assertOk();
        $end = $this->live()->period_end->copy();

        // A few days before: told once that it renews (the payer only).
        $this->travelTo($end->copy()->subDays(2));
        $this->assertSame(1, app(TeamSubscriptions::class)->run()['reminded']);
        $this->assertSame(0, app(TeamSubscriptions::class)->run()['reminded']);
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_renewing'));
        $this->assertSame(0, $this->told($this->lina, 'freelance.team_subscription_renewing'));

        // At the end of the period: charged again, a new period, an invoice and a note.
        $this->travelTo($end->copy()->addMinute());
        $this->assertSame(1, app(TeamSubscriptions::class)->run()['renewed']);
        $sub = $this->live();
        $this->assertTrue($sub->period_end->gt($end->copy()->addDays(27)));
        $this->assertSame(10000 - 2900 - 2900, $this->balance($this->sara));
        $this->assertSame(['purchase', 'renewal'], TeamInvoice::query()->orderBy('id')->pluck('kind')->all());
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_renewed'));

        // A second pass in the same period does nothing.
        $this->assertSame(['renewed' => 0, 'failed' => 0, 'ended' => 0, 'reminded' => 0], app(TeamSubscriptions::class)->run());
    }

    public function test_a_renewal_the_wallet_cannot_pay_keeps_the_plan_for_the_grace_days_and_the_payer_can_pay_again(): void
    {
        $this->trialUsed();
        $this->topUp($this->sara, 2900); // exactly the first month
        $this->take('team_starter')->assertOk();
        $this->assertSame(0, $this->balance($this->sara));

        $this->travelTo($this->live()->period_end->copy()->addMinute());
        $this->assertSame(1, app(TeamSubscriptions::class)->run()['failed']);
        $sub = $this->live();
        $this->assertSame(TeamSub::PAST_DUE, $sub->status);
        $this->assertTrue($sub->grace_until->isSameDay(now()->addDays((int) config('freelance.plan_grace_days', 3))));
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_renewal_failed'));
        $failed = TeamInvoice::query()->where('status', 'failed')->firstOrFail();
        $this->assertSame(['renewal', 'subscription_insufficient', 2900], [$failed->kind, $failed->failure_code, $failed->amount_minor]);
        $this->assertSame(10, app(TeamPlans::class)->membersMax($this->team), 'the plan still gives its room during the grace days');

        // The next day it tries again and fails again, and the payer is not told twice.
        $this->travelTo(now()->addDay());
        $this->assertSame(0, app(TeamSubscriptions::class)->run()['failed']);
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_renewal_failed'));
        $this->assertSame(TeamSub::PAST_DUE, $this->live()->status);

        // While it waits the payer cannot change the plan; with money in the wallet "pay now" renews at once.
        $this->as($this->sara);
        $this->postJson($this->url('/renew'))->assertStatus(422)->assertJsonPath('error_code', 'freelance_subscription_insufficient');
        $this->take('team_growth')->assertStatus(409)->assertJsonPath('error_code', 'freelance_team_subscription_past_due');
        $this->topUp($this->sara, 5000);
        $this->postJson($this->url('/renew'))->assertOk()->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.subscription.grace_until', null);
        $this->assertSame(5000 - 2900, $this->balance($this->sara));
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_renewed'));
    }

    public function test_when_the_grace_days_pass_the_plan_ends_and_every_owner_is_told(): void
    {
        $this->trialUsed();
        $this->lina_owns();
        $this->topUp($this->sara, 2900);
        $this->take('team_starter')->assertOk();

        $this->travelTo($this->live()->period_end->copy()->addMinute());
        app(TeamSubscriptions::class)->run();
        $this->assertSame(TeamSub::PAST_DUE, $this->live()->status);

        $this->travelTo(now()->addDays((int) config('freelance.plan_grace_days', 3))->addHour());
        $this->assertSame(1, app(TeamSubscriptions::class)->run()['ended']);
        $this->assertNull($this->live());
        $this->assertSame(TeamSub::EXPIRED, TeamSub::query()->latest('id')->firstOrFail()->status);
        $this->assertNull(app(TeamPlans::class)->plan($this->team));
        $this->assertSame(3, app(TeamPlans::class)->membersMax($this->team), 'the team is back on the free baseline');
        $this->assertSame(1, $this->told($this->sara, 'freelance.team_subscription_expired'));
        $this->assertSame(1, $this->told($this->lina, 'freelance.team_subscription_expired'));
    }

    public function test_a_plan_whose_payer_is_no_longer_an_owner_ends_at_its_renewal_without_charging_anyone(): void
    {
        $this->trialUsed();
        $this->lina_owns();
        $this->topUp($this->sara, 20000);
        $this->topUp($this->lina, 20000);
        $this->take('team_starter')->assertOk();

        // Sara steps down: the one who agreed to pay is not an Owner any more.
        Member::query()->where(['team_id' => $this->team->id, 'freelancer_id' => $this->sara->id])->update(['role' => Member::PM]);
        $this->travelTo($this->live()->period_end->copy()->addMinute());
        $this->assertSame(1, app(TeamSubscriptions::class)->run()['ended']);

        $this->assertSame(TeamSub::EXPIRED, TeamSub::query()->latest('id')->firstOrFail()->status);
        $this->assertSame(1, TeamInvoice::query()->count(), 'nobody was charged for the renewal');
        $this->assertSame(20000 - 2900, $this->balance($this->sara));
        $this->assertSame(20000, $this->balance($this->lina));
        $this->assertSame(1, $this->told($this->lina, 'freelance.team_subscription_payer_left'), 'the Owner who is left is told, to take the plan again');
        $this->assertSame(0, $this->told($this->sara, 'freelance.team_subscription_payer_left'));
    }

    public function test_the_renewal_command_renews_the_teams_with_the_freelancers(): void
    {
        $this->trialUsed();
        $this->topUp($this->sara, 10000);
        $this->take('team_starter')->assertOk();

        $this->travelTo($this->live()->period_end->copy()->addMinute());
        $this->artisan('freelance:renew-subscriptions')->assertSuccessful();

        $this->assertSame(['purchase', 'renewal'], TeamInvoice::query()->orderBy('id')->pluck('kind')->all());
        $this->assertTrue($this->live()->period_end->isFuture());
    }

    // ------------------------------------------------------------------ the admin desk

    private function staff(string ...$permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    public function test_staff_see_both_kinds_of_plan_and_what_each_brought_in(): void
    {
        $this->topUp($this->sara, 100000);
        $this->take('team_growth')->assertOk(); // 7,900 from the team
        FreelanceSubscription::query()->create([
            'freelancer_id' => $this->sara->id, 'plan_id' => $this->plus->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR', 'period' => 'monthly', 'price_minor' => 3400,
            'status' => 'expired', 'auto_renew' => false, 'started_at' => now()->subMonths(3), 'period_start' => now()->subMonths(3), 'period_end' => now()->subMonths(2), 'ended_at' => now()->subMonths(2),
        ]);
        $this->as($this->sara);
        $this->postJson(self::API.'/subscription', ['plan' => 'plus', 'period' => 'monthly'])->assertOk(); // 3,400 from the freelancer

        $this->staff('freelance-plans.view', 'freelance-plans.update', 'freelance-teams.view');
        $res = $this->getJson(self::ADMIN.'/freelance-plans')->assertOk();
        $this->assertSame(['free', 'plus', 'team_starter', 'team_growth'], array_column($res->json('data.plans'), 'slug'), 'freelancer plans first, then the teams\'');
        $plans = collect($res->json('data.plans'))->keyBy('slug');
        $this->assertSame(['freelancer', 'team', 'team'], [$plans['plus']['audience'], $plans['team_starter']['audience'], $plans['team_growth']['audience']]);
        $this->assertSame([10, 5, 60], [$plans['team_starter']['team_members_max'], $plans['team_starter']['team_projects_max'], $plans['team_starter']['offers_monthly']]);
        $this->assertSame([1, 1, 0], [$plans['plus']['subscribers'], $plans['team_growth']['subscribers'], $plans['team_starter']['subscribers']]);
        $this->assertSame([1, 1], [$res->json('data.subscribers'), $res->json('data.team_subscribers')]);
        $this->assertSame([['currency' => 'SAR', 'decimals' => 2, 'total_minor' => 11300, 'invoices' => 2]], $res->json('data.revenue_30d'), 'both kinds of invoice are in the sum');

        // The teams on a plan, with the Owner who pays; the freelancers' list is untouched.
        $teams = $this->getJson(self::ADMIN.'/freelance-plans/subscriptions?audience=team')->assertOk();
        $row = $teams->json('data.0');
        $this->assertSame(['Dorr Studio', 'Sara Ali', 'team_growth', 'active'], [$row['team']['name'], $row['freelancer']['name'], $row['plan']['slug'], $row['status']]);
        $this->assertSame(1, $teams->json('pagination.counts.active'));
        $this->assertCount(1, $this->getJson(self::ADMIN.'/freelance-plans/subscriptions?audience=team&search=Studio')->json('data'));
        $this->assertCount(1, $this->getJson(self::ADMIN.'/freelance-plans/subscriptions?audience=team&search=Sara')->json('data'));
        $this->assertSame([], $this->getJson(self::ADMIN.'/freelance-plans/subscriptions?audience=team&search=nobody')->json('data'));
        $this->assertSame([], $this->getJson(self::ADMIN.'/freelance-plans/subscriptions?audience=team&status=expired')->json('data'));
        // (Sara's old Plus and her new one.)
        $this->assertSame(['plus', 'plus'], array_column(array_column($this->getJson(self::ADMIN.'/freelance-plans/subscriptions')->json('data'), 'plan'), 'slug'));
        $this->getJson(self::ADMIN.'/freelance-plans/subscriptions?audience=robots')->assertStatus(422);

        // The team's page shows its plan, what it holds against it, and who pays.
        $this->getJson(self::ADMIN."/freelance-teams/{$this->team->uuid}")->assertOk()
            ->assertJsonPath('data.plan.subscription.plan.slug', 'team_growth')->assertJsonPath('data.plan.subscription.paid_by.name', 'Sara Ali')
            ->assertJsonPath('data.plan.usage.members.used', 3)->assertJsonPath('data.plan.usage.members.max', 25)->assertJsonPath('data.members_max', 25);
    }

    public function test_staff_set_what_a_team_plan_opens_and_a_freelancer_plan_ignores_those_numbers(): void
    {
        $this->staff('freelance-plans.view', 'freelance-plans.update');

        $this->putJson(self::ADMIN.'/freelance-plans/'.$this->starter->id, ['team_members_max' => 12, 'team_projects_max' => 8, 'offers_monthly' => 70, 'commission_bp' => 500, 'badge' => true])->assertOk()
            ->assertJsonPath('data.team_members_max', 12)->assertJsonPath('data.team_projects_max', 8)->assertJsonPath('data.offers_monthly', 70);
        $starter = $this->starter->refresh();
        $this->assertSame([12, 8, 70], [$starter->team_members_max, $starter->team_projects_max, $starter->offers_monthly]);
        $this->assertNull($starter->commission_bp, 'a team plan has no commission');
        $this->assertFalse((bool) $starter->badge, 'and no badge');

        // Empty = no cap.
        $this->putJson(self::ADMIN.'/freelance-plans/'.$this->starter->id, ['team_projects_max' => null])->assertOk()->assertJsonPath('data.team_projects_max', null);
        $this->assertNull($this->starter->refresh()->team_projects_max);

        // The numbers are checked.
        foreach ([['team_members_max' => 0], ['team_members_max' => 1001], ['team_projects_max' => 0], ['team_projects_max' => 100001]] as $bad) {
            $this->putJson(self::ADMIN.'/freelance-plans/'.$this->starter->id, $bad)->assertStatus(422);
        }

        // A freelancer's plan has no room for teams: the numbers are dropped, its own are saved.
        $this->putJson(self::ADMIN.'/freelance-plans/'.$this->plus->id, ['team_members_max' => 9, 'team_projects_max' => 9, 'offers_monthly' => 50])->assertOk();
        $plus = $this->plus->refresh();
        $this->assertSame([null, null, 50], [$plus->team_members_max, $plus->team_projects_max, $plus->offers_monthly]);

        // A team on the plan reads the plan's numbers as they are now (a raise reaches it at once; a cut stops only what is new, never what is there).
        $this->topUp($this->sara, 100000);
        $this->take('team_starter')->assertOk();
        $this->staff('freelance-plans.view', 'freelance-plans.update');
        $this->putJson(self::ADMIN.'/freelance-plans/'.$this->starter->id, ['team_members_max' => 15])->assertOk();
        $this->assertSame(15, app(TeamPlans::class)->membersMax($this->team));
    }

    // ------------------------------------------------------------------ the data and the texts

    public function test_the_migration_goes_down_and_up_again(): void
    {
        $migration = require base_path('Modules/Freelance/database/migrations/2026_12_09_100000_create_freelance_team_plans.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('freelance_team_subscriptions'));
        $this->assertFalse(Schema::hasTable('freelance_team_subscription_invoices'));
        $this->assertFalse(Schema::hasColumn('freelance_plans', 'audience'));
        $this->assertFalse(Schema::hasColumn('freelance_plans', 'team_members_max'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('freelance_team_subscriptions'));
        $this->assertTrue(Schema::hasTable('freelance_team_subscription_invoices'));
        $this->assertTrue(Schema::hasColumns('freelance_plans', ['audience', 'team_members_max', 'team_projects_max']));
    }

    public function test_the_new_policy_and_every_text_of_the_plan_exist_in_both_languages(): void
    {
        $this->assertNull(FreelancePolicyData::defaults()['teams.projects_max'], 'no cap at first');
        foreach ([1, 100000] as $ok) {
            $this->policy(['teams.projects_max' => $ok]);
        }
        foreach ([0, 100001] as $bad) {
            $this->refused(fn () => app(FreelancePolicies::class)->save($this->saudi->id, null, ['teams.projects_max' => $bad]), 'policy_invalid');
        }

        foreach (['ar', 'en'] as $locale) {
            $text = json_encode(json_decode((string) file_get_contents(resource_path("js/locales/{$locale}.json")), true), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('"teams__projects_max"', (string) $text, "teams__projects_max is missing in {$locale}");

            foreach (['team_projects_limit', 'team_plan_forbidden', 'team_plan_payer_only', 'team_subscription_exists', 'team_subscription_none', 'team_subscription_unchanged',
                'team_subscription_past_due', 'team_subscription_not_resumable'] as $code) {
                $this->assertTrue(Lang::has("freelance.errors.{$code}", $locale, false), "{$code} is missing in {$locale}");
            }
            foreach (['free_name', 'free_description'] as $key) {
                $this->assertTrue(Lang::has("freelance.team_plans.{$key}", $locale, false), "team_plans.{$key} is missing in {$locale}");
            }
            foreach (['started', 'renewing', 'renewed', 'renewal_failed', 'expired', 'payer_left'] as $event) {
                foreach (['title', 'body'] as $part) {
                    $this->assertTrue(Lang::has("notifications.freelance_team_subscription_{$event}_{$part}", $locale, false), "freelance_team_subscription_{$event}_{$part} is missing in {$locale}");
                }
            }
        }
        $meanings = (new \ReflectionClassConstant(\Modules\Freelance\Services\Policy\FreelancePoliciesGuide::class, 'MEANINGS'))->getValue();
        $this->assertArrayHasKey('teams.projects_max', $meanings);
    }
}
