<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSanction;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Plans\FreelancePerks;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L01 phase 4: what a plan shows and opens beyond offers and commission. The badge by the name and the weight in the «recommended»
 * order (only that order) follow a subscription that gives its plan; more ready-made services at once; and «My performance», whose depth
 * is the plan's — a freelancer on Free gets `locked` and the plan that opens it, not an error.
 */
class FreelancePerksTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private Freelancer $sara;

    private Freelancer $lina;

    private FreelancePlan $free;

    private FreelancePlan $plus;

    private FreelancePlan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'invitations', 'services', 'subscriptions', 'free_offer_quota', 'commission', 'client_fee'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        // Lina comes before Sara by name, so a place ahead of her is the plan's doing.
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->lina = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000092', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);

        $this->free = FreelancePlan::query()->create(['slug' => 'free', 'rank' => 0, 'name' => ['ar' => 'مجانية', 'en' => 'Free']]);
        $this->plus = FreelancePlan::query()->create(['slug' => 'plus', 'rank' => 1, 'name' => ['ar' => 'بلس', 'en' => 'Plus'], 'offers_monthly' => 40, 'commission_bp' => 800, 'badge' => true, 'boost' => 1, 'analytics' => 1, 'services_max' => 15]);
        $this->pro = FreelancePlan::query()->create(['slug' => 'pro', 'rank' => 2, 'name' => ['ar' => 'برو', 'en' => 'Pro'], 'offers_unlimited' => true, 'commission_bp' => 600, 'badge' => true, 'boost' => 2, 'analytics' => 2, 'services_max' => 30]);
        foreach ([[$this->plus, 3400, 32500], [$this->pro, 9000, 86500]] as [$plan, $monthly, $yearly]) {
            FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'period' => 'monthly', 'price_minor' => $monthly]);
            FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'period' => 'yearly', 'price_minor' => $yearly]);
        }
    }

    // -------------------------------------------------------------- helpers

    private function as(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function staff(string ...$permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    /** A subscription that gives its plan now (override anything to take it away). */
    private function subscribe(Freelancer $f, FreelancePlan $plan, array $over = []): FreelanceSubscription
    {
        return FreelanceSubscription::query()->create($over + [
            'freelancer_id' => $f->id, 'plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR', 'period' => 'monthly', 'price_minor' => 3400,
            'status' => 'active', 'auto_renew' => true, 'started_at' => now()->subDays(3), 'period_start' => now()->subDays(3), 'period_end' => now()->addDays(27),
        ]);
    }

    private function perks(Freelancer $f): array
    {
        FreelanceFeatures::forget();

        // A new instance each time: the perks are remembered per instance only.
        return app(FreelancePerks::class)->of($f->refresh());
    }

    private function project(array $over = []): FreelanceProject
    {
        return FreelanceProject::query()->create($over + [
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    private function offer(FreelanceProject $p, Freelancer $f, string $status = 'submitted', int $daysAgo = 0, bool $viewed = false): FreelanceOffer
    {
        $offer = FreelanceOffer::query()->create([
            'project_id' => $p->id, 'freelancer_id' => $f->id, 'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21,
            'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.', 'status' => $status,
            'viewed_at' => $viewed ? now()->subDays($daysAgo) : null,
        ]);
        $offer->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $offer;
    }

    private function body(string $title): array
    {
        return [
            'title' => $title,
            'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'specialty_id' => $this->web->id,
            'tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea', 'PNG']]],
        ];
    }

    /** A service of this freelancer, sent and approved by the staff: published. */
    private function published(Freelancer $f, string $title): FreelanceService
    {
        $this->as($f);
        $uuid = $this->postJson(self::API.'/services', $this->body($title))->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk();
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk()->assertJsonPath('data.status', 'published');

        return FreelanceService::query()->where('uuid', $uuid)->firstOrFail();
    }

    // -------------------------------------------------------------- the staff

    public function test_staff_set_what_a_plan_gives_and_free_stays_off(): void
    {
        $this->staff('freelance-plans.view', 'freelance-plans.update');

        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['badge' => false, 'boost' => 3, 'analytics' => 2, 'services_max' => 20])
            ->assertOk()->assertJsonPath('data.boost', 3)->assertJsonPath('data.badge', false)->assertJsonPath('data.services_max', 20);
        $this->assertSame([false, 3, 2, 20], array_values($this->plus->refresh()->only(['badge', 'boost', 'analytics', 'services_max'])));
        // «null» gives back the country's own number.
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['services_max' => null])->assertOk();
        $this->assertNull($this->plus->refresh()->services_max);

        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['boost' => 4])->assertStatus(422);
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['analytics' => 3])->assertStatus(422);
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->plus->id, ['services_max' => 0])->assertStatus(422);

        // Free follows the policy: none of these.
        $this->putJson('/api/admin/v1/freelance-plans/'.$this->free->id, ['badge' => true, 'boost' => 3, 'analytics' => 2, 'services_max' => 50])->assertOk();
        $this->assertSame([false, 0, 0, null], array_values($this->free->refresh()->only(['badge', 'boost', 'analytics', 'services_max'])));

        $listed = collect($this->getJson('/api/admin/v1/freelance-plans')->assertOk()->json('data.plans'))->firstWhere('slug', 'pro');
        $this->assertSame([true, 2, 2, 30], [$listed['badge'], $listed['boost'], $listed['analytics'], $listed['services_max']]);
    }

    // -------------------------------------------------------------- who has the perks

    public function test_the_perks_come_only_from_a_subscription_that_gives_its_plan(): void
    {
        $this->assertSame(FreelancePerks::NONE, $this->perks($this->sara), 'no subscription: Free, nothing extra');

        $sub = $this->subscribe($this->sara, $this->plus);
        $this->assertSame(['plus', true, 1, 1, 15], array_values(array_intersect_key($this->perks($this->sara), array_flip(['plan', 'badge', 'boost', 'analytics', 'services_max']))));

        // A trial gives them too.
        $sub->update(['status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]);
        $this->assertSame('plus', $this->perks($this->sara)['plan']);

        // Past its end with no renewal coming: nothing. Waiting for the renewal (auto-renew on): still there.
        $sub->update(['status' => 'active', 'period_end' => now()->subHour(), 'auto_renew' => false]);
        $this->assertNull($this->perks($this->sara)['plan']);
        $sub->update(['auto_renew' => true]);
        $this->assertSame('plus', $this->perks($this->sara)['plan']);

        // A failed renewal keeps them through the grace days only.
        $sub->update(['status' => 'past_due', 'grace_until' => now()->addDay()]);
        $this->assertSame('plus', $this->perks($this->sara)['plan']);
        $sub->update(['grace_until' => now()->subMinute()]);
        $this->assertNull($this->perks($this->sara)['plan']);
        $sub->update(['status' => 'expired']);
        $this->assertNull($this->perks($this->sara)['plan']);

        // A plan that is no longer offered gives nothing; neither does a country where plans are off.
        $sub->update(['status' => 'active', 'period_end' => now()->addDays(10)]);
        $this->plus->update(['status' => false]);
        $this->assertNull($this->perks($this->sara)['plan']);
        $this->plus->update(['status' => true]);
        FreelanceFeatureRule::query()->where('feature', 'subscriptions')->update(['enabled' => false]);
        $this->assertNull($this->perks($this->sara)['plan']);
    }

    public function test_a_work_limit_takes_the_boost_away_and_the_badge_stays(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $this->assertSame(2, $this->perks($this->sara)['boost']);

        FreelanceSanction::query()->create([
            'subject_type' => 'freelancer', 'subject_id' => $this->sara->id, 'type' => 'work_limit', 'reason' => 'Too many complaints', 'source' => 'manual',
            'issued_by' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5),
        ]);
        $perks = $this->perks($this->sara);
        $this->assertSame([0, true, 2], [$perks['boost'], $perks['badge'], $perks['analytics']]);
        // Nor does the sort give it: the boost query sees the limit too.
        $boosts = DB::table('freelancers')->select('freelancers.id')->selectSub(app(FreelancePerks::class)->boostQuery('freelancers.id'), 'b')->pluck('b', 'id');
        $this->assertSame(0, (int) $boosts[$this->sara->id]);
    }

    // -------------------------------------------------------------- the badge and the recommended order

    public function test_services_come_recommended_first_and_every_other_order_stays_plain(): void
    {
        $this->subscribe($this->sara, $this->plus);
        // Sara's is the older one.
        $this->published($this->sara, 'Sara logo');
        $this->travel(1)->minute();
        $this->published($this->lina, 'Lina logo');

        $this->asClient();
        $names = fn (string $q = '') => array_column(array_column($this->getJson(self::CLIENT.'/services'.$q)->assertOk()->json('data'), 'freelancer'), 'name');
        $this->assertSame(['Sara', 'Lina'], $names(), 'the plan puts her ahead by default');
        $this->assertSame(['Sara', 'Lina'], $names('?sort=recommended'));
        $this->assertSame(['Lina', 'Sara'], $names('?sort=newest'), 'a chosen order ignores the plan');
        $this->assertSame(['Lina', 'Sara'], $names('?sort=price_low'), 'equal prices fall back to the newest');

        $cards = collect($this->getJson(self::CLIENT.'/services')->json('data'));
        $this->assertSame('plus', $cards->firstWhere('freelancer.name', 'Sara')['freelancer']['badge']['plan']);
        $this->assertNull($cards->firstWhere('freelancer.name', 'Lina')['freelancer']['badge']);

        // Where plans are off the order is the plain one and nobody has a badge.
        FreelanceFeatureRule::query()->where('feature', 'subscriptions')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->assertSame(['Lina', 'Sara'], $names());
        $this->assertNull($this->getJson(self::CLIENT.'/services')->json('data.0.freelancer.badge'));
    }

    public function test_offers_come_recommended_first_with_the_badge_and_a_chosen_order_stays_plain(): void
    {
        $this->subscribe($this->sara, $this->plus);
        $project = $this->project();
        $this->offer($project, $this->sara, 'submitted', 3);
        $this->offer($project, $this->lina, 'submitted', 1);

        $this->asClient();
        $url = self::CLIENT."/projects/{$project->uuid}/offers";
        $names = fn (string $q = '') => array_column(array_column($this->getJson($url.$q)->assertOk()->json('data'), 'freelancer'), 'name');
        $this->assertSame(['Sara', 'Lina'], $names());
        $this->assertSame(['Lina', 'Sara'], $names('?sort=newest'));
        $this->assertSame(['Lina', 'Sara'], $names('?sort=cheapest'), 'equal prices: the newest');

        $rows = collect($this->getJson($url)->json('data'));
        $this->assertSame('plus', $rows->firstWhere('freelancer.name', 'Sara')['freelancer']['badge']['plan']);
        $this->assertNull($rows->firstWhere('freelancer.name', 'Lina')['freelancer']['badge']);
    }

    public function test_the_invitation_list_puts_the_plans_first_and_shows_the_badge(): void
    {
        $this->subscribe($this->sara, $this->plus);
        $project = $this->project(['visibility' => 'private']);

        $this->asClient();
        $rows = $this->getJson(self::CLIENT."/projects/{$project->uuid}/invitees?all=1")->assertOk()->json('data');
        $this->assertSame(['Sara', 'Lina'], array_column($rows, 'name'), 'Lina comes first by name; the plan puts Sara ahead');
        $this->assertSame('plus', $rows[0]['badge']['plan']);
        $this->assertNull($rows[1]['badge']);
        // Searching by name still finds anyone.
        $this->assertSame(['Lina'], array_column($this->getJson(self::CLIENT."/projects/{$project->uuid}/invitees?q=lin")->json('data'), 'name'));
    }

    // -------------------------------------------------------------- more services at once

    public function test_a_plan_raises_the_services_a_freelancer_may_have_at_once(): void
    {
        $perks = fn () => app(FreelancePerks::class);
        $this->assertSame(5, $perks()->servicesLimit($this->sara, 5));

        $this->subscribe($this->sara, $this->plus);
        FreelanceFeatures::forget();
        $this->assertSame(15, $perks()->servicesLimit($this->sara, 5));
        // A country that already allows more keeps its number.
        $this->assertSame(20, $perks()->servicesLimit($this->sara, 20));

        $this->as($this->sara);
        $this->getJson(self::API.'/services')->assertOk()->assertJsonPath('data.limits.max_active', 15);

        // The plans list says it, against the country's own number.
        $plans = collect($this->getJson(self::API.'/plans')->assertOk()->json('data.plans'));
        $this->assertSame([true, 1, 1, 15], array_values(array_intersect_key($plans->firstWhere('slug', 'plus')['benefits'], array_flip(['badge', 'boost', 'analytics', 'services_max']))));
        $this->assertSame([false, 0, 0, 5], array_values(array_intersect_key($plans->firstWhere('slug', 'free')['benefits'], array_flip(['badge', 'boost', 'analytics', 'services_max']))));
    }

    public function test_the_service_limit_follows_the_plan_when_sending_services_for_review(): void
    {
        FreelancePolicy::query()->create(['country_id' => $this->saudi->id, 'specialty_id' => null, 'data' => ['services.max_active' => 1], 'status' => true]);
        FreelancePolicies::forget();

        $this->as($this->sara);
        $first = $this->postJson(self::API.'/services', $this->body('First'))->assertCreated()->json('data.uuid');
        $second = $this->postJson(self::API.'/services', $this->body('Second'))->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/services/{$first}/submit")->assertOk();
        $this->postJson(self::API."/services/{$second}/submit")->assertStatus(422)->assertJsonPath('error_code', 'freelance_service_limit');

        $this->subscribe($this->sara, $this->plus);
        $this->postJson(self::API."/services/{$second}/submit")->assertOk();
    }

    // -------------------------------------------------------------- my performance

    public function test_free_is_locked_and_told_which_plan_opens_it(): void
    {
        $this->as($this->sara);
        $res = $this->getJson(self::API.'/insights')->assertOk();
        $this->assertTrue($res->json('data.locked'));
        $this->assertSame([0, [], null], [$res->json('data.level'), $res->json('data.allowed_days'), $res->json('data.data')]);
        $this->assertSame('plus', $res->json('data.upgrade.slug'));
        $this->assertTrue($res->json('data.subscriptions_enabled'));
        $this->assertSame(['SAR', 2], [$res->json('data.currency'), $res->json('data.decimals')]);

        // Where plans are not sold there is nothing to offer, and the screen knows.
        FreelanceFeatureRule::query()->where('feature', 'subscriptions')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $off = $this->getJson(self::API.'/insights')->assertOk();
        $this->assertFalse($off->json('data.subscriptions_enabled'));
        $this->assertNull($off->json('data.upgrade'));
    }

    /** Sara's work: offers in several states, and earnings. @return array<int, FreelanceProject> */
    private function saraWork(): void
    {
        foreach ([['accepted', 2, true], ['rejected', 5, true], ['submitted', 8, false], ['withdrawn', 9, false], ['submitted', 60, false]] as [$status, $ago, $viewed]) {
            $this->offer($this->project(), $this->sara, $status, $ago, $viewed);
        }
        // The test runs inside a transaction that is never committed, so deferring the checks is enough to insert earnings without their project / milestone.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        foreach ([['a', 2, 5000, 'released'], ['b', 10, 3000, 'released'], ['c', 200, 7000, 'released'], ['d', 1, 9900, 'pending']] as [$key, $ago, $amount, $status]) {
            DB::table('freelance_earnings')->insert([
                'freelancer_id' => $this->sara->id, 'project_id' => 1, 'milestone_id' => 1, 'country_id' => $this->saudi->id, 'kind' => 'approved', 'amount_minor' => $amount,
                'currency_code' => 'SAR', 'status' => $status, 'released_at' => $status === 'released' ? now()->subDays($ago) : null, 'key' => "perks-{$key}", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_a_basic_plan_shows_a_summary_of_thirty_days_and_no_more(): void
    {
        $this->subscribe($this->sara, $this->plus);
        $this->saraWork();

        $this->as($this->sara);
        // 90 is asked, the plan allows 30.
        $res = $this->getJson(self::API.'/insights?days=90')->assertOk();
        $this->assertSame([1, false, [30], 30, 'pro'], [$res->json('data.level'), $res->json('data.locked'), $res->json('data.allowed_days'), $res->json('data.days'), $res->json('data.upgrade.slug')]);

        $data = $res->json('data.data');
        $this->assertSame(['sent' => 4, 'withdrawn' => 1, 'viewed' => 2, 'shortlisted' => 1, 'accepted' => 1, 'rejected' => 1, 'view_rate' => 67, 'accept_rate' => 33], $data['offers'], 'the old offer is outside the 30 days');
        $this->assertSame(8000, $data['earnings']['earned_minor'], 'only what was released in the last 30 days');
        $this->assertArrayNotHasKey('series', $data['earnings']);
        $this->assertArrayNotHasKey('specialties', $data);
        $this->assertSame(0, $data['services']['received']);
        $this->assertNull($data['services']['accept_rate']);
        $this->assertArrayHasKey('rating', $data['reputation']);
        $this->assertSame(0, $data['reputation']['completed_in_period']);
    }

    public function test_a_full_plan_looks_back_further_and_shows_earnings_over_time_and_specialties(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $this->saraWork();

        $this->as($this->sara);
        $month = $this->getJson(self::API.'/insights')->assertOk();
        $this->assertSame([2, [30, 90, 365], 30, null], [$month->json('data.level'), $month->json('data.allowed_days'), $month->json('data.days'), $month->json('data.upgrade')]);
        $earnings = $month->json('data.data.earnings');
        $this->assertSame('week', $earnings['bucket']);
        $this->assertCount(5, $earnings['series'], 'weeks counted back from today');
        $this->assertSame([5000, 3000, 8000], [$earnings['series'][4]['earned_minor'], $earnings['series'][3]['earned_minor'], $earnings['earned_minor']]);
        $this->assertSame(8000, array_sum(array_column($earnings['series'], 'earned_minor')));

        $specialties = $month->json('data.data.specialties');
        $this->assertSame([['id' => $this->web->id, 'name' => 'Web', 'sent' => 4, 'accepted' => 1, 'accept_rate' => 25]], $specialties);

        // 90 days: three months; a year: twelve, with the old payment in.
        $quarter = $this->getJson(self::API.'/insights?days=90')->assertOk();
        $this->assertSame([90, 'month', 3], [$quarter->json('data.days'), $quarter->json('data.data.earnings.bucket'), count($quarter->json('data.data.earnings.series'))]);
        $this->assertSame(5, $quarter->json('data.data.offers.sent'));
        $year = $this->getJson(self::API.'/insights?days=365')->assertOk();
        $this->assertSame([365, 15000, 12], [$year->json('data.days'), $year->json('data.data.earnings.earned_minor'), count($year->json('data.data.earnings.series'))]);
        $this->assertSame(15000, array_sum(array_column($year->json('data.data.earnings.series'), 'earned_minor')));

        // A number the plan has no window for takes the nearest below it; below the smallest, the smallest.
        $this->assertSame(365, $this->getJson(self::API.'/insights?days=1000')->json('data.days'));
        $this->assertSame(30, $this->getJson(self::API.'/insights?days=7')->json('data.days'));
        $this->getJson(self::API.'/insights?days=abc')->assertStatus(422);
    }

    public function test_the_numbers_are_the_freelancers_own(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $this->subscribe($this->lina, $this->plus);
        $this->offer($this->project(), $this->lina, 'accepted', 1, true);

        $this->as($this->sara);
        $this->assertSame(0, $this->getJson(self::API.'/insights')->json('data.data.offers.sent'));
        $this->as($this->lina);
        $this->assertSame(1, $this->getJson(self::API.'/insights')->json('data.data.offers.sent'));
    }
}
