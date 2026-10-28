<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Driver\Models\Driver;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FL-003: the freelancer's own account on `freelance_api` — phone + OTP, one session per device,
 * profile + terms, devices, deleting the account, and the admin's block.
 */
class FreelancerAuthTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9, 'is_default' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س'])->id,
            'status' => true,
        ]);
        $this->openService($this->saudi);
    }

    private function openService(Country $country, bool $on = true): void
    {
        FreelanceFeatureRule::query()->updateOrCreate(['feature' => 'freelance', 'country_id' => $country->id], ['enabled' => $on]);
        FreelanceFeatures::forget();
    }

    private function signIn(string $phone = '501234567', array $device = []): string
    {
        Cache::flush(); // the OTP resend cooldown; each sign-in here stands for a real one later on
        $this->postJson('/api/freelance/v1/auth/otp', ['dial_code' => '+966', 'phone' => $phone])->assertOk();
        $token = (string) $this->postJson('/api/freelance/v1/auth/verify', ['dial_code' => '+966', 'phone' => $phone, 'code' => '1234'] + $device)
            ->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_a_new_phone_becomes_an_incomplete_freelancer_and_signs_in(): void
    {
        $this->postJson('/api/freelance/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])
            ->assertOk()->assertJsonPath('data.is_new_freelancer', true);
        $this->postJson('/api/freelance/v1/auth/verify', ['dial_code' => '+966', 'phone' => '501234567', 'code' => '9999'])->assertUnprocessable();

        $token = $this->postJson('/api/freelance/v1/auth/verify', ['dial_code' => '+966', 'phone' => '501234567', 'code' => '1234'])
            ->assertOk()->assertJsonPath('data.freelancer.status', 'registered')->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/freelance/v1/auth/me')->assertOk()
            ->assertJsonPath('data.phone', '+966501234567')
            ->assertJsonPath('data.missing', ['name', 'terms']);
    }

    public function test_the_service_must_be_open_in_the_country(): void
    {
        $this->openService($this->saudi, false);

        $this->postJson('/api/freelance/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])
            ->assertForbidden()->assertJsonPath('error_code', 'freelance_not_available');
        $this->assertSame(0, Freelancer::query()->count());
    }

    public function test_one_session_per_device_and_devices_can_be_ended(): void
    {
        $phoneA = $this->signIn(device: ['device_id' => 'phone-1', 'platform' => 'android', 'device_name' => 'Pixel']);
        $phoneAgain = $this->signIn(device: ['device_id' => 'phone-1', 'platform' => 'android', 'device_name' => 'Pixel']);
        $web = $this->signIn(device: ['platform' => 'web']);

        $this->withToken($phoneA)->getJson('/api/freelance/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $devices = $this->withToken($web)->getJson('/api/freelance/v1/devices')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->app['auth']->forgetGuards();
        $phoneRow = collect($devices)->firstWhere('platform', 'android');
        $this->assertFalse($phoneRow['is_current']);

        $this->withToken($web)->deleteJson('/api/freelance/v1/devices/'.$phoneRow['id'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($phoneAgain)->getJson('/api/freelance/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($web)->getJson('/api/freelance/v1/auth/me')->assertOk();
    }

    public function test_profile_and_terms_move_the_account_to_pending_verification(): void
    {
        $token = $this->signIn();

        $this->withToken($token)->putJson('/api/freelance/v1/profile', ['name' => 'Sara', 'email' => 'sara@example.com', 'gender' => 'female'])
            ->assertOk()->assertJsonPath('data.status', 'registered')->assertJsonPath('data.missing', ['terms']);
        $this->withToken($token)->postJson('/api/freelance/v1/terms/accept', ['version' => '0'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'freelance_terms_outdated');
        $this->withToken($token)->postJson('/api/freelance/v1/terms/accept', ['version' => (string) config('freelance.terms_version')])
            ->assertOk()->assertJsonPath('data.status', 'profile_ready')->assertJsonPath('data.terms.accepted', true);
    }

    public function test_a_blocked_freelancer_is_signed_out_and_turned_away(): void
    {
        $token = $this->signIn();
        $freelancer = Freelancer::query()->firstOrFail();

        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        Permission::findOrCreate('freelancers.update', 'admin_api');
        Permission::findOrCreate('freelancers.view', 'admin_api');
        $admin->givePermissionTo(['freelancers.update', 'freelancers.view']);
        Sanctum::actingAs($admin, [], 'admin_api');
        $this->postJson("/api/admin/v1/freelancers/{$freelancer->id}/status", ['status' => 'blocked'])->assertUnprocessable();
        $this->postJson("/api/admin/v1/freelancers/{$freelancer->id}/status", ['status' => 'blocked', 'reason' => 'fraud'])
            ->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->getJson('/api/admin/v1/freelancers?status=blocked')->assertOk()->assertJsonCount(1, 'data');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/freelance/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/freelance/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])->assertUnprocessable();
        $this->assertSame(FreelancerStatus::Blocked, $freelancer->fresh()->status);
    }

    public function test_deleting_the_account_wipes_it_and_frees_the_phone(): void
    {
        $token = $this->signIn();
        $this->withToken($token)->putJson('/api/freelance/v1/profile', ['name' => 'Sara', 'email' => 'sara@example.com'])->assertOk();

        $this->withToken($token)->deleteJson('/api/freelance/v1/account')->assertUnprocessable();
        $this->withToken($token)->deleteJson('/api/freelance/v1/account', ['confirm' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        $old = Freelancer::withTrashed()->firstOrFail();
        $this->assertTrue($old->trashed());
        $this->assertNull($old->name);
        $this->assertNull($old->email);
        $this->assertSame('deleted-'.$old->id, $old->phone);
        $this->withToken($token)->getJson('/api/freelance/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/freelance/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])
            ->assertOk()->assertJsonPath('data.is_new_freelancer', true);
    }

    public function test_driver_and_freelancer_tokens_do_not_cross(): void
    {
        $freelancerToken = $this->signIn();
        $driver = Driver::query()->create(['phone' => '+966509999999', 'country_id' => $this->saudi->id, 'phone_verified_at' => now()]);
        $driverToken = $driver->createToken('driver-app')->plainTextToken;

        $this->withToken($driverToken)->getJson('/api/freelance/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($freelancerToken)->getJson('/api/driver/v1/auth/me')->assertUnauthorized();
    }

    public function test_admin_freelancer_screens_need_their_permission(): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->getJson('/api/admin/v1/freelancers')->assertForbidden();
    }
}
