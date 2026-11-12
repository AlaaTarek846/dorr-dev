<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanFeature;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\FreelanceTeamSubscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamPlans;
use Modules\Freelance\Services\Plans\FreelancePerks;
use Modules\Freelance\Services\Plans\FreelancePlanDefaults;
use Modules\Freelance\Services\Plans\PlanFeatures;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Tests\TestCase;

/**
 * FS-705a: one catalog of what a plan gives (`freelance.plan_features`), read by `PlanFeatures` with one rule - a plan only ever makes a
 * feature better than the country gives with no plan. A team's plan counts on the team's work only and a person's plan on their own work
 * only (owner, 2026-11-12). A feature not wired yet is never a reason to sell a plan, and an upsell to a freelancer never names a team plan.
 */
class FreelancePlanFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Freelancer $sara;

    private Freelancer $lina;

    private FreelanceTeam $team;

    private FreelancePlan $free;

    private FreelancePlan $plus;

    private FreelancePlan $pro;

    private FreelancePlan $starter;

    private PlanFeatures $features;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'teams', 'subscriptions'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->features = app(PlanFeatures::class);

        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->lina = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000092', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->team = app(FreelancerTeams::class)->create($this->sara, 'Dorr Studio');
        app(FreelancerTeams::class)->join($this->team, $this->lina, Member::MEMBER);

        $this->free = FreelancePlan::query()->create(['slug' => 'free', 'rank' => 0, 'name' => ['ar' => 'مجانية', 'en' => 'Free']]);
        $this->plus = FreelancePlan::query()->create(['slug' => 'plus', 'rank' => 1, 'name' => ['ar' => 'بلس', 'en' => 'Plus'], 'offers_monthly' => 40, 'commission_bp' => 800, 'badge' => true, 'boost' => 1, 'analytics' => 1, 'crm' => 1, 'services_max' => 15]);
        $this->pro = FreelancePlan::query()->create(['slug' => 'pro', 'rank' => 2, 'name' => ['ar' => 'برو', 'en' => 'Pro'], 'offers_unlimited' => true, 'commission_bp' => 600, 'badge' => true, 'boost' => 2, 'analytics' => 2, 'crm' => 2, 'services_max' => 30]);
        // Team Starter at rank 1 like Plus, with Pro's CRM - the upsell to a freelancer must still never name it.
        $this->starter = FreelancePlan::query()->create([
            'slug' => 'team_starter', 'audience' => FreelancePlan::TEAM, 'rank' => 1, 'name' => ['ar' => 'ستارتر للفرق', 'en' => 'Team Starter'], 'status' => true,
            'offers_monthly' => 60, 'team_members_max' => 10, 'team_projects_max' => 5, 'commission_bp' => 600, 'badge' => true, 'boost' => 2, 'analytics' => 2, 'crm' => 2,
        ]);
        $priceCountry = app(FreelanceMoney::class)->countryId();
        foreach ([$this->plus, $this->pro, $this->starter] as $plan) {
            foreach (['monthly' => 3400, 'yearly' => 32500] as $period => $minor) {
                FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $priceCountry, 'period' => $period, 'price_minor' => $minor]);
            }
        }
    }

    /** @param  array<string, mixed>  $values */
    private function policy(array $values): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, $values);
        FreelancePolicies::forget();
    }

    private function subscribe(Freelancer $who, FreelancePlan $plan): void
    {
        FreelanceSubscription::query()->create([
            'freelancer_id' => $who->id, 'plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR', 'period' => 'monthly', 'price_minor' => 3400,
            'status' => FreelanceSubscription::ACTIVE, 'auto_renew' => true, 'started_at' => now(), 'period_start' => now(), 'period_end' => now()->addMonth(),
        ]);
    }

    private function subscribeTeam(FreelancePlan $plan): void
    {
        FreelanceTeamSubscription::query()->create([
            'team_id' => $this->team->id, 'paid_by_freelancer_id' => $this->sara->id, 'plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR',
            'period' => 'monthly', 'price_minor' => 2900, 'status' => 'active', 'auto_renew' => true, 'started_at' => now(), 'period_start' => now(), 'period_end' => now()->addMonth(),
        ]);
    }

    public function test_each_type_only_ever_gets_better_than_the_country_gives(): void
    {
        $this->policy(['services.max_active' => 20, 'consultations.max_active' => 5, 'commission.value' => 500, 'workroom.storage_mb' => null]);
        $country = $this->saudi->id;
        $value = fn (?FreelancePlan $plan, string $key) => $this->features->value($plan, $key, FreelancePlan::FREELANCER, $country);

        // No plan, or Free: the base. A switch is off, a level 0, a limit the policy's number, a fee the policy's.
        foreach ([null, $this->free] as $plan) {
            $this->assertSame([false, 0, 20, 5, 500], [$value($plan, 'badge'), $value($plan, 'crm'), $value($plan, 'services_max'), $value($plan, 'consultations_max'), $value($plan, 'commission_bp')]);
        }
        // A limit takes the larger: Plus says 15 services but the country already gives 20; a row of the catalog counts the same way.
        $this->features->set($this->plus, 'consultations_max', 10);
        $this->assertSame([true, 1, 20, 10], [$value($this->plus, 'badge'), $value($this->plus, 'crm'), $value($this->plus, 'services_max'), $value($this->plus, 'consultations_max')]);
        // A fee takes the lower: the country takes 5% and Plus says 8% - Plus never raises it.
        $this->assertSame(500, $value($this->plus, 'commission_bp'));
        $this->policy(['commission.value' => 1000]);
        $this->assertSame([800, 600], [$value($this->plus, 'commission_bp'), $value($this->pro, 'commission_bp')]);
        // Offers: Pro's "no limit" is no cap; a limit with no cap in the policy stays no cap whatever the plan says.
        $this->policy(['offers.free_monthly_quota' => 10]);
        $this->assertSame([10, 40, null], [$value(null, 'offers_monthly'), $value($this->plus, 'offers_monthly'), $value($this->pro, 'offers_monthly')]);
        $this->features->set($this->plus, 'workroom_storage_mb', 5120);
        $this->assertNull($value($this->plus, 'workroom_storage_mb'));
        $this->policy(['workroom.storage_mb' => 500]);
        $this->assertSame([500, 5120], [$value(null, 'workroom_storage_mb'), $value($this->plus, 'workroom_storage_mb')]);
        // A base from the config or a fixed number works the same.
        $this->assertSame([(int) config('freelance.profile.max_portfolio'), 10], [$value(null, 'portfolio_max'), $value(null, 'offer_templates_max')]);
    }

    public function test_a_teams_plan_counts_on_the_teams_work_and_a_persons_plan_on_their_own(): void
    {
        $this->policy(['teams.members_max' => 3, 'teams.projects_max' => 1, 'teams.offers_monthly' => 2]);
        $this->subscribe($this->sara, $this->plus);
        $this->subscribeTeam($this->starter);

        // Sara has Plus on her own work; the team she owns has Team Starter (Pro's CRM) on the team's work.
        $this->assertSame([1, 1], [$this->features->forFreelancer($this->sara, 'crm'), $this->features->forFreelancer($this->sara, 'boost')]);
        $this->assertSame([2, 2], [$this->features->forTeam($this->team, 'crm'), $this->features->forTeam($this->team, 'boost')]);
        // Lina is in the team and has no plan of her own: the team's plan gives her own account nothing.
        $this->assertSame([0, 0, false], [$this->features->forFreelancer($this->lina, 'crm'), $this->features->forFreelancer($this->lina, 'boost'), $this->features->forFreelancer($this->lina, 'badge')]);
        // The team's room reads the same catalog (FS-211's rule unchanged): the bigger of the policy and the plan.
        $this->assertSame(['members' => 10, 'projects' => 5, 'offers' => 60], app(TeamPlans::class)->limits($this->team));
        // A freelancer-only feature is never given by a team plan, and a team-only one never by a freelancer's.
        $this->assertSame(['given' => false, 'value' => null], $this->features->raw($this->starter, 'services_max'));
        $this->assertSame(['given' => false, 'value' => null], $this->features->raw($this->plus, 'team_members_max'));
    }

    public function test_a_plan_is_sold_only_for_what_works_today(): void
    {
        $limits = fn () => app(TeamPlans::class)->raises($this->team, $this->starter->refresh());

        // Every team policy open: the room has nothing to lift. Team Starter's badge, boost, numbers and CRM are not wired for a team yet, so
        // they do not count either - nobody pays for them before they work.
        $this->policy(['teams.members_max' => null, 'teams.projects_max' => null, 'teams.offers_monthly' => null]);
        $this->assertFalse($this->features->isLive('crm', FreelancePlan::TEAM));
        $this->assertFalse($limits());
        // One cap set: the plan lifts it, so it is sold.
        $this->policy(['teams.offers_monthly' => 2]);
        $this->assertTrue($limits());
    }

    public function test_an_upsell_to_a_freelancer_never_names_a_team_plan(): void
    {
        $perks = app(FreelancePerks::class);
        // Team Starter has CRM 2 and the numbers 2 at rank 1, Plus has 1 at rank 1: a freelancer with nothing is sent to Plus, and one on
        // level 1 to Pro.
        $this->assertSame('plus', $perks->planForCrm($this->sara, 0)['slug']);
        $this->assertSame('pro', $perks->planForCrm($this->sara, 1)['slug']);
        $this->assertSame('pro', $perks->planForAnalytics($this->sara, 1)['slug']);
        $this->assertNull($perks->planForCrm($this->sara, 2));
        // The same question for a team names the team's plan.
        $this->assertSame('team_starter', $this->features->lowestPlanBeyond(FreelancePlan::TEAM, 'crm', 0, $this->saudi->id)['slug']);
    }

    public function test_set_checks_each_value_by_type_and_audience(): void
    {
        $fails = function (callable $call): void {
            try {
                $call();
                $this->fail('A wrong value was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        };
        $fails(fn () => $this->features->set($this->plus, 'crm', 3));                     // above the level's max
        $fails(fn () => $this->features->set($this->plus, 'team_members_max', 10));       // a team's feature on a freelancer's plan
        $fails(fn () => $this->features->set($this->starter, 'services_max', 10));        // and the other way round
        $fails(fn () => $this->features->set($this->free, 'portfolio_max', 50));          // Free follows the policy
        $fails(fn () => $this->features->set($this->plus, 'commission_bp', 10001));
        $fails(fn () => $this->features->set($this->plus, 'badge', null));

        // A limit set to null is no cap; clearing it goes back to the base. A column feature lives in its column.
        $this->features->set($this->plus, 'portfolio_max', null);
        $this->assertSame(['given' => true, 'value' => null], $this->features->raw($this->plus, 'portfolio_max'));
        $this->features->set($this->plus, 'portfolio_max', null, clear: true);
        $this->assertSame(['given' => false, 'value' => null], $this->features->raw($this->plus, 'portfolio_max'));
        $this->features->set($this->plus, 'offers_monthly', null);
        $this->assertTrue($this->plus->refresh()->offers_unlimited);
        $this->features->set($this->plus, 'offers_monthly', 70);
        $this->assertSame([false, 70], [$this->plus->refresh()->offers_unlimited, $this->plus->offers_monthly]);
        $this->assertSame(0, FreelancePlanFeature::query()->where('key', 'offers_monthly')->count());
    }

    public function test_the_catalog_names_every_feature_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([FreelancePlan::FREELANCER, FreelancePlan::TEAM] as $audience) {
                foreach ($this->features->catalog($audience) as $row) {
                    $this->assertNotSame("freelance.plan_features.{$row['key']}.name", $row['name'], "{$locale}: {$row['key']}");
                    $this->assertNotNull($row['hint'], "{$locale}: {$row['key']}");
                    $this->assertContains($row['type'], PlanFeatures::TYPES);
                }
            }
        }
        $team = array_column($this->features->catalog(FreelancePlan::TEAM), 'live', 'key');
        $this->assertSame(['offers_monthly' => true, 'team_members_max' => true, 'team_projects_max' => true], array_filter($team));
        $this->assertArrayNotHasKey('services_max', $team);
    }

    public function test_the_defaults_add_the_starting_values_and_keep_the_admins(): void
    {
        FreelancePlan::query()->whereKey([$this->plus->id, $this->pro->id, $this->starter->id])->delete();
        app(FreelancePlanDefaults::class)->seed();

        $starter = FreelancePlan::query()->where('slug', 'team_starter')->firstOrFail();
        // Owner, 2026-11-12: Team Starter is offered, with Pro's perks on the team's work.
        $this->assertTrue($starter->status);
        $this->assertSame([600, true, 2, 2, 2], [$starter->commission_bp, $starter->badge, $starter->boost, $starter->analytics, $starter->crm]);
        $this->assertSame(['given' => true, 'value' => 30], $this->features->raw($starter, 'team_staff_max'));
        $plus = FreelancePlan::query()->where('slug', 'plus')->firstOrFail();
        $this->assertSame(['given' => true, 'value' => 50], $this->features->raw($plus, 'portfolio_max'));
        $this->assertSame(['given' => false, 'value' => null], $this->features->raw($plus, 'priority_support'));

        // The admin's value stays and a second run adds nothing.
        $this->features->set($plus, 'portfolio_max', 75);
        $this->assertSame(['plans' => 0, 'prices' => 0, 'features' => 0], app(FreelancePlanDefaults::class)->seed());
        $this->assertSame(['given' => true, 'value' => 75], $this->features->raw($plus->refresh(), 'portfolio_max'));
    }
}
