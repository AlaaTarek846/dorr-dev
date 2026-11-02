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
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L11: how often a freelancer's profile and ready-made services are looked at, and what to do next to be looked at more. Visits are
 * a number per day, kind and place — never who looked — and the same client opening the same thing within minutes counts once. Seeing
 * the numbers follows the plan (`analytics`); «improve my profile» is the same for everyone.
 */
class FreelanceProfileViewsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $mona;

    private User $khaled;

    private Freelancer $sara;

    private FreelancePlan $plus;

    private FreelancePlan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'services', 'subscriptions'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->khaled = User::query()->create(['name' => 'Khaled', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);

        FreelancePlan::query()->create(['slug' => 'free', 'rank' => 0, 'name' => ['ar' => 'مجانية', 'en' => 'Free']]);
        $this->plus = FreelancePlan::query()->create(['slug' => 'plus', 'rank' => 1, 'name' => ['ar' => 'بلس', 'en' => 'Plus'], 'analytics' => 1]);
        $this->pro = FreelancePlan::query()->create(['slug' => 'pro', 'rank' => 2, 'name' => ['ar' => 'برو', 'en' => 'Pro'], 'analytics' => 2]);
    }

    // -------------------------------------------------------------- helpers

    private function asFreelancer(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function asClient(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u->refresh(), [], 'user_api');
    }

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

    private function subscribe(Freelancer $f, FreelancePlan $plan): void
    {
        FreelanceSubscription::query()->create([
            'freelancer_id' => $f->id, 'plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR', 'period' => 'monthly', 'price_minor' => 3400,
            'status' => 'active', 'auto_renew' => true, 'started_at' => now()->subDays(3), 'period_start' => now()->subDays(3), 'period_end' => now()->addDays(27),
        ]);
    }

    /** A service of this freelancer, sent and approved by the staff: published. */
    private function published(Freelancer $f, string $title = 'Logo design'): FreelanceService
    {
        $this->asFreelancer($f);
        $uuid = $this->postJson(self::API.'/services', [
            'title' => $title,
            'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'specialty_id' => $this->web->id,
            'tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea', 'PNG']]],
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk();
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk();

        return FreelanceService::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function views(Freelancer $f): \Illuminate\Support\Collection
    {
        return DB::table('freelance_profile_views')->where('freelancer_id', $f->id)->get();
    }

    private function insights(Freelancer $f, string $query = ''): array
    {
        $this->asFreelancer($f);

        return $this->getJson(self::API.'/insights'.$query)->assertOk()->json('data');
    }

    // -------------------------------------------------------------- counting

    public function test_a_service_opened_by_a_client_is_counted_once_in_a_while(): void
    {
        $service = $this->published($this->sara);

        $this->asClient($this->mona);
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();
        $this->assertSame(1, (int) $this->views($this->sara)->sum('views'), 'refreshing the page is not another visit');

        $this->asClient($this->khaled);
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();
        $row = $this->views($this->sara)->first();
        $this->assertSame(2, (int) $row->views);
        $this->assertSame(['service', 'market', $service->id], [$row->kind, $row->source, (int) $row->service_id]);
        $this->assertCount(1, $this->views($this->sara), 'one counter for the day, not a row per visit');
    }

    public function test_every_open_counts_when_the_window_is_off(): void
    {
        config(['freelance.profile_views.dedupe_minutes' => 0]);
        $service = $this->published($this->sara);

        $this->asClient($this->mona);
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();

        $this->assertSame(2, (int) $this->views($this->sara)->sum('views'));
    }

    public function test_the_profile_seen_from_an_offer_and_the_portfolio_are_counted(): void
    {
        $project = FreelanceProject::query()->create([
            'client_id' => $this->mona->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $offer = FreelanceOffer::query()->create([
            'project_id' => $project->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21,
            'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.', 'status' => 'submitted',
        ]);

        $this->asClient($this->mona);
        $this->getJson(self::CLIENT."/projects/{$project->uuid}/offers/{$offer->uuid}/freelancer")->assertOk();
        $this->getJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio")->assertOk();

        $this->assertEqualsCanonicalizing(['offer', 'portfolio'], $this->views($this->sara)->pluck('source')->all());
        $this->assertSame(['profile'], $this->views($this->sara)->pluck('kind')->unique()->values()->all());
    }

    public function test_nobody_is_named_in_what_is_kept(): void
    {
        $service = $this->published($this->sara);
        $this->asClient($this->mona);
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();

        $columns = DB::getSchemaBuilder()->getColumnListing('freelance_profile_views');
        $this->assertSame([], array_values(array_intersect($columns, ['user_id', 'client_id', 'viewer_id', 'ip', 'ip_address'])));
    }

    // -------------------------------------------------------------- «My performance»

    public function test_free_does_not_see_the_numbers(): void
    {
        $this->assertTrue($this->insights($this->sara)['locked']);
    }

    public function test_the_basic_level_gets_totals_and_the_full_level_gets_more(): void
    {
        $service = $this->published($this->sara, 'Logo design');
        $this->asClient($this->mona);
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();
        $this->asClient($this->khaled);
        $this->getJson(self::CLIENT."/services/{$service->uuid}")->assertOk();
        // The same days one period earlier, and one a long time ago (outside both).
        DB::table('freelance_profile_views')->insert([
            ['freelancer_id' => $this->sara->id, 'service_id' => 0, 'kind' => 'profile', 'source' => 'offer', 'day' => now()->subDays(40)->toDateString(), 'views' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['freelancer_id' => $this->sara->id, 'service_id' => 0, 'kind' => 'profile', 'source' => 'offer', 'day' => now()->subDays(400)->toDateString(), 'views' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->subscribe($this->sara, $this->plus);
        $basic = $this->insights($this->sara)['data']['views'];
        $this->assertSame([0, 2, 5, 0], [$basic['profile'], $basic['service'], $basic['profile_before'], $basic['service_before']]);
        $this->assertSame(['offer' => 0, 'portfolio' => 0, 'market' => 2], $basic['by_source']);
        $this->assertArrayNotHasKey('series', $basic);
        $this->assertArrayNotHasKey('top_services', $basic);

        FreelanceSubscription::query()->delete();
        $this->subscribe($this->sara, $this->pro);
        $full = $this->insights($this->sara, '?days=90')['data']['views'];
        $this->assertSame('month', $full['bucket']);
        $this->assertCount(3, $full['series']);
        $this->assertSame(2, collect($full['series'])->sum('service'));
        $this->assertSame([['id' => $service->id, 'title' => 'Logo design', 'views' => 2, 'orders' => 0]], $full['top_services']);
    }

    // -------------------------------------------------------------- «improve my profile»

    public function test_a_new_profile_is_told_what_is_worth_most_first(): void
    {
        $this->asFreelancer($this->sara);
        $res = $this->getJson(self::API.'/profile-coach')->assertOk();

        $this->assertSame(10, $res->json('data.percent'), 'only the name is there');
        $this->assertSame('start', $res->json('data.level'));
        $tips = collect($res->json('data.tips'));
        $this->assertSame(['skills', 'specialties'], $tips->take(2)->pluck('key')->sort()->values()->all(), 'the two worth 15 lead');
        $this->assertSame(['high'], $tips->take(2)->pluck('impact')->unique()->values()->all());
        $this->assertSame(['specialties', 'skills', 'profile'], [$tips->firstWhere('key', 'specialties')['action'], $tips->firstWhere('key', 'skills')['action'], $tips->firstWhere('key', 'avatar')['action']]);
        $this->assertSame(10, $tips->firstWhere('key', 'avatar')['points']);
        $this->assertNull($tips->firstWhere('key', 'verification'), 'verified already');
        $this->assertSame('medium', $tips->firstWhere('key', 'first_service')['impact']);
        // High before medium before low, and never out of order.
        $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
        $this->assertSame($tips->pluck('impact')->map(fn ($i) => $rank[$i])->sortDesc()->values()->all(), $tips->pluck('impact')->map(fn ($i) => $rank[$i])->all());
    }

    public function test_an_unverified_freelancer_is_told_to_verify_and_the_first_service_waits(): void
    {
        $this->sara->forceFill(['status' => 'profile_ready'])->save();
        $this->asFreelancer($this->sara);
        $tips = collect($this->getJson(self::API.'/profile-coach')->assertOk()->json('data.tips'));

        $this->assertSame(['high', 'verification'], [$tips->firstWhere('key', 'verification')['impact'], $tips->firstWhere('key', 'verification')['action']]);
        $this->assertNull($tips->firstWhere('key', 'first_service'));
    }

    public function test_the_extra_tips_come_and_go_with_the_profile(): void
    {
        foreach (['a', 'b', 'c'] as $slug) {
            $this->sara->skills()->attach(FreelanceSkill::query()->create(['slug' => $slug, 'status' => FreelanceSkill::ACTIVE])->id);
        }
        $this->asFreelancer($this->sara);
        $keys = collect($this->getJson(self::API.'/profile-coach')->assertOk()->json('data.tips'))->pluck('key');
        $this->assertTrue($keys->contains('more_skills'), 'enough for the bar, not for a client choosing');
        $this->assertFalse($keys->contains('skills'));

        foreach (['d', 'e', 'f'] as $slug) {
            $this->sara->skills()->attach(FreelanceSkill::query()->create(['slug' => $slug, 'status' => FreelanceSkill::ACTIVE])->id);
        }
        $this->assertFalse(collect($this->getJson(self::API.'/profile-coach')->json('data.tips'))->pluck('key')->contains('more_skills'));

        $this->published($this->sara);
        $this->asFreelancer($this->sara);
        $this->assertFalse(collect($this->getJson(self::API.'/profile-coach')->json('data.tips'))->pluck('key')->contains('first_service'));
    }

    public function test_it_is_the_same_on_every_plan(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $this->asFreelancer($this->sara);
        $this->getJson(self::API.'/profile-coach')->assertOk()->assertJsonPath('data.percent', 10);
    }
}
