<?php

namespace Tests\Feature;

use App\Enums\VerificationType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\User;
use Modules\Wallet\Services\PinService;
use Tests\TestCase;

/**
 * A device the wallet has never opened from before still has to prove the phone on file is reachable
 * from it, once the PIN itself checks out (wallet policy bend 3, docs/wallet-tasks.md §10.10).
 */
class WalletDeviceTrustTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/mobile/v1/wallet';

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth_flow.otp_resend_cooldown_seconds' => 0]);

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true]);

        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'status' => 'active', 'phone_verified_at' => now()]);
        app(PinService::class)->set($this->alice, '1234');
        Sanctum::actingAs($this->alice, [], 'user_api');
    }

    private function headers(array $extra = []): array
    {
        return $extra + ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'];
    }

    private function currentCode(): string
    {
        return (string) VerificationCode::query()
            ->where('authenticatable_type', User::class)
            ->where('authenticatable_id', $this->alice->id)
            ->where('type', VerificationType::DeviceTrust->value)
            ->whereNull('verified_at')
            ->latest('id')
            ->firstOrFail()->code;
    }

    public function test_verify_says_untrusted_with_no_device_id_at_all(): void
    {
        $this->postJson(self::BASE.'/pin/verify', [], $this->headers())
            ->assertOk()->assertJsonPath('data.device_trusted', false);
    }

    public function test_verify_says_untrusted_for_a_device_never_confirmed(): void
    {
        $this->postJson(self::BASE.'/pin/verify', [], $this->headers(['X-Device-Id' => 'device-a']))
            ->assertOk()->assertJsonPath('data.device_trusted', false);
    }

    public function test_confirming_needs_the_right_code_and_the_same_device_id(): void
    {
        $this->postJson(self::BASE.'/device/verify-code', [], $this->headers(['X-Device-Id' => 'device-a']))->assertOk();

        $this->postJson(self::BASE.'/device/confirm', ['code' => '0000'], $this->headers(['X-Device-Id' => 'device-a']))
            ->assertStatus(422);

        $this->postJson(self::BASE.'/device/confirm', ['code' => $this->currentCode()], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'wallet_device_id_required');

        $this->postJson(self::BASE.'/device/confirm', ['code' => $this->currentCode()], $this->headers(['X-Device-Id' => 'device-a']))
            ->assertOk()->assertJsonPath('data.trusted', true);
    }

    public function test_a_confirmed_device_is_trusted_from_then_on_and_others_are_not(): void
    {
        $this->postJson(self::BASE.'/device/verify-code', [], $this->headers(['X-Device-Id' => 'device-a']))->assertOk();
        $this->postJson(self::BASE.'/device/confirm', ['code' => $this->currentCode()], $this->headers(['X-Device-Id' => 'device-a']))->assertOk();

        $this->postJson(self::BASE.'/pin/verify', [], $this->headers(['X-Device-Id' => 'device-a']))
            ->assertOk()->assertJsonPath('data.device_trusted', true);

        // A different device on the same account still has to prove itself separately.
        $this->postJson(self::BASE.'/pin/verify', [], $this->headers(['X-Device-Id' => 'device-b']))
            ->assertOk()->assertJsonPath('data.device_trusted', false);
    }

    public function test_confirming_device_trust_needs_the_wallet_pin_like_everything_else(): void
    {
        $this->postJson(self::BASE.'/device/verify-code', [], ['X-Country' => 'SA'])
            ->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');
    }
}
