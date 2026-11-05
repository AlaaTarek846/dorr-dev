<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceEarning;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelancePromo;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The freelancer app's home in one answer (GET home) and the staff's promo banners on it: shown while on, between their dates, in their
 * country (or everywhere), and only when the page they open is on in that country.
 */
class FreelancerHomeTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/admin/v1/freelance-promos';

    private Country $saudi;

    private Country $egypt;

    private Freelancer $sara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('public');
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00', 'UTC'));
        $this->saudi = $this->country('SA', '+966', 'SAR');
        $this->egypt = $this->country('EG', '+20', 'EGP');
        $this->enable($this->saudi, ['freelance', 'projects', 'services']);
        $this->enable($this->egypt, ['freelance', 'projects']);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000071', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function country(string $code, string $dial, string $currency): Country
    {
        return Country::create(['code' => $code, 'dial_code' => $dial, 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => strtolower($code)])->id, 'currency_id' => Currency::create(['code' => $currency, 'symbol' => $currency])->id]);
    }

    /** @param  list<string>  $features */
    private function enable(Country $c, array $features): void
    {
        foreach ($features as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $c->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
    }

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    /** @param  list<string>  $permissions */
    private function asAdmin(array $permissions = ['freelance-promos.view', 'freelance-promos.create', 'freelance-promos.update', 'freelance-promos.delete']): void
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

    /** @param  array<string, mixed>  $attrs */
    private function promo(string $title, array $attrs = []): FreelancePromo
    {
        return FreelancePromo::query()->create($attrs + ['title' => ['ar' => $title, 'en' => $title], 'target_type' => 'none']);
    }

    public function test_home_brings_the_numbers_and_what_waits(): void
    {
        $this->earning(12500, FreelanceEarning::RELEASED, now()->subDay());
        $this->earning(9999, FreelanceEarning::RELEASED, now()->subMonths(2));
        $this->earning(3000, FreelanceEarning::PENDING, null);

        $this->asSara();
        $this->getJson('/api/freelance/v1/home')->assertOk()
            ->assertJsonPath('data.stats.currency', 'SAR')
            ->assertJsonPath('data.stats.earned_month_minor', 12500)
            ->assertJsonPath('data.stats.pending_minor', 3000)
            ->assertJsonPath('data.stats.live_offers', 0)
            ->assertJsonPath('data.stats.active_projects', 0)
            ->assertJsonPath('data.attention.orders_awaiting', 0)
            ->assertJsonPath('data.features.services', true)
            ->assertJsonPath('data.next_consultation', null)
            ->assertJsonPath('data.work', []);
    }

    public function test_promos_follow_their_switch_dates_country_and_page(): void
    {
        $this->promo('Everywhere', ['sort_order' => 2]);
        $this->promo('Saudi first', ['country_id' => $this->saudi->id, 'sort_order' => 1]);
        $this->promo('Egypt only', ['country_id' => $this->egypt->id]);
        $this->promo('Off', ['is_active' => false]);
        $this->promo('Later', ['starts_at' => now()->addDay()]);
        $this->promo('Over', ['ends_at' => now()->subMinute()]);
        $this->promo('Services page', ['target_type' => 'screen', 'target' => 'services', 'sort_order' => 3]);
        // The community is off in Saudi Arabia: its banner stays away.
        FreelanceFeatureRule::query()->create(['feature' => 'community', 'country_id' => $this->saudi->id, 'enabled' => false]);
        FreelanceFeatures::forget();
        $this->promo('Community page', ['target_type' => 'screen', 'target' => 'community']);

        $this->asSara();
        $titles = collect($this->getJson('/api/freelance/v1/home')->assertOk()->json('data.promos'))->pluck('title')->all();
        $this->assertSame(['Saudi first', 'Everywhere', 'Services page'], $titles);
    }

    public function test_staff_add_change_switch_and_delete_a_promo(): void
    {
        $this->asAdmin();
        $uuid = $this->post(self::ADMIN, [
            'title' => ['ar' => 'باقة برو', 'en' => 'Go Pro'], 'body' => ['ar' => 'عمولة أقل', 'en' => 'Lower fees'], 'cta' => ['ar' => 'اشترك', 'en' => 'Subscribe'],
            'target_type' => 'screen', 'target' => 'plans', 'country_id' => $this->saudi->id, 'sort_order' => 1,
            'image' => UploadedFile::fake()->image('banner.png', 1200, 600),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.state', 'showing')->json('data.uuid');
        $this->assertNotNull(FreelancePromo::query()->where('uuid', $uuid)->first()->imagePath());

        $this->post(self::ADMIN.'/'.$uuid, ['target_type' => 'url', 'target' => 'https://dorr.app/pro'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.target', 'https://dorr.app/pro');
        $this->postJson(self::ADMIN.'/'.$uuid.'/toggle')->assertOk()->assertJsonPath('data.state', 'off');
        $this->getJson(self::ADMIN.'?state=off')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson(self::ADMIN.'/'.$uuid)->assertOk();
        $this->assertDatabaseCount('freelance_promos', 0);
    }

    public function test_a_promo_opens_only_a_known_page_or_an_https_link(): void
    {
        $this->asAdmin();
        $base = ['title' => ['ar' => 'عنوان', 'en' => 'Title']];
        $this->postJson(self::ADMIN, $base + ['target_type' => 'screen', 'target' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson(self::ADMIN, $base + ['target_type' => 'url', 'target' => 'http://plain.example'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson(self::ADMIN, $base + ['target_type' => 'none', 'target' => 'anything'])->assertCreated()->assertJsonPath('data.target', null);
    }

    public function test_promos_need_their_permission(): void
    {
        $this->asAdmin(['freelance-promos.view']);
        $this->getJson(self::ADMIN)->assertOk();
        $this->postJson(self::ADMIN, ['title' => ['ar' => 'x', 'en' => 'x'], 'target_type' => 'none'])->assertForbidden();
    }

    /** An earning row on its own (its project and part do not matter to the home, so the keys are left loose). */
    private function earning(int $minor, string $status, ?Carbon $releasedAt): void
    {
        // The test's transaction is rolled back, so the loose keys are never checked.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('freelance_earnings')->insert([
            'freelancer_id' => $this->sara->id, 'project_id' => 1, 'milestone_id' => 1, 'country_id' => $this->saudi->id, 'kind' => 'approved',
            'amount_minor' => $minor, 'currency_code' => 'SAR', 'status' => $status, 'released_at' => $releasedAt, 'key' => uniqid('e'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
