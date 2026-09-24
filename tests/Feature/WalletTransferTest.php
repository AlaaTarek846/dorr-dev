<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Modules\Wallet\Support\MaskedName;
use Modules\Wallet\Support\WalletNumber;
use Tests\TestCase;

/**
 * Phase 10 — user → user transfers (wallet-plan.md §10).
 */
class WalletTransferTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    private User $alice;

    private User $bob;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $egp = Currency::create(['code' => 'EGP', 'symbol' => 'EGP']);
        $flagSa = Flag::create(['code' => 'sa']);
        $flagEg = Flag::create(['code' => 'eg']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flagSa->id, 'currency_id' => $sar->id, 'status' => true]);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => $flagEg->id, 'currency_id' => $egp->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flagSa->id]);

        $this->wallets = app(WalletService::class);
        $this->alice = $this->makeUser('Alice', '+966500000001');
        $this->bob = $this->makeUser('Bob', '+966500000002');
        app(PinService::class)->set($this->alice, '1234');
        app(PinService::class)->set($this->bob, '1234');

        // Transfers are off by default in every country — switch Saudi on.
        WalletSetting::query()->where('country_id', $this->saudi->id)->update(['transfers_enabled' => true]);

        Sanctum::actingAs($this->alice, [], 'user_api');
    }

    private function makeUser(string $name, string $phone, ?Country $country = null, string $status = 'active'): User
    {
        return User::create([
            'name' => $name, 'phone' => $phone, 'country_id' => ($country ?? $this->saudi)->id,
            'status' => $status, 'phone_verified_at' => now(),
        ]);
    }

    private function fund(User $user, int $withdrawable, int $spendOnly = 0): Wallet
    {
        $wallet = $this->wallets->firstOrCreateWallet($user, $this->saudi);
        if ($withdrawable > 0) {
            $this->wallets->credit($wallet, $withdrawable, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        }
        if ($spendOnly > 0) {
            $this->wallets->credit($wallet, $spendOnly, WalletBucket::SpendOnly, WalletTransactionType::TopupBonus);
        }

        return $wallet;
    }

    private function walletOf(User $user): Wallet
    {
        return Wallet::query()->where('owner_type', 'user')->where('owner_id', $user->id)->firstOrFail()->refresh();
    }

    /**
     * The real two-step flow: look the recipient up (that's where they are validated and
     * shown), then pay with the token. If the lookup itself is refused, that response is returned.
     */
    private function send(string $phone, int $amount, string $key = 'key-12345678', array $extra = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $lookup = $this->lookup(['mode' => 'phone', 'phone' => $phone]);

        if (! $lookup->isSuccessful()) {
            return $lookup;
        }

        return $this->pay($lookup->json('data.recipient_token'), $amount, $key, $extra, $headers);
    }

    private function lookup(array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mobile/v1/wallet/transfers/lookup', $body, array_merge(['X-Country' => 'SA'], $headers));
    }

    private function pay(string $token, int $amount, string $key = 'key-12345678', array $extra = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mobile/v1/wallet/transfers', [
            'recipient_token' => $token, 'amount_minor' => $amount,
        ] + $extra, array_merge(['X-Wallet-Pin' => '1234', 'Idempotency-Key' => $key, 'X-Country' => 'SA'], $headers));
    }

    private function settings(array $values): void
    {
        WalletSetting::query()->where('country_id', $this->saudi->id)->update($values);
    }

    // ------------------------------------------------ the worked example (§10)

    public function test_the_recipient_can_spend_but_never_withdraw_even_when_it_is_sent_back(): void
    {
        $this->fund($this->alice, 100000); // charged 1000.00, all withdrawable

        $this->send('500000002', 50000)->assertCreated()->assertJsonPath('data.type', 'transfer_out');

        $alice = $this->walletOf($this->alice);
        $bob = $this->walletOf($this->bob);
        $this->assertSame(50000, $alice->withdrawable_minor);
        $this->assertSame(0, $bob->withdrawable_minor, 'received money is never withdrawable');
        $this->assertSame(50000, $bob->spend_only_minor);

        // Both legs share one operation.
        $legs = WalletTransaction::query()->whereIn('type', [WalletTransactionType::TransferOut, WalletTransactionType::TransferIn])->get();
        $this->assertCount(2, $legs);
        $this->assertSame(1, $legs->pluck('operation_id')->unique()->count());

        // Bob returns it: Alice gets it back as spend_only, not withdrawable.
        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->send('500000001', 50000, 'return-key-1')->assertCreated();

        $alice = $this->walletOf($this->alice);
        $this->assertSame(50000, $alice->withdrawable_minor);
        $this->assertSame(50000, $alice->spend_only_minor);
        $this->assertSame(0, $this->walletOf($this->bob)->total_balance ?? $this->walletOf($this->bob)->totalBalanceMinor());
    }

    // ------------------------------------------------------------------ guards

    public function test_a_transfer_needs_the_pin(): void
    {
        $this->fund($this->alice, 10000);

        $this->send('500000002', 1000, headers: ['X-Wallet-Pin' => ''])->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');
        $this->send('500000002', 1000, headers: ['X-Wallet-Pin' => '0000'])->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');

        $this->assertSame(10000, $this->walletOf($this->alice)->withdrawable_minor);
    }

    public function test_transfers_are_off_unless_the_country_enables_them(): void
    {
        $this->fund($this->alice, 10000);
        $this->settings(['transfers_enabled' => false]);

        $this->send('500000002', 1000)->assertStatus(403)->assertJsonPath('error_code', 'transfers_disabled');
        $this->assertSame(10000, $this->walletOf($this->alice)->withdrawable_minor);
    }

    public function test_missing_settings_fail_closed(): void
    {
        $this->fund($this->alice, 10000);
        WalletSetting::query()->where('country_id', $this->saudi->id)->delete();

        $this->send('500000002', 1000)->assertStatus(422)->assertJsonPath('error_code', 'wallet_settings_missing');
    }

    public function test_every_bad_recipient_gets_the_same_answer_and_no_wallet_is_created(): void
    {
        $this->fund($this->alice, 10000);
        $this->makeUser('Blocked', '+966500000004', null, 'blocked');       // not active

        foreach (['500000099', '500000004'] as $phone) { // unknown, blocked
            $this->send($phone, 1000, 'key-for-'.$phone)->assertStatus(422)->assertJsonPath('error_code', 'transfer_recipient_unavailable');
        }

        // Your own number is the one exception: it says so plainly instead of "no account".
        $this->send('500000001', 1000, 'key-for-me')->assertStatus(422)->assertJsonPath('error_code', 'transfer_to_self');

        $this->assertSame(1, Wallet::query()->count(), 'only the sender has a wallet');
        $this->assertSame(10000, $this->walletOf($this->alice)->withdrawable_minor);
    }

    // ------------------------------------------------------------------ limits

    public function test_the_per_transaction_limit(): void
    {
        $this->fund($this->alice, 100000);
        $this->settings(['transfer_max_per_transaction_minor' => 20000]);

        $this->send('500000002', 20001)->assertStatus(422)->assertJsonPath('data.scope', 'per_transaction');
        $this->send('500000002', 20000, 'key-00000002')->assertCreated();
    }

    public function test_the_daily_limit_is_cumulative_and_says_what_is_left(): void
    {
        $this->fund($this->alice, 100000);
        $this->settings(['transfer_max_per_day_minor' => 30000]);

        $this->send('500000002', 20000)->assertCreated();

        $this->send('500000002', 15000, 'key-00000002')
            ->assertStatus(422)
            ->assertJsonPath('data.scope', 'per_day')
            ->assertJsonPath('data.remaining_minor', 10000);
        $this->send('500000002', 10000, 'key-00000003')->assertCreated();

        $this->assertSame(70000, $this->walletOf($this->alice)->withdrawable_minor);
    }

    public function test_yesterdays_transfers_do_not_count_towards_todays_limit(): void
    {
        $this->fund($this->alice, 100000);
        $this->settings(['transfer_max_per_day_minor' => 30000]);
        $this->send('500000002', 30000)->assertCreated();
        WalletTransaction::query()->where('type', WalletTransactionType::TransferOut)->update(['created_at' => now()->subDay()->startOfDay()->subHour()]);

        $this->send('500000002', 30000, 'key-00000002')->assertCreated();
    }

    public function test_the_monthly_limit(): void
    {
        $this->fund($this->alice, 100000);
        $this->settings(['transfer_max_per_month_minor' => 25000]);

        $this->send('500000002', 25000)->assertCreated();
        $this->send('500000002', 1, 'key-00000002')->assertStatus(422)->assertJsonPath('data.scope', 'per_month');
    }

    // ----------------------------------------------------------------- balance

    public function test_held_money_cannot_be_transferred(): void
    {
        $wallet = $this->fund($this->alice, 100000);
        $this->wallets->hold($wallet, 80000, WalletBucket::Withdrawable, ['reason_code' => 'withdrawal_request']);

        // Balance is 1000.00 but only 200.00 is free.
        $this->send('500000002', 30000)->assertStatus(422)->assertJsonPath('error_code', 'insufficient_balance');
        $this->send('500000002', 20000, 'key-00000002')->assertCreated();
    }

    public function test_bonus_money_is_spent_first_unless_the_sender_chooses(): void
    {
        $this->fund($this->alice, 10000, spendOnly: 5000);

        $this->send('500000002', 3000)->assertCreated();
        $alice = $this->walletOf($this->alice);
        $this->assertSame(2000, $alice->spend_only_minor, 'spend_only first');
        $this->assertSame(10000, $alice->withdrawable_minor);

        $this->send('500000002', 4000, 'key-00000002', ['from_bucket' => 'withdrawable'])->assertCreated();
        $this->assertSame(6000, $this->walletOf($this->alice)->withdrawable_minor);

        // Choosing spend_only when it can't cover it is refused, even though the total could.
        $this->send('500000002', 3000, 'key-00000003', ['from_bucket' => 'spend_only'])->assertStatus(422)->assertJsonPath('data.bucket', 'spend_only');
    }

    public function test_nothing_can_be_sent_without_a_funded_wallet(): void
    {
        $this->send('500000002', 100)->assertStatus(422)->assertJsonPath('error_code', 'insufficient_balance');
    }

    public function test_debt_cannot_be_used_to_send_money(): void
    {
        $wallet = $this->fund($this->alice, 1000);
        $this->wallets->debit($wallet, 5000, WalletBucket::Withdrawable, WalletTransactionType::Penalty); // −40.00

        $this->send('500000002', 100)->assertStatus(422)->assertJsonPath('error_code', 'insufficient_balance');
    }

    // ------------------------------------------------------------- idempotency

    public function test_a_retry_with_the_same_key_moves_money_once(): void
    {
        $this->fund($this->alice, 10000);

        $first = $this->send('500000002', 4000)->assertCreated()->json('data.uuid');
        $second = $this->send('500000002', 4000)->assertCreated()->json('data.uuid');

        $this->assertSame($first, $second);
        $this->assertSame(6000, $this->walletOf($this->alice)->withdrawable_minor);
        $this->assertSame(4000, $this->walletOf($this->bob)->spend_only_minor);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::TransferOut)->count());
    }

    public function test_a_retry_is_not_re_checked_against_a_balance_the_first_call_already_spent(): void
    {
        $this->fund($this->alice, 4000);

        $this->send('500000002', 4000)->assertCreated();
        // Balance is now 0 — a dropped-connection retry must still answer 201, not "insufficient".
        $this->send('500000002', 4000)->assertCreated();
    }

    public function test_the_same_key_for_a_different_recipient_or_amount_is_a_409(): void
    {
        $this->fund($this->alice, 10000);
        $this->makeUser('Carol', '+966500000005');

        $this->send('500000002', 1000)->assertCreated();

        $this->send('500000005', 1000)->assertStatus(409)->assertJsonPath('error_code', 'idempotency_conflict');
        $this->send('500000002', 2000)->assertStatus(409)->assertJsonPath('error_code', 'idempotency_conflict');
        $this->assertSame(9000, $this->walletOf($this->alice)->withdrawable_minor);
    }

    // --------------------------------------------------------------- statement

    public function test_both_sides_see_the_transfer_with_the_other_party_masked(): void
    {
        $this->fund($this->alice, 10000);
        $this->send('500000002', 2500)->assertCreated();

        $out = $this->getJson('/api/mobile/v1/wallet/transactions?type=transfer_out', ['X-Country' => 'SA'])->assertOk()->json('data.0');
        $this->assertSame('Transfer sent', $out['type_label']);
        $this->assertSame('B***', $out['counterparty']['name'], 'names of other people are masked');
        $this->assertSame('•••••••••0002', $out['counterparty']['phone']);
        $this->assertStringNotContainsString('500000002', json_encode($out));

        Sanctum::actingAs($this->bob, [], 'user_api');
        $in = $this->getJson('/api/mobile/v1/wallet/transactions', ['X-Country' => 'SA'])->assertOk()->json('data.0');
        $this->assertSame('transfer_in', $in['type']);
        $this->assertSame('spend_only', $in['bucket']);
        $this->assertSame('A***', $in['counterparty']['name']);
    }

    public function test_input_is_validated(): void
    {
        $this->fund($this->alice, 10000);
        $token = $this->lookup(['mode' => 'phone', 'phone' => '500000002'])->json('data.recipient_token');

        $this->pay($token, 0)->assertStatus(422)->assertJsonValidationErrors('amount_minor');
        $this->pay($token, 100, 'short')->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/mobile/v1/wallet/transfers', ['amount_minor' => 100], ['X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'key-12345678', 'X-Country' => 'SA'])
            ->assertStatus(422)->assertJsonValidationErrors('recipient_token');
        $this->lookup(['mode' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->lookup(['mode' => 'phone'])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_providers_have_no_transfer_endpoint(): void
    {
        $this->assertNull(collect(app('router')->getRoutes()->getRoutes())->first(
            fn ($route) => str_contains($route->uri(), 'provider/v1/wallet/transfers'),
        ));
    }

    // ------------------------------------------------- confirm who you are paying, before paying

    public function test_the_recipient_is_shown_masked_before_any_money_moves(): void
    {
        $this->fund($this->alice, 10000);
        $this->bob->update(['name' => 'سارة أحمد محمد']);

        $response = $this->lookup(['mode' => 'phone', 'phone' => '500000002'])->assertOk();

        $response->assertJsonPath('data.name_masked', 'س*** أ*** م***')
            ->assertJsonPath('data.phone', '+966500000002')     // by phone: the full number, as typed
            ->assertJsonPath('data.country_code', 'SA')
            ->assertJsonPath('data.currency_code', 'SAR')
            ->assertJsonPath('data.wallet_number', null);        // a phone lookup never reveals the wallet number
        $this->assertStringNotContainsString('سارة', $response->getContent());

        $this->assertSame(10000, $this->walletOf($this->alice)->withdrawable_minor, 'lookup moves nothing');
        $this->assertSame(0, WalletTransaction::query()->where('type', WalletTransactionType::TransferOut)->count());
    }

    public function test_looking_up_needs_no_pin_but_paying_does(): void
    {
        $this->fund($this->alice, 10000);

        $this->lookup(['mode' => 'phone', 'phone' => '500000002'], ['X-Wallet-Pin' => ''])->assertOk();
    }

    public function test_the_transfer_only_accepts_the_token_from_the_lookup(): void
    {
        $this->fund($this->alice, 10000);
        $token = $this->lookup(['mode' => 'phone', 'phone' => '500000002'])->json('data.recipient_token');

        // Someone else's token, a garbled one, and an expired one are all refused.
        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->pay($token, 100)->assertStatus(422)->assertJsonPath('error_code', 'transfer_recipient_expired');

        Sanctum::actingAs($this->alice, [], 'user_api');
        $this->pay('not-a-token', 100, 'key-00000002')->assertStatus(422)->assertJsonPath('error_code', 'transfer_recipient_expired');

        $this->travel(11)->minutes();
        $this->pay($token, 100, 'key-00000003')->assertStatus(422)->assertJsonPath('error_code', 'transfer_recipient_expired');
        $this->travelBack();

        $this->pay($token, 100, 'key-00000004')->assertCreated();
    }

    public function test_a_retry_after_the_token_expired_still_replays_the_original_transfer(): void
    {
        $this->fund($this->alice, 10000);
        $token = $this->lookup(['mode' => 'phone', 'phone' => '500000002'])->json('data.recipient_token');
        $first = $this->pay($token, 2500, 'same-key-1234')->assertCreated()->json('data.uuid');

        // Connection dropped, app retries much later: it looks the recipient up again and reuses the key.
        $this->travel(30)->minutes();
        $fresh = $this->lookup(['mode' => 'phone', 'phone' => '500000002'])->json('data.recipient_token');
        $second = $this->pay($fresh, 2500, 'same-key-1234')->assertCreated()->json('data.uuid');

        $this->assertSame($first, $second);
        $this->assertSame(7500, $this->walletOf($this->alice)->withdrawable_minor);
    }

    // ------------------------------------------------- phone numbers follow the wallet's country

    public function test_only_numbers_of_the_wallets_country_are_accepted(): void
    {
        $this->fund($this->alice, 10000);
        $this->saudi->update(['phone_starts_with' => '5']);

        // Wrong length, wrong prefix, and a foreign (Egyptian) number are all refused with the format spelled out.
        foreach (['50000000', '5000000022', '400000002', '201001234567'] as $bad) {
            $this->lookup(['mode' => 'phone', 'phone' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone')
                ->assertJsonPath('errors.phone.0', 'Enter a valid SA mobile number (9 digits, starting with 5).');
        }
    }

    public function test_common_ways_of_typing_a_saudi_number_all_work(): void
    {
        $this->fund($this->alice, 10000);
        $this->saudi->update(['phone_starts_with' => '5']);

        foreach (['500000002', '0500000002', '+966500000002', '966500000002', '٥٠٠٠٠٠٠٠٢', '500 000 002'] as $typed) {
            $this->lookup(['mode' => 'phone', 'phone' => $typed])->assertOk()->assertJsonPath('data.phone', '+966500000002');
        }
    }

    public function test_the_balance_tells_the_app_the_countrys_phone_format(): void
    {
        $this->saudi->update(['phone_starts_with' => '5']);

        $this->getJson('/api/mobile/v1/wallet', ['X-Country' => 'SA'])
            ->assertOk()
            ->assertJsonPath('data.phone_length', 9)
            ->assertJsonPath('data.phone_starts_with', '5')
            ->assertJsonPath('data.dial_code', '+966');
    }

    // ------------------------------------------------- sending to a wallet number

    public function test_every_wallet_has_its_own_valid_number_visible_to_its_owner(): void
    {
        $response = $this->getJson('/api/mobile/v1/wallet', ['X-Country' => 'SA'])->assertOk();

        $number = $response->json('data.wallet_number');
        $this->assertTrue(WalletNumber::isValid($number));
        $this->assertSame(WalletNumber::format($number), $response->json('data.wallet_number_formatted'));
        $this->assertSame($number, $this->walletOf($this->alice)->wallet_number);
    }

    public function test_a_person_with_a_wallet_per_country_has_a_different_number_in_each(): void
    {
        $sa = $this->wallets->firstOrCreateWallet($this->bob, $this->saudi);
        $eg = $this->wallets->firstOrCreateWallet($this->bob, $this->egypt);

        $this->assertNotSame($sa->wallet_number, $eg->wallet_number);
        $this->assertTrue(WalletNumber::isValid($sa->wallet_number));
        $this->assertTrue(WalletNumber::isValid($eg->wallet_number));
    }

    public function test_sending_by_wallet_number_lands_on_exactly_that_wallet(): void
    {
        $this->fund($this->alice, 10000);
        $this->bob->update(['name' => 'Bob Builder']);
        $bobSa = $this->wallets->firstOrCreateWallet($this->bob, $this->saudi);
        $bobEg = $this->wallets->firstOrCreateWallet($this->bob, $this->egypt); // same person, other country

        $lookup = $this->lookup(['mode' => 'wallet', 'wallet_number' => WalletNumber::format($bobSa->wallet_number)])->assertOk();
        $lookup->assertJsonPath('data.name_masked', 'B*** B***')
            ->assertJsonPath('data.wallet_number', $bobSa->wallet_number)
            ->assertJsonPath('data.phone', null);   // by wallet number: no phone shown

        $this->pay($lookup->json('data.recipient_token'), 3000)->assertCreated();

        $this->assertSame(3000, $bobSa->fresh()->spend_only_minor);
        $this->assertSame(0, $bobEg->fresh()->spend_only_minor, 'the other country wallet is untouched');
    }

    public function test_a_wallet_number_of_another_country_cannot_be_paid_from_here(): void
    {
        $this->fund($this->alice, 10000);
        $bobEg = $this->wallets->firstOrCreateWallet($this->bob, $this->egypt);

        $this->lookup(['mode' => 'wallet', 'wallet_number' => $bobEg->wallet_number])
            ->assertStatus(422)->assertJsonPath('error_code', 'transfer_recipient_unavailable');
    }

    public function test_a_mistyped_wallet_number_is_caught_by_its_check_digit(): void
    {
        $this->fund($this->alice, 10000);
        $number = $this->wallets->firstOrCreateWallet($this->bob, $this->saudi)->wallet_number;
        $typo = substr($number, 0, 5).(((int) $number[5] + 1) % 10).substr($number, 6);

        $this->lookup(['mode' => 'wallet', 'wallet_number' => $typo])->assertStatus(422)->assertJsonValidationErrors('wallet_number');
        $this->lookup(['mode' => 'wallet', 'wallet_number' => '12345'])->assertStatus(422)->assertJsonValidationErrors('wallet_number');
    }

    public function test_your_own_wallet_number_is_not_a_valid_recipient(): void
    {
        $mine = $this->fund($this->alice, 10000);

        $this->lookup(['mode' => 'wallet', 'wallet_number' => $mine->wallet_number])
            ->assertStatus(422)->assertJsonPath('error_code', 'transfer_to_self');
    }

    public function test_a_recipient_is_found_by_phone_even_when_their_profile_has_no_country_yet(): void
    {
        $this->fund($this->alice, 10000);
        $noCountry = $this->makeUser('Nocountry User', '+966500000055');
        $noCountry->forceFill(['country_id' => null])->save();

        $this->lookup(['mode' => 'phone', 'phone' => '500000055'])
            ->assertOk()->assertJsonPath('data.name_masked', 'N*** U***');
    }

    // ------------------------------------------------------------------ QR codes

    public function test_the_wallet_endpoint_hands_out_the_qr_payload_of_the_wallet(): void
    {
        $data = $this->getJson('/api/mobile/v1/wallet', ['X-Country' => 'SA'])->assertOk()->json('data');

        $this->assertSame('dorr://wallet/SA/'.$data['wallet_number'], $data['qr_payload']);
        $this->assertSame(['country' => 'SA', 'number' => $data['wallet_number']], WalletNumber::parseQr($data['qr_payload']));
    }

    public function test_scanning_a_qr_opens_the_same_confirmation_as_typing_the_number(): void
    {
        $this->fund($this->alice, 10000);
        $this->bob->update(['name' => 'Bob Builder']);
        $bobSa = $this->wallets->firstOrCreateWallet($this->bob, $this->saudi);

        $lookup = $this->lookup(['mode' => 'qr', 'qr' => WalletNumber::qrPayload('SA', $bobSa->wallet_number)])->assertOk();
        $lookup->assertJsonPath('data.name_masked', 'B*** B***')->assertJsonPath('data.wallet_number', $bobSa->wallet_number);

        $this->pay($lookup->json('data.recipient_token'), 2500)->assertCreated();
        $this->assertSame(2500, $bobSa->fresh()->spend_only_minor);
    }

    public function test_a_qr_of_another_country_says_so_precisely(): void
    {
        $bobEg = $this->wallets->firstOrCreateWallet($this->bob, $this->egypt);

        $this->lookup(['mode' => 'qr', 'qr' => WalletNumber::qrPayload('EG', $bobEg->wallet_number)])
            ->assertStatus(422)->assertJsonPath('error_code', 'transfer_qr_other_country');
    }

    public function test_a_random_or_tampered_qr_is_refused_without_any_lookup(): void
    {
        $bobSa = $this->wallets->firstOrCreateWallet($this->bob, $this->saudi);
        $typo = substr($bobSa->wallet_number, 0, 10).(((int) substr($bobSa->wallet_number, -1) + 1) % 10);

        foreach (['https://example.com', 'dorr://wallet/SA/123', 'dorr://wallet/SA/'.$typo, 'hello'] as $bad) {
            $this->lookup(['mode' => 'qr', 'qr' => $bad])->assertStatus(422)->assertJsonValidationErrors('qr');
        }
    }

    public function test_your_own_qr_says_it_is_you(): void
    {
        $mine = $this->fund($this->alice, 10000);

        $this->lookup(['mode' => 'qr', 'qr' => WalletNumber::qrPayload('SA', $mine->wallet_number)])
            ->assertStatus(422)->assertJsonPath('error_code', 'transfer_to_self');
    }

    public function test_wallet_number_and_masking_helpers(): void
    {
        $this->assertTrue(WalletNumber::isValid(WalletNumber::generate()));
        $this->assertSame('12345678901', WalletNumber::normalize('١٢٣ 4567-8901'));
        $this->assertSame('123 4567 8901', WalletNumber::format('12345678901'));

        $this->assertSame('س*** أ***', MaskedName::of('سارة أحمد'));
        $this->assertSame('M***', MaskedName::of('Mona'));
        $this->assertSame('J*** D***', MaskedName::of('  John   Doe '));
        $this->assertSame('***', MaskedName::of(null));
        $this->assertSame('***', MaskedName::of('   '));
    }
}
