<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Tests\TestCase;

/** The freelancer app's Settings > Security: `freelance/v1/security/*` - the devices signed in and the security log. */
class FreelancerSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9, 'is_default' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id,
            'status' => true,
        ]);
        FreelanceFeatureRule::query()->updateOrCreate(['feature' => 'freelance', 'country_id' => $saudi->id], ['enabled' => true]);
        FreelanceFeatures::forget();
    }

    private function signIn(string $deviceId, string $name): string
    {
        Cache::flush();
        $headers = ['X-Device-Id' => $deviceId, 'X-Device-Name' => $name, 'X-Platform' => 'android'];
        $this->postJson('/api/freelance/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'], $headers)->assertOk();
        $token = (string) $this->postJson('/api/freelance/v1/auth/verify', ['dial_code' => '+966', 'phone' => '501234567', 'code' => '1234', 'device_id' => $deviceId, 'device_name' => $name, 'platform' => 'android'], $headers)
            ->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_the_devices_are_listed_and_another_one_can_be_signed_out(): void
    {
        $phone = $this->signIn('devicephone1', 'Pixel 8');
        $tablet = $this->signIn('devicetablet', 'Galaxy Tab');

        $devices = $this->as($phone)->getJson('/api/freelance/v1/security/devices')->assertOk()->json('data');
        $this->assertCount(2, $devices);
        $this->assertSame('Pixel 8', $devices[0]['device_name']);
        $this->assertTrue($devices[0]['current']);

        $other = collect($devices)->firstWhere('current', false);
        $this->as($phone)->deleteJson('/api/freelance/v1/security/devices/'.$other['id'])->assertOk()->assertJsonPath('data.signed_out_current', false);

        $this->as($tablet)->getJson('/api/freelance/v1/auth/me')->assertUnauthorized();
        $this->as($phone)->getJson('/api/freelance/v1/security/devices')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, Freelancer::query()->first()->devices()->count());
    }

    public function test_all_the_other_devices_can_be_signed_out_at_once(): void
    {
        $phone = $this->signIn('devicephone1', 'Pixel 8');
        $this->signIn('devicetablet', 'Galaxy Tab');
        $this->signIn('devicethree', 'Old phone');

        $this->as($phone)->postJson('/api/freelance/v1/security/devices/logout-others')->assertOk()->assertJsonPath('data.count', 2);
        $this->as($phone)->getJson('/api/freelance/v1/security/devices')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_security_log_has_the_sign_ins_and_the_warnings_filter(): void
    {
        $phone = $this->signIn('devicephone1', 'Pixel 8');
        $this->signIn('devicetablet', 'Galaxy Tab');

        $all = $this->as($phone)->getJson('/api/freelance/v1/security/events')->assertOk()->json('data');
        $this->assertContains('login_new_device', array_column($all, 'type'));

        $warnings = $this->as($phone)->getJson('/api/freelance/v1/security/events?warnings=1')->assertOk()->json('data');
        $this->assertNotEmpty($warnings);
        $this->assertSame([true], array_values(array_unique(array_column($warnings, 'warning'))));
    }

    public function test_security_needs_a_signed_in_freelancer(): void
    {
        $this->getJson('/api/freelance/v1/security/devices')->assertUnauthorized();
        $this->getJson('/api/freelance/v1/security/events')->assertUnauthorized();
    }
}
