<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
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
            ->assertJsonStructure(['success', 'data' => ['masked_phone', 'is_new_user', 'resend_cooldown_seconds']]);

        $this->assertTrue($response->json('data.is_new_user'));

        $user = User::query()->where('phone', '+966501234567')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('verification_codes', [
            'authenticatable_type' => User::class,
            'authenticatable_id' => $user->id,
            'type' => VerificationType::Phone->value,
            'code' => '123456',
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
            'code' => '123456',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        $response = $this->postJson('/api/mobile/v1/auth/verify', $this->payload('123456'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);

        $this->assertTrue($user->fresh()->phone_verified_at !== null);
        $this->assertSame('Bearer', $response->json('data.token_type'));
    }

    public function test_verify_otp_rejects_wrong_code(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'status' => UserStatus::Active,
        ]);

        $user->verificationCodes()->create([
            'type' => VerificationType::Phone->value,
            'code' => '123456',
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

    private function bearerHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
