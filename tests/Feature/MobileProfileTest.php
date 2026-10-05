<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\User\Models\User;
use Tests\TestCase;

class MobileProfileTest extends TestCase
{
    use RefreshDatabase;

    private const DIAL_CODE = '+966';

    private const PHONE = '501234567';

    private const NEW_PHONE = '509876543';

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

    private function verifiedUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'phone' => '+966'.self::PHONE,
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ], $overrides));
    }

    private function bearerHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken];
    }

    public function test_update_identity_saves_name_and_gender(): void
    {
        $user = $this->verifiedUser();

        $this->putJson('/api/mobile/v1/profile/identity', [
            'name' => 'Test User',
            'gender' => 'male',
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Test User')
            ->assertJsonPath('data.gender', 'male');

        $this->assertSame('Test User', $user->fresh()->name);
    }

    public function test_update_identity_rejects_invalid_gender(): void
    {
        $user = $this->verifiedUser();

        $this->putJson('/api/mobile/v1/profile/identity', [
            'name' => 'Test User',
            'gender' => 'unknown',
        ], $this->bearerHeaders($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gender');
    }

    /** The profile paths are aliases of the guarded phone/change flow (see PhoneChangeTest). */
    public function test_request_phone_change_sends_otp_without_touching_current_number(): void
    {
        $user = $this->verifiedUser();

        $this->postJson('/api/mobile/v1/profile/phone/request', [
            'dial_code' => self::DIAL_CODE,
            'phone' => self::NEW_PHONE,
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        $this->assertSame('+966'.self::PHONE, $user->fresh()->phone);
        $this->assertDatabaseHas('user_phone_changes', [
            'user_id' => $user->id,
            'new_phone' => '+966'.self::NEW_PHONE,
        ]);
    }

    public function test_request_phone_change_rejects_current_number(): void
    {
        $user = $this->verifiedUser();

        $this->postJson('/api/mobile/v1/profile/phone/request', [
            'dial_code' => self::DIAL_CODE,
            'phone' => self::PHONE,
        ], $this->bearerHeaders($user))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'phone_change_same_number');
    }

    public function test_confirm_phone_change_swaps_number_after_valid_code(): void
    {
        $user = $this->verifiedUser();

        $this->postJson('/api/mobile/v1/profile/phone/request', [
            'dial_code' => self::DIAL_CODE,
            'phone' => self::NEW_PHONE,
        ], $this->bearerHeaders($user))->assertOk();

        $code = \Modules\User\Models\UserPhoneChange::query()->where('user_id', $user->id)->value('code');

        $this->postJson('/api/mobile/v1/profile/phone/confirm', [
            'code' => $code,
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.phone', '+966'.self::NEW_PHONE);

        $fresh = $user->fresh();
        $this->assertSame('+966'.self::NEW_PHONE, $fresh->phone);
        $this->assertNotNull($fresh->phone_verified_at);
    }

    public function test_confirm_phone_change_rejects_wrong_code(): void
    {
        $user = $this->verifiedUser();

        $this->postJson('/api/mobile/v1/profile/phone/request', [
            'dial_code' => self::DIAL_CODE,
            'phone' => self::NEW_PHONE,
        ], $this->bearerHeaders($user))->assertOk();

        $this->postJson('/api/mobile/v1/profile/phone/confirm', [
            'code' => '000000',
        ], $this->bearerHeaders($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame('+966'.self::PHONE, $user->fresh()->phone);
    }

    public function test_request_email_change_sends_otp_without_touching_current_email(): void
    {
        $user = $this->verifiedUser(['email' => 'old@example.com']);

        $this->postJson('/api/mobile/v1/profile/email/request', [
            'email' => 'new@example.com',
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonStructure(['data' => ['masked_email', 'resend_cooldown_seconds']]);

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertDatabaseHas('verification_codes', [
            'authenticatable_type' => User::class,
            'authenticatable_id' => $user->id,
            'type' => VerificationType::Email->value,
        ]);
    }

    public function test_request_email_change_rejects_taken_email(): void
    {
        $user = $this->verifiedUser(['email' => 'old@example.com']);
        $this->verifiedUser(['phone' => '+966509876543', 'email' => 'taken@example.com']);

        $this->postJson('/api/mobile/v1/profile/email/request', [
            'email' => 'taken@example.com',
        ], $this->bearerHeaders($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_confirm_email_change_swaps_email_after_valid_code(): void
    {
        $user = $this->verifiedUser(['email' => 'old@example.com']);

        $this->postJson('/api/mobile/v1/profile/email/request', [
            'email' => 'new@example.com',
        ], $this->bearerHeaders($user))->assertOk();

        $code = $user->verificationCodes()
            ->where('type', VerificationType::Email->value)
            ->whereNull('verified_at')
            ->latest('id')
            ->firstOrFail()
            ->code;

        $this->postJson('/api/mobile/v1/profile/email/confirm', [
            'code' => $code,
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');

        $fresh = $user->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_update_avatar_stores_image_and_returns_url(): void
    {
        Storage::fake('public');
        $user = $this->verifiedUser();

        $this->postJson('/api/mobile/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.avatar', fn ($avatar) => $avatar !== null);

        $this->assertSame(1, $user->fresh()->getMedia('avatar')->count());
        $media = $user->fresh()->getFirstMedia('avatar');
        if (function_exists('imagewebp')) {
            $this->assertSame('image/webp', $media?->mime_type);
            $this->assertNotFalse($media && str_ends_with($media->file_name, '.webp'));
        }
    }

    public function test_delete_avatar_removes_media_and_returns_null_avatar(): void
    {
        Storage::fake('public');
        $user = $this->verifiedUser();
        $headers = $this->bearerHeaders($user);

        $this->postJson('/api/mobile/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ], $headers)->assertOk();

        $this->deleteJson('/api/mobile/v1/profile/avatar', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.avatar', null);

        $this->assertSame(0, $user->fresh()->getMedia('avatar')->count());
    }

    public function test_delete_avatar_without_avatar_is_ok(): void
    {
        $user = $this->verifiedUser();

        $this->deleteJson('/api/mobile/v1/profile/avatar', [], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.avatar', null);
    }

    public function test_delete_avatar_requires_authentication(): void
    {
        $this->deleteJson('/api/mobile/v1/profile/avatar')->assertUnauthorized();
    }

    public function test_update_avatar_rejects_non_image(): void
    {
        Storage::fake('public');
        $user = $this->verifiedUser();

        $this->postJson('/api/mobile/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('avatar.txt', 10),
        ], $this->bearerHeaders($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    // -----------------------------------------------------------------------
    // Delete account
    // -----------------------------------------------------------------------

    public function test_delete_account_soft_deletes_user_and_returns_success(): void
    {
        $user = $this->verifiedUser();
        $headers = $this->bearerHeaders($user);

        $this->deleteJson('/api/mobile/v1/profile/account', [], $headers)
            ->assertOk()
            ->assertJsonPath('message', __('api.account_deleted'));

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_delete_account_revokes_all_tokens(): void
    {
        $user = $this->verifiedUser();

        // Issue two tokens to simulate multiple devices.
        $user->createToken('mobile-app');
        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->deleteJson('/api/mobile/v1/profile/account', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_delete_account_requires_authentication(): void
    {
        $this->deleteJson('/api/mobile/v1/profile/account')
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // Restore deleted account on re-login
    // -----------------------------------------------------------------------

    public function test_deleted_user_is_reported_deleted_without_restore_on_otp_request(): void
    {
        $user = $this->verifiedUser();
        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);

        // Requesting an OTP for a deleted account only reports its state — it must
        // NOT send a code, and must NOT restore the account behind the user's back.
        $this->postJson('/api/mobile/v1/auth/otp', [
            'dial_code' => self::DIAL_CODE,
            'phone'     => self::PHONE,
        ])
            ->assertOk()
            ->assertJsonPath('data.account_state', 'deleted');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_deleted_user_is_restored_via_restore_flow_and_can_verify(): void
    {
        $user = $this->verifiedUser();
        $user->delete();

        // The restore endpoint sends the OTP without logging the user in.
        $this->postJson('/api/mobile/v1/auth/otp/restore', [
            'dial_code' => self::DIAL_CODE,
            'phone'     => self::PHONE,
        ])->assertOk();

        // Verifying the demo code restores the account and issues a fresh token.
        $this->postJson('/api/mobile/v1/auth/verify', [
            'dial_code' => self::DIAL_CODE,
            'phone'     => self::PHONE,
            'code'      => '1234',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user', 'is_restored']])
            ->assertJsonPath('data.is_restored', true);

        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
    }
}
