<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Driver\Models\Driver;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\PaymentMethodSeeder;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\PinService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-005: a freelancer has their own wallet like a rider's — balance, statement, PIN and topping up
 * (to pay for a plan later) and withdrawing the earnings on `freelance_api`; no transfers yet.
 */
class FreelancerWalletTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Freelancer $me;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->seed(PaymentMethodSeeder::class);
        $this->method = PaymentMethod::query()->where('code', 'sandbox_card')->firstOrFail();

        $this->me = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000077', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        app(PinService::class)->set($this->me, '1234');
        Sanctum::actingAs($this->me, [], 'freelance_api');
    }

    private function balance(): int
    {
        return (int) Wallet::query()->where('owner_type', 'freelancer')->where('owner_id', $this->me->id)->value('withdrawable_minor');
    }

    public function test_the_owner_alias_and_the_empty_wallet(): void
    {
        $this->assertSame(0, $this->me->wallets()->count());

        $this->getJson('/api/freelance/v1/wallet', ['X-Country' => 'SA'])->assertOk()->assertJsonPath('data.country_code', 'SA');
        $this->getJson('/api/freelance/v1/wallet/transactions', ['X-Country' => 'SA'])->assertOk();

        $this->assertSame(0, $this->balance());
    }

    public function test_a_freelancer_tops_up_through_the_gateway_and_the_wallet_is_credited(): void
    {
        $uuid = $this->postJson('/api/freelance/v1/wallet/topups', [
            'payment_method_id' => $this->method->id, 'amount_minor' => 5000,
        ], ['X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'fl-topup-1', 'X-Country' => 'SA'])->assertCreated()->json('data.uuid');
        $payment = PaymentTransaction::query()->where('uuid', $uuid)->firstOrFail();

        $location = $this->post("/api/wallet/sandbox/{$payment->gateway_reference}", ['result' => 'approve'])->assertRedirect()->headers->get('Location');
        $this->assertSame(0, $this->balance(), 'nothing is credited by the redirect itself');
        $this->get($location)->assertOk();

        $this->assertSame(5000, $this->balance());
        $this->assertSame(PaymentTransactionStatus::Paid, $payment->fresh()->status);
        $this->getJson('/api/freelance/v1/wallet', ['X-Country' => 'SA'])->assertOk()->assertJsonPath('data.withdrawable_minor', 5000);
        $this->getJson('/api/freelance/v1/wallet/transactions', ['X-Country' => 'SA'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/freelance/v1/wallet/payment-methods', ['X-Country' => 'SA'])->assertOk();
    }

    public function test_topping_up_needs_the_pin(): void
    {
        $this->postJson('/api/freelance/v1/wallet/topups', ['payment_method_id' => $this->method->id, 'amount_minor' => 5000], ['Idempotency-Key' => 'fl-topup-2', 'X-Country' => 'SA'])
            ->assertStatus(422); // PIN required
        $this->postJson('/api/freelance/v1/wallet/topups', ['payment_method_id' => $this->method->id, 'amount_minor' => 5000], ['X-Wallet-Pin' => '9999', 'Idempotency-Key' => 'fl-topup-3', 'X-Country' => 'SA'])
            ->assertStatus(422); // wrong PIN
        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    public function test_a_freelancer_withdraws_their_earnings_through_a_request_an_admin_approves(): void
    {
        // The owner opened withdrawing to freelancers (2026-10-20): the captain's routes, on `freelance_api`.
        app(\Modules\Wallet\Services\WalletService::class)->credit(
            app(\Modules\Wallet\Services\WalletService::class)->firstOrCreateWallet($this->me, $this->saudi),
            10000, \Modules\Wallet\Enums\WalletBucket::Withdrawable, \Modules\Wallet\Enums\WalletTransactionType::Topup,
        );
        $headers = ['X-Wallet-Pin' => '1234', 'X-Country' => 'SA'];
        $iban = 'SA0380000000608010167519';

        // Adding the destination needs the PIN; the list answers it masked.
        $this->postJson('/api/freelance/v1/wallet/withdrawal-methods', ['type' => 'bank', 'data' => ['bank_name' => 'Al Rajhi', 'account_holder' => 'Sara', 'iban' => $iban]], ['X-Country' => 'SA'])->assertStatus(422);
        $method = $this->postJson('/api/freelance/v1/wallet/withdrawal-methods', ['type' => 'bank', 'label' => 'Main', 'data' => ['bank_name' => 'Al Rajhi', 'account_holder' => 'Sara', 'iban' => $iban]], $headers)->assertCreated()->json('data.id');
        $this->getJson('/api/freelance/v1/wallet/withdrawal-methods', ['X-Country' => 'SA'])->assertOk()->assertJsonPath('data.0.display', 'Al Rajhi ••7519');

        // The request holds the money without moving it.
        $this->postJson('/api/freelance/v1/wallet/withdrawals', ['withdrawal_method_id' => $method, 'amount_minor' => 6000], ['X-Country' => 'SA', 'Idempotency-Key' => 'fl-key-12345678'])->assertStatus(422);
        $this->postJson('/api/freelance/v1/wallet/withdrawals', ['withdrawal_method_id' => $method, 'amount_minor' => 6000], $headers + ['Idempotency-Key' => 'fl-key-12345678'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.method.display', 'Al Rajhi ••7519');
        $wallet = Wallet::query()->where('owner_type', 'freelancer')->where('owner_id', $this->me->id)->firstOrFail();
        $this->assertSame(10000, $wallet->withdrawable_minor);
        $this->assertSame(6000, $wallet->held_withdrawable_minor);

        $this->getJson('/api/freelance/v1/wallet/withdrawals', ['X-Country' => 'SA'])->assertOk()->assertJsonCount(1, 'data');

        // An admin approves it with the transfer receipt: the money leaves, and the freelancer is told.
        $request = \Modules\Wallet\Models\WithdrawalRequest::query()->firstOrFail();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        Permission::findOrCreate('withdrawal-requests.approve', 'admin_api');
        Permission::findOrCreate('withdrawal-requests.view', 'admin_api');
        $admin->givePermissionTo(['withdrawal-requests.approve', 'withdrawal-requests.view']);
        Sanctum::actingAs($admin, [], 'admin_api');
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->post("/api/admin/v1/withdrawal-requests/{$request->id}/approve", ['receipt' => \Illuminate\Http\UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n%%EOF")], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(4000, $wallet->fresh()->withdrawable_minor);
        $this->assertContains('wallet_withdrawal_paid_title', $this->me->notifications()->pluck('data')->pluck('title')->all());
    }

    public function test_a_freelancer_cannot_touch_anothers_withdrawal_method_and_transfers_stay_closed(): void
    {
        $other = Freelancer::query()->create(['name' => 'Omar', 'phone' => '+966500000088', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $theirs = \Modules\Wallet\Models\WithdrawalMethod::create(['owner_type' => 'freelancer', 'owner_id' => $other->id, 'type' => 'bank', 'data' => ['bank_name' => 'X', 'account_holder' => 'Omar', 'iban' => 'SA0380000000608010167519'], 'is_favorite' => true]);

        $this->deleteJson("/api/freelance/v1/wallet/withdrawal-methods/{$theirs->id}", [], ['X-Country' => 'SA'])->assertNotFound();
        $this->getJson('/api/freelance/v1/wallet/withdrawals/999999', ['X-Country' => 'SA'])->assertNotFound();
        foreach (['wallet/beneficiaries'] as $path) {
            $this->getJson("/api/freelance/v1/{$path}")->assertNotFound();
        }
        $this->postJson('/api/freelance/v1/wallet/transfers', [])->assertStatus(404);
    }

    public function test_other_accounts_cannot_use_a_freelancers_wallet_routes(): void
    {
        $this->app['auth']->forgetGuards();
        $rider = User::query()->create(['name' => 'R', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        Sanctum::actingAs($rider, [], 'user_api');
        $this->getJson('/api/freelance/v1/wallet')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $driver = Driver::query()->create(['phone' => '+966500000002', 'country_id' => $this->saudi->id, 'phone_verified_at' => now()]);
        Sanctum::actingAs($driver, [], 'driver_api');
        $this->getJson('/api/freelance/v1/wallet')->assertUnauthorized();
    }

    public function test_the_wallet_country_follows_the_freelancers_own_country(): void
    {
        // No X-Country header: the account's country decides, like a rider's.
        $this->getJson('/api/freelance/v1/wallet')->assertOk()->assertJsonPath('data.country_code', 'SA');
    }

    public function test_the_admin_sees_finds_and_filters_freelancer_wallets(): void
    {
        $this->getJson('/api/freelance/v1/wallet', ['X-Country' => 'SA'])->assertOk(); // creates the wallet
        $this->app['auth']->forgetGuards();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        Permission::findOrCreate('wallets.view', 'admin_api');
        $admin->givePermissionTo('wallets.view');
        Sanctum::actingAs($admin, [], 'admin_api');

        $byName = $this->getJson('/api/admin/v1/wallets?search=Sara')->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('freelancer', $byName[0]['owner_type']);
        $this->assertSame('Sara', $byName[0]['owner']['name']);
        $this->getJson('/api/admin/v1/wallets?owner_type=freelancer')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/v1/wallets?owner_type=user')->assertOk()->assertJsonCount(0, 'data');
    }
}
