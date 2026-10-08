<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\User;
use Modules\User\Models\UserPhoneHistory;
use Modules\Wallet\Models\WalletPin;
use Modules\Wallet\Services\PinService;
use Tests\TestCase;

/**
 * Changing the logged-in user's phone number — the guarded replacement for the old "just a field
 * on the profile form" behaviour (wallet policy bend 38, docs/wallet-tasks.md §10.9).
 */
class PhoneChangeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/mobile/v1/phone/change';

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth_flow.email_otp_fixed' => null, 'auth_flow.otp_resend_cooldown_seconds' => 0]);

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'status' => 'active', 'phone_verified_at' => now()]);
        Sanctum::actingAs($this->alice, [], 'user_api');
    }

    private function headers(array $extra = []): array
    {
        return $extra + ['Accept' => 'application/json'];
    }

    private function start(string $phone = '500000099', array $headers = []): TestResponse
    {
        return $this->postJson(self::BASE, ['dial_code' => '+966', 'phone' => $phone], $this->headers($headers));
    }

    private function confirmedCode(): string
    {
        return (string) config('auth_flow.phone_otp_fixed', '1234');
    }

    // ------------------------------------------------------------------ the old, unguarded path is gone

    public function test_the_profile_update_endpoint_no_longer_touches_the_phone(): void
    {
        $this->postJson('/api/user/v1/profile', [
            'name' => 'Alice', 'email' => 'alice@example.com', 'gender' => 'female', 'phone' => '+966500000999',
        ], $this->headers())->assertOk();

        $this->assertSame('+966500000001', $this->alice->fresh()->phone, 'the profile form cannot change the phone anymore');
    }

    // ------------------------------------------------------------------ starting a change

    public function test_the_new_number_cannot_already_be_the_current_one(): void
    {
        $this->start('500000001')->assertStatus(422)->assertJsonPath('error_code', 'phone_change_same_number');
    }

    public function test_the_new_number_cannot_belong_to_someone_else(): void
    {
        User::create(['name' => 'Bob', 'phone' => '+966500000099', 'status' => 'active']);

        $this->start('500000099')->assertStatus(422)->assertJsonPath('error_code', 'phone_change_taken');
    }

    public function test_a_pin_is_required_only_once_one_exists(): void
    {
        // No wallet PIN yet: starting a change needs nothing but the new number.
        $this->start()->assertOk();

        app(PinService::class)->set($this->alice, '1234');

        $this->start('500000098')->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');
        $this->start('500000098', ['X-Wallet-Pin' => '9999'])->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');
        $this->start('500000098', ['X-Wallet-Pin' => '1234'])->assertOk();
    }

    public function test_a_frozen_wallet_refuses_the_whole_flow(): void
    {
        app(PinService::class)->set($this->alice, '1234');
        WalletPin::query()->update(['frozen_at' => now()]);

        $this->start('500000098', ['X-Wallet-Pin' => '1234'])->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_frozen');
    }

    // ------------------------------------------------------------------ confirming

    public function test_confirming_needs_a_started_change_first(): void
    {
        $this->postJson(self::BASE.'/confirm', ['code' => $this->confirmedCode()], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'phone_change_none_started');
    }

    public function test_the_right_code_changes_the_phone_records_history_and_revokes_other_sessions(): void
    {
        // A second session (e.g. another device) that should get signed out once the change lands.
        $otherToken = $this->alice->createToken('other-device');

        $this->start('500000099')->assertOk();

        $wrong = $this->confirmedCode() === '0000' ? '1111' : '0000';
        $this->postJson(self::BASE.'/confirm', ['code' => $wrong], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'phone_change_invalid_code');

        $this->postJson(self::BASE.'/confirm', ['code' => $this->confirmedCode()], $this->headers())
            ->assertOk()->assertJsonPath('data.phone', '+966500000099');

        $this->assertSame('+966500000099', $this->alice->fresh()->phone);

        $history = UserPhoneHistory::query()->where('user_id', $this->alice->id)->sole();
        $this->assertSame('+966500000001', $history->old_phone);
        $this->assertSame('+966500000099', $history->new_phone);

        // The other session's token row is gone — Sanctum::actingAs() short-circuits real bearer-token
        // resolution in tests, so the DB row itself is the only reliable thing to check here.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_wrong_codes_lock_out_after_five_tries(): void
    {
        $this->start('500000099')->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::BASE.'/confirm', ['code' => '0000'], $this->headers())->assertStatus(422);
        }

        $this->postJson(self::BASE.'/confirm', ['code' => $this->confirmedCode()], $this->headers())
            ->assertStatus(423)->assertJsonPath('error_code', 'phone_change_too_many_attempts');

        $this->assertSame('+966500000001', $this->alice->fresh()->phone, 'still the original number');
    }

    public function test_only_numbers_of_the_countrys_own_shape_are_accepted(): void
    {
        $this->postJson(self::BASE, ['dial_code' => '+966', 'phone' => '12'], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }
}
