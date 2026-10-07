<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * One wallet per country (docs/wallet-plan.md §7): someone with a Saudi number who is in Egypt
 * opens their Egyptian wallet by default — and their Saudi wallet is not frozen: chosen explicitly
 * (X-Country, the app's wallet switcher) it shows its own number / QR, receives, and sends to Saudi
 * numbers and wallets only. A wallet only ever pays wallets of its own country.
 */
class WalletCountryWalletsTest extends TestCase
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

        $flagSa = Flag::create(['code' => 'sa']);
        $flagEg = Flag::create(['code' => 'eg']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flagSa->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id, 'status' => true]);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => $flagEg->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP'])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flagSa->id]);
        $this->seed(FinancialCategorySeeder::class);
        WalletSetting::query()->whereIn('country_id', [$this->saudi->id, $this->egypt->id])->update(['transfers_enabled' => true]);

        $this->wallets = app(WalletService::class);
        // A Saudi number, signed in from Egypt.
        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'logged_in_country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->bob = User::create(['name' => 'Bob', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'logged_in_country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        // Where the last sign-in came from (set by LoginCountry at sign-in).
        $this->alice->forceFill(['logged_in_country_id' => $this->egypt->id])->save();
        $this->bob->forceFill(['logged_in_country_id' => $this->saudi->id])->save();
        app(PinService::class)->set($this->alice, '1234');
        app(PinService::class)->set($this->bob, '1234');

        $saWallet = $this->wallets->firstOrCreateWallet($this->alice, $this->saudi);
        $this->wallets->credit($saWallet, 50000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    public function test_in_egypt_the_egyptian_wallet_opens_and_the_saudi_one_is_listed_not_lost(): void
    {
        Sanctum::actingAs($this->alice, [], 'user_api');

        $this->getJson('/api/mobile/v1/wallet')->assertOk()
            ->assertJsonPath('data.country_code', 'EG')
            ->assertJsonPath('data.dial_code', '+20')
            ->assertJsonPath('data.currency_code', 'EGP')
            ->assertJsonPath('data.other_wallets.0.country_code', 'SA')
            ->assertJsonPath('data.other_wallets.0.total_minor', 50000);

        // Chosen explicitly: the Saudi wallet, its number and QR, in riyals.
        $sa = $this->getJson('/api/mobile/v1/wallet', ['X-Country' => 'SA'])->assertOk()
            ->assertJsonPath('data.country_code', 'SA')
            ->assertJsonPath('data.dial_code', '+966')
            ->assertJsonPath('data.total_minor', 50000);
        $this->assertSame(Wallet::query()->where('owner_id', $this->alice->id)->where('country_id', $this->saudi->id)->value('wallet_number'), $sa->json('data.wallet_number'));
    }

    public function test_from_egypt_the_saudi_wallet_sends_to_saudi_numbers_only(): void
    {
        Sanctum::actingAs($this->alice, [], 'user_api');

        // The Egyptian wallet (default here) takes Egyptian numbers: a Saudi one is not even a valid number.
        $this->postJson('/api/mobile/v1/wallet/transfers/lookup', ['mode' => 'phone', 'phone' => '500000002'])->assertStatus(422);

        // The Saudi wallet, chosen: Bob's Saudi number works, and the money leaves the Saudi wallet.
        $token = $this->postJson('/api/mobile/v1/wallet/transfers/lookup', ['mode' => 'phone', 'phone' => '500000002'], ['X-Country' => 'SA'])
            ->assertOk()->json('data.recipient_token');
        $this->postJson('/api/mobile/v1/wallet/transfers', ['recipient_token' => $token, 'amount_minor' => 10000], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'key-sa-0001'])->assertSuccessful();

        $this->assertSame(40000, Wallet::query()->where('owner_id', $this->alice->id)->where('country_id', $this->saudi->id)->first()->totalBalanceMinor());
        $this->assertSame(10000, Wallet::query()->where('owner_id', $this->bob->id)->where('country_id', $this->saudi->id)->first()->totalBalanceMinor());

        // A token from the Saudi wallet can't be spent from the Egyptian one.
        $token = $this->postJson('/api/mobile/v1/wallet/transfers/lookup', ['mode' => 'phone', 'phone' => '500000002'], ['X-Country' => 'SA'])->json('data.recipient_token');
        $this->postJson('/api/mobile/v1/wallet/transfers', ['recipient_token' => $token, 'amount_minor' => 100], ['X-Country' => 'EG', 'X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'key-eg-0001'])->assertStatus(422);
    }

    public function test_someone_in_saudi_can_send_to_my_saudi_wallet_while_i_am_in_egypt(): void
    {
        $number = Wallet::query()->where('owner_id', $this->alice->id)->where('country_id', $this->saudi->id)->value('wallet_number');
        $this->wallets->credit($this->wallets->firstOrCreateWallet($this->bob, $this->saudi), 20000, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        Sanctum::actingAs($this->bob, [], 'user_api');
        $token = $this->postJson('/api/mobile/v1/wallet/transfers/lookup', ['mode' => 'wallet', 'wallet_number' => $number])->assertOk()->json('data.recipient_token');
        $this->postJson('/api/mobile/v1/wallet/transfers', ['recipient_token' => $token, 'amount_minor' => 5000], ['X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'key-bob-0001'])->assertSuccessful();

        $this->assertSame(55000, Wallet::query()->where('owner_id', $this->alice->id)->where('country_id', $this->saudi->id)->first()->totalBalanceMinor());
    }
}
