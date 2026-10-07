<?php

namespace Tests\Feature;

use App\Enums\ReferralStatus;
use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Services\General\ReferralCodeService;
use App\Services\General\ReferralService;
use App\Support\Referral\ReferrableType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        Country::create([
            'code' => 'SA', 'code_alpha3' => 'SAU', 'dial_code' => '+966', 'phone_starts_with' => '5',
            'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);

        $this->user = $this->makeUser('+966501234567');
    }

    private function makeUser(string $phone): User
    {
        return User::query()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
            'name' => 'User '.$phone,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function asUser(?User $user = null): array
    {
        $this->app['auth']->forgetGuards();

        return [
            'Authorization' => 'Bearer '.($user ?? $this->user)->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function adminWith(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create(['name' => 'A', 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
        }

        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_a_user_gets_one_active_code_prefixed_dorrfc(): void
    {
        $first = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser())
            ->assertOk()
            ->json('data');

        $this->assertMatchesRegularExpression('/^DORRFC-[A-Z0-9]{6}$/', $first['code']);
        $this->assertTrue($first['is_active']);
        $this->assertNull($first['applied']);

        $second = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser())
            ->assertOk()
            ->json('data.code');

        $this->assertSame($first['code'], $second);
        $this->assertSame(1, ReferralCode::query()->count());
        $this->assertSame('user', ReferralCode::query()->first()->referrable_type);
    }

    public function test_generated_codes_are_unique_and_use_only_english_letters_and_numbers(): void
    {
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $this->makeUser('+96650999000'.$i);
            $codes[] = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser($user))->json('data.code');
        }

        $this->assertCount(5, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^DORRFC-[A-Z0-9]{6}$/', $code);
        }
    }

    public function test_a_provider_can_own_a_referral_code(): void
    {
        $provider = Provider::create(['name' => 'Shop', 'email' => 'shop@example.com', 'status' => 'active']);
        $code = app(ReferralCodeService::class)->codeFor($provider);

        $this->assertSame('provider', $code->referrable_type);
        $this->assertSame($provider->id, $code->referrable_id);
        $this->assertMatchesRegularExpression('/^DORRFC-[A-Z0-9]{6}$/', $code->code);
        $this->assertSame(ReferrableType::aliasFor($provider), 'provider');
    }

    public function test_a_valid_referral_is_registered(): void
    {
        $owner = $this->makeUser('+966501111111');
        $code = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser($owner))->json('data.code');
        $friend = $this->makeUser('+966502222222');

        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => strtolower($code)], $this->asUser($friend))
            ->assertCreated()
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.referral_code', $code)
            ->assertJsonPath('data.referrer.type', 'user')
            ->assertJsonPath('data.referred.type', 'user');

        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $owner->id,
            'referred_id' => $friend->id,
            'status' => 'registered',
        ]);
    }

    public function test_tracking_the_same_code_again_is_idempotent(): void
    {
        $owner = $this->makeUser('+966501111111');
        $code = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser($owner))->json('data.code');
        $friend = $this->makeUser('+966502222222');

        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $code], $this->asUser($friend))->assertCreated();
        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $code], $this->asUser($friend))
            ->assertOk()
            ->assertJsonPath('data.status', 'registered');

        $this->assertSame(1, Referral::query()->count());
    }

    public function test_an_invalid_code_is_rejected(): void
    {
        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => 'DORRFC-XXXXXX'], $this->asUser())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');
    }

    public function test_an_inactive_code_cannot_be_used(): void
    {
        $owner = $this->makeUser('+966501111111');
        $code = app(ReferralCodeService::class)->codeFor($owner);
        $code->update(['is_active' => false]);

        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $code->code], $this->asUser())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');
    }

    public function test_self_referral_is_rejected(): void
    {
        $code = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser())->json('data.code');

        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $code], $this->asUser())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');
    }

    public function test_a_second_different_code_is_rejected(): void
    {
        $ownerA = $this->makeUser('+966501111111');
        $ownerB = $this->makeUser('+966503333333');
        $codeA = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser($ownerA))->json('data.code');
        $codeB = $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser($ownerB))->json('data.code');
        $friend = $this->makeUser('+966502222222');

        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $codeA], $this->asUser($friend))->assertCreated();
        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $codeB], $this->asUser($friend))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');

        $this->assertSame(1, Referral::query()->count());
    }

    public function test_a_user_can_be_referred_by_a_provider(): void
    {
        $provider = Provider::create(['name' => 'Shop', 'email' => 'shop@example.com', 'status' => 'active']);
        $code = app(ReferralCodeService::class)->codeFor($provider);

        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $code->code], $this->asUser())
            ->assertCreated()
            ->assertJsonPath('data.referrer.type', 'provider')
            ->assertJsonPath('data.referred.type', 'user');
    }

    public function test_deactivating_a_code_keeps_referral_history(): void
    {
        $owner = $this->makeUser('+966501111111');
        $code = app(ReferralCodeService::class)->codeFor($owner);
        $friend = $this->makeUser('+966502222222');
        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $code->code], $this->asUser($friend))->assertCreated();

        $this->adminWith(['referral-codes.view', 'referral-codes.change-status']);
        $this->patchJson("/api/admin/v1/referral-codes/{$code->id}/status", ['status' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(1, Referral::query()->count());
    }

    public function test_status_can_move_from_registered_to_completed(): void
    {
        $owner = $this->makeUser('+966501111111');
        $code = app(ReferralCodeService::class)->codeFor($owner);
        $friend = $this->makeUser('+966502222222');
        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => $code->code], $this->asUser($friend))->assertCreated();

        $referral = Referral::query()->first();
        $updated = app(ReferralService::class)->markCompleted($referral);

        $this->assertSame(ReferralStatus::Completed, $updated->status);
        $this->assertNotNull($updated->completed_at);
    }

    public function test_verifying_otp_mints_a_referral_code(): void
    {
        $this->user->update(['phone_verified_at' => null]);
        $this->user->verificationCodes()->create([
            'type' => 'phone',
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $this->postJson('/api/mobile/v1/auth/verify', [
            'dial_code' => '+966',
            'phone' => '501234567',
            'code' => '1234',
        ])->assertOk();

        $this->assertDatabaseHas('referral_codes', [
            'referrable_type' => 'user',
            'referrable_id' => $this->user->id,
            'is_active' => 1,
        ]);
        $this->assertMatchesRegularExpression(
            '/^DORRFC-[A-Z0-9]{6}$/',
            ReferralCode::query()->where('referrable_id', $this->user->id)->value('code'),
        );
    }

    public function test_guests_cannot_read_or_track_codes(): void
    {
        $this->getJson('/api/mobile/v1/referrals/my-code')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/referrals/track', ['referral_code' => 'DORRFC-AAAAAA'])->assertUnauthorized();
    }

    public function test_admin_can_list_codes_and_referrals(): void
    {
        $this->getJson('/api/mobile/v1/referrals/my-code', $this->asUser());
        $this->adminWith(['referral-codes.view', 'referrals.view']);

        $this->getJson('/api/admin/v1/referral-codes')->assertOk()->assertJsonPath('data.0.code', ReferralCode::query()->first()->code);
        $this->getJson('/api/admin/v1/referrals')->assertOk();
    }
}
