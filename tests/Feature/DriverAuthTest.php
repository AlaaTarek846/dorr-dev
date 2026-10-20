<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * The driver app's own sign-in (docs/rides-plan.md §4.1): phone + OTP on the `driver_api` guard —
 * a separate account from the rider's, and no way across between the two guards.
 */
class DriverAuthTest extends TestCase
{
    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create([
            'code' => 'SA',
            'dial_code' => '+966',
            'phone_starts_with' => '5',
            'phone_length' => 9,
            'is_default' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س'])->id,
            'status' => true,
        ]);
    }

    private function signIn(string $phone = '501234567'): string
    {
        $this->postJson('/api/driver/v1/auth/otp', ['dial_code' => '+966', 'phone' => $phone])->assertOk();

        return (string) $this->postJson('/api/driver/v1/auth/verify', ['dial_code' => '+966', 'phone' => $phone, 'code' => '1234'])
            ->assertOk()
            ->json('data.token');
    }

    public function test_a_new_phone_becomes_a_driver_waiting_for_documents(): void
    {
        $this->postJson('/api/driver/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])
            ->assertOk()
            ->assertJsonPath('data.is_new_driver', true);

        $driver = Driver::query()->where('phone', '+966501234567')->firstOrFail();
        $this->assertSame(DriverStatus::PendingDocuments, $driver->status);
        $this->assertSame($this->saudi->id, $driver->country_id);
        $this->assertSame('app', $driver->registered_by);
        $this->assertNotEmpty($driver->uuid);
        $this->assertDatabaseHas('verification_codes', ['authenticatable_type' => 'driver', 'authenticatable_id' => $driver->id, 'code' => '1234']);
    }

    public function test_verify_returns_a_driver_token_that_opens_the_driver_api(): void
    {
        $token = $this->signIn();

        $this->withToken($token)->getJson('/api/driver/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '+966501234567')
            ->assertJsonPath('data.status', 'pending_documents');

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => 'driver']);
    }

    public function test_a_wrong_code_signs_nobody_in(): void
    {
        $this->postJson('/api/driver/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])->assertOk();

        $this->postJson('/api/driver/v1/auth/verify', ['dial_code' => '+966', 'phone' => '501234567', 'code' => '9999'])
            ->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_same_phone_as_a_rider_is_a_separate_driver_account(): void
    {
        $rider = User::query()->create(['phone' => '+966501234567', 'status' => 'active', 'phone_verified_at' => now()]);
        $riderToken = $rider->createToken('mobile-app')->plainTextToken;

        $driverToken = $this->signIn();

        // Neither token opens the other app's API.
        $this->withToken($riderToken)->getJson('/api/driver/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($driverToken)->getJson('/api/mobile/v1/auth/me')->assertUnauthorized();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Driver::query()->count());
    }

    public function test_a_blocked_driver_cannot_sign_in_but_a_suspended_one_can(): void
    {
        Driver::query()->create(['phone' => '+966501234567', 'country_id' => $this->saudi->id, 'status' => DriverStatus::Blocked]);
        $this->postJson('/api/driver/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])->assertStatus(422);

        Driver::query()->create(['phone' => '+966509999999', 'country_id' => $this->saudi->id, 'status' => DriverStatus::Suspended, 'status_reason' => 'Licence expired']);
        $token = $this->signIn('509999999');
        $this->withToken($token)->getJson('/api/driver/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', 'Licence expired');
    }

    public function test_logout_ends_the_token(): void
    {
        $token = $this->signIn();

        $this->withToken($token)->postJson('/api/driver/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
