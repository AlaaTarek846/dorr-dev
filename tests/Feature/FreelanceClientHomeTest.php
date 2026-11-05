<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelancePromo;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The client's freelance hub in the Dorr user app in one answer (GET mobile/v1/freelance/home, the client redesign batch B), and the promo
 * banners' audience: a banner shows on the freelancer app, the client's hub or both, and opens only a page that app has.
 */
class FreelanceClientHomeTest extends TestCase
{
    use RefreshDatabase;

    private const HOME = '/api/mobile/v1/freelance/home';

    private const ADMIN = '/api/admin/v1/freelance-promos';

    private Country $saudi;

    private User $mona;

    private FreelanceSpecialty $web;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function asMona(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->mona->refresh(), [], 'user_api');
    }

    private function asAdmin(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['freelance-promos.view', 'freelance-promos.create', 'freelance-promos.update'] as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /** @param  array<string, mixed>  $attrs */
    private function promo(string $title, array $attrs = []): FreelancePromo
    {
        return FreelancePromo::query()->create($attrs + ['title' => ['ar' => $title, 'en' => $title], 'target_type' => 'none']);
    }

    private function publishedProject(string $title): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => $this->mona->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    public function test_a_new_client_sees_how_it_works_and_no_numbers(): void
    {
        $this->asMona();
        $this->getJson(self::HOME)->assertOk()
            ->assertJsonPath('data.is_new', true)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.stats.running_projects', 0)
            ->assertJsonPath('data.stats.new_offers', 0)
            ->assertJsonPath('data.attention.new_offers.count', 0)
            ->assertJsonPath('data.attention.new_offers.project_uuid', null)
            ->assertJsonPath('data.projects', [])
            // Services are off in this country: no row of them.
            ->assertJsonPath('data.services', [])
            ->assertJsonPath('data.next_consultation', null);
    }

    public function test_an_offer_not_seen_yet_waits_on_the_hub_with_its_project(): void
    {
        $project = $this->publishedProject('A booking website');
        $sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($sara, [], 'freelance_api');
        $this->postJson("/api/freelance/v1/projects/{$project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();

        $this->asMona();
        $this->getJson(self::HOME)->assertOk()
            ->assertJsonPath('data.is_new', false)
            ->assertJsonPath('data.stats.running_projects', 1)
            ->assertJsonPath('data.stats.new_offers', 1)
            ->assertJsonPath('data.attention.new_offers.count', 1)
            ->assertJsonPath('data.attention.new_offers.project_uuid', $project->uuid)
            ->assertJsonPath('data.projects.0.uuid', $project->uuid)
            ->assertJsonPath('data.projects.0.next_action', 'review_offers');
    }

    public function test_a_project_sent_back_waits_to_be_fixed(): void
    {
        $project = $this->publishedProject('Logo');
        $project->update(['status' => FreelanceProjectStatus::ChangesRequested]);

        $this->asMona();
        $this->getJson(self::HOME)->assertOk()
            ->assertJsonPath('data.attention.to_fix.count', 1)
            ->assertJsonPath('data.attention.to_fix.project_uuid', $project->uuid)
            // Not running: it is not on the hub's list of running projects.
            ->assertJsonPath('data.stats.running_projects', 0);
    }

    public function test_each_app_shows_its_own_banners_and_the_ones_for_both(): void
    {
        $this->promo('Freelancers', ['sort_order' => 1]);
        $this->promo('Clients', ['audience' => 'client', 'sort_order' => 2]);
        $this->promo('Everyone', ['audience' => 'both', 'sort_order' => 3]);
        $this->promo('Post now', ['audience' => 'client', 'target_type' => 'screen', 'target' => 'new_project', 'sort_order' => 4]);
        // Services are off in this country: a banner opening them stays away.
        $this->promo('Services', ['audience' => 'client', 'target_type' => 'screen', 'target' => 'services', 'sort_order' => 5]);

        $this->asMona();
        $this->assertSame(['Clients', 'Everyone', 'Post now'], collect($this->getJson(self::HOME)->assertOk()->json('data.promos'))->pluck('title')->all());

        $sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($sara, [], 'freelance_api');
        $this->assertSame(['Freelancers', 'Everyone'], collect($this->getJson('/api/freelance/v1/home')->assertOk()->json('data.promos'))->pluck('title')->all());
    }

    public function test_staff_pick_the_audience_and_a_page_that_app_has(): void
    {
        $this->asAdmin();
        $base = ['title' => ['ar' => 'عنوان', 'en' => 'Title']];
        // The community is the freelancer app's only; plans are not on the client's hub, so not for both either.
        $this->postJson(self::ADMIN, $base + ['audience' => 'client', 'target_type' => 'screen', 'target' => 'community'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson(self::ADMIN, $base + ['audience' => 'both', 'target_type' => 'screen', 'target' => 'plans'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson(self::ADMIN, $base + ['audience' => 'nobody', 'target_type' => 'none'])->assertUnprocessable()->assertJsonValidationErrors('audience');
        $this->postJson(self::ADMIN, $base + ['audience' => 'client', 'target_type' => 'screen', 'target' => 'new_project'])->assertCreated()->assertJsonPath('data.audience', 'client');
        $uuid = $this->postJson(self::ADMIN, $base + ['target_type' => 'screen', 'target' => 'community'])->assertCreated()->assertJsonPath('data.audience', 'freelancer')->json('data.uuid');

        // Moving a community banner to the client's hub is refused: its page is not there.
        $this->postJson(self::ADMIN.'/'.$uuid, ['audience' => 'client'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->getJson(self::ADMIN.'?audience=client')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('screens_by_audience.client.1', 'new_project');
    }
}
