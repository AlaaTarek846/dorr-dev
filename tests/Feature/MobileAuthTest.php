<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Models\User;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private const DIAL_CODE = '+966';

    private const PHONE = '501234567';

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);

        Country::create([
            'code' => 'SA',
            'code_alpha3' => 'SAU',
            'dial_code' => '+966',
            'phone_starts_with' => '5',
            'phone_length' => 9,
            'is_default' => true,
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);
    }

    private function payload(string $code = '', ?string $dialCode = null, ?string $phone = null): array
    {
        return [
            'dial_code' => $dialCode ?? self::DIAL_CODE,
            'phone' => $phone ?? self::PHONE,
            'code' => $code,
        ];
    }

    public function test_request_otp_creates_new_user_and_returns_fixed_code(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/otp', $this->payload())
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['masked_phone', 'is_new_user', 'account_state', 'resend_cooldown_seconds']]);

        $this->assertTrue($response->json('data.is_new_user'));
        $this->assertSame('active', $response->json('data.account_state'));

        $user = User::query()->where('phone', '+966501234567')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('verification_codes', [
            // Modules\AI\Providers\AIServiceProvider registers a
            // non-enforced Relation::morphMap() for 'user'/'provider' that
            // applies app-wide, not just to AI tables - every polymorphic
            // relation using User/Provider now stores the short alias
            // instead of the FQCN, verification_codes included.
            'authenticatable_type' => $user->getMorphClass(),
            'authenticatable_id' => $user->id,
            'type' => VerificationType::Phone->value,
            'code' => '1234',
        ]);
    }

    public function test_request_otp_logs_in_existing_user(): void
    {
        User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $response = $this->postJson('/api/mobile/v1/auth/otp', $this->payload())
            ->assertOk();

        $this->assertFalse($response->json('data.is_new_user'));
        $this->assertSame('active', $response->json('data.account_state'));
    }

    public function test_request_otp_rejects_phone_with_invalid_length(): void
    {
        $this->postJson('/api/mobile/v1/auth/otp', $this->payload(phone: '50123'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_request_otp_rejects_phone_with_invalid_prefix(): void
    {
        $this->postJson('/api/mobile/v1/auth/otp', $this->payload(phone: '601234567'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_request_otp_rejects_unknown_dial_code(): void
    {
        $this->postJson('/api/mobile/v1/auth/otp', $this->payload(dialCode: '+123'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dial_code');
    }

    public function test_verify_otp_marks_phone_verified_and_issues_token(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'status' => UserStatus::Active,
        ]);

        $user->verificationCodes()->create([
            'type' => VerificationType::Phone->value,
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/mobile/v1/auth/verify', $this->payload('1234'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type', 'is_restored']]);

        $this->assertTrue($user->fresh()->phone_verified_at !== null);
        $this->assertFalse($response->json('data.is_restored'));
        $this->assertSame('Bearer', $response->json('data.token_type'));
    }

    public function test_request_otp_reports_deleted_account_without_otp_or_restore(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $user->delete();

        $response = $this->postJson('/api/mobile/v1/auth/otp', $this->payload())
            ->assertOk();

        $this->assertSame('deleted', $response->json('data.account_state'));
        $this->assertArrayNotHasKey('is_new_user', $response->json('data'));
        $this->assertNull($response->json('data.resend_cooldown_seconds'));

        // Not restored, and NO code sent for the normal login attempt.
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseCount('verification_codes', 0);
    }

    public function test_request_restore_otp_sends_code_for_deleted_account(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        $user->delete();

        $response = $this->postJson('/api/mobile/v1/auth/otp/restore', $this->payload())
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['masked_phone', 'account_state', 'resend_cooldown_seconds']]);

        $this->assertSame('restore', $response->json('data.account_state'));

        // Code was issued, but the account is still deleted until it is verified.
        $this->assertDatabaseHas('verification_codes', [
            'authenticatable_type' => User::class,
            'authenticatable_id' => $user->id,
            'type' => VerificationType::Phone->value,
            'code' => '1234',
        ]);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_request_restore_otp_rejects_active_account(): void
    {
        User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $this->postJson('/api/mobile/v1/auth/otp/restore', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_verify_otp_restores_deleted_account(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'status' => UserStatus::Active,
        ]);
        $user->delete();

        $user->verificationCodes()->create([
            'type' => VerificationType::Phone->value,
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/mobile/v1/auth/verify', $this->payload('1234'))
            ->assertOk();

        $this->assertTrue($response->json('data.is_restored'));
        $this->assertSame($user->id, $response->json('data.user.id'));

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->assertTrue($user->fresh()->phone_verified_at !== null);
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_verify_otp_keeps_account_deleted_on_wrong_code(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'status' => UserStatus::Active,
        ]);
        $user->delete();

        $user->verificationCodes()->create([
            'type' => VerificationType::Phone->value,
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $this->postJson('/api/mobile/v1/auth/verify', $this->payload('0000'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_verify_otp_rejects_wrong_code(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'status' => UserStatus::Active,
        ]);

        $user->verificationCodes()->create([
            'type' => VerificationType::Phone->value,
            'code' => '1234',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $this->postJson('/api/mobile/v1/auth/verify', $this->payload('000000'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertTrue($user->fresh()->phone_verified_at === null);
    }

    public function test_protected_route_denied_before_phone_verification(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'status' => UserStatus::Active,
        ]);

        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->getJson('/api/mobile/v1/auth/me', $this->bearerHeaders($token))
            ->assertForbidden();
    }

    public function test_protected_route_allowed_after_phone_verification(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->getJson('/api/mobile/v1/auth/me', $this->bearerHeaders($token))
            ->assertOk();
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->postJson('/api/mobile/v1/auth/logout', [], $this->bearerHeaders($token))
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_each_sign_in_saves_the_country_it_came_from_saudi_when_unknown(): void
    {
        $egypt = Country::create([
            'code' => 'EG', 'code_alpha3' => 'EGY', 'dial_code' => '+20', 'phone_starts_with' => '1', 'phone_length' => 10,
            'is_default' => false, 'flag_id' => Flag::query()->value('id'), 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP'])->id, 'status' => true,
        ]);
        $saudi = Country::query()->where('code', 'SA')->value('id');
        $user = User::query()->create(['phone' => '+966501234567', 'status' => UserStatus::Active]);
        $code = fn () => $user->verificationCodes()->create(['type' => VerificationType::Phone->value, 'code' => '1234', 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);

        // Signed in from Egypt (Cloudflare tells us).
        $code();
        $this->postJson('/api/mobile/v1/auth/verify', $this->payload('1234'), ['CF-IPCountry' => 'EG'])->assertOk();
        $this->assertSame($egypt->id, $user->fresh()->logged_in_country_id);

        // A country we don't have: Saudi Arabia.
        $code();
        $this->postJson('/api/mobile/v1/auth/verify', $this->payload('1234'), ['CF-IPCountry' => 'FR'])->assertOk();
        $this->assertSame($saudi, $user->fresh()->logged_in_country_id);

        // And the app's country follows the sign-in, not the phone's.
        $user->forceFill(['logged_in_country_id' => $egypt->id])->save();
        $token = $user->createToken('mobile-app')->plainTextToken;
        $this->getJson('/api/mobile/v1/wallet', $this->bearerHeaders($token))->assertOk()->assertJsonPath('data.currency_code', 'EGP');
    }

    public function test_logout_forgets_this_phones_push_id(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
        foreach (['this-phone', 'other-phone'] as $id) {
            NotificationDevice::query()->create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'player_id' => $id, 'platform' => 'android']);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;
        $this->postJson('/api/mobile/v1/auth/logout', ['player_id' => 'this-phone'], $this->bearerHeaders($token))->assertOk();

        // No more messages or calls ring on the signed-out phone; the other phone keeps them.
        $this->assertSame(['other-phone'], $user->notificationDevices()->pluck('player_id')->all());
    }

    private function bearerHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
