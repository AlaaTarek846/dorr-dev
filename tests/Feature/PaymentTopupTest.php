<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Provider\Models\Provider;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\FinancialCategoryNotFoundException;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\PaymentGatewayLog;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletFeeRule;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Models\WebhookInboxEntry;
use Modules\Wallet\Enums\PaymentMethodType;
use Modules\Wallet\Services\FeeService;
use Modules\Wallet\Services\PinService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 6 - online top-up end to end, with the gateway faked at the HTTP layer.
 */
class PaymentTopupTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Provider $provider;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
        Language::create([
            'code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true,
            'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id,
        ]);

        $this->seed(FinancialCategorySeeder::class);

        $this->method = $this->makeMethod('mf', 'myfatoorah', ['api_url' => 'https://mf.test', 'api_key' => 'KEY']);
        $this->provider = Provider::create(['name' => 'Prov', 'email' => 'prov@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        app(PinService::class)->set($this->provider, '1234');

        Sanctum::actingAs($this->provider, [], 'provider_api');
    }

    private function makeMethod(string $code, string $gateway, array $credentials): PaymentMethod
    {
        return PaymentMethod::create([
            'code' => $code, 'gateway' => $gateway, 'type' => PaymentMethodType::Online,
            'is_global' => true, 'supports_topup' => true, 'status' => true, 'credentials' => $credentials,
        ]);
    }

    private function rule(string $percent, array $overrides = []): WalletFeeRule
    {
        return WalletFeeRule::create(array_merge(['operation' => 'topup', 'percent' => $percent, 'status' => true], $overrides));
    }

    private function fakeMyFatoorah(bool $paid = true, float $value = 100.00): void
    {
        // Http::fake() stubs are first-match-wins, so a re-fake must start clean.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'mf.test/v2/SendPayment' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceURL' => 'https://pay.test/inv/1', 'InvoiceId' => 555]]),
            'mf.test/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => [
                'InvoiceId' => 555,
                'InvoiceValue' => $value,
                'InvoiceTransactions' => [['TransactionStatus' => $paid ? 'Succss' : 'Failed']],
            ]]),
        ]);
    }

    private function initiate(int $amountMinor = 10000, string $key = 'key-12345678', array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/provider/v1/wallet/topups', [
            'payment_method_id' => $this->method->id,
            'amount_minor' => $amountMinor,
        ], array_merge(['X-Wallet-Pin' => '1234', 'Idempotency-Key' => $key, 'X-Country' => 'SA'], $headers));
    }

    private function paymentAfterInitiate(int $amountMinor = 10000): PaymentTransaction
    {
        $this->fakeMyFatoorah();
        $uuid = $this->initiate($amountMinor)->assertCreated()->json('data.uuid');

        return PaymentTransaction::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function gatewayCallback(PaymentTransaction $payment): \Illuminate\Testing\TestResponse
    {
        return $this->get("/api/wallet/payments/{$payment->uuid}/callback?paymentId=PAY-1");
    }

    private function wallet(): ?Wallet
    {
        return Wallet::query()->where('owner_type', 'provider')->where('owner_id', $this->provider->id)->first();
    }

    // --------------------------------------------------------------- balance

    public function test_the_balance_endpoint_is_zero_before_the_first_topup_then_reflects_it(): void
    {
        $this->getJson('/api/provider/v1/wallet', ['X-Country' => 'SA'])
            ->assertOk()
            ->assertJsonPath('data.currency_code', 'SAR')
            ->assertJsonPath('data.total_minor', 0);

        $this->rule('-10.0000', ['max_amount_minor' => 5000]);
        $this->gatewayCallback($this->paymentAfterInitiate());

        $this->getJson('/api/provider/v1/wallet', ['X-Country' => 'SA'])
            ->assertOk()
            ->assertJsonPath('data.withdrawable_minor', 10000)
            ->assertJsonPath('data.spend_only_minor', 1000)
            ->assertJsonPath('data.total_minor', 11000);
    }

    // ------------------------------------------------------------------ fees

    public function test_a_listed_gateway_without_credentials_is_coming_soon_and_cannot_be_charged(): void
    {
        Http::fake();
        $arb = $this->makeMethod('arb', 'arb', []);
        $arb->update(['credentials' => null]);
        $this->method = $arb;

        $this->initiate()->assertStatus(422)->assertJsonPath('error_code', 'payment_method_coming_soon');

        Http::assertNothingSent();
        $this->assertSame(0, PaymentTransaction::query()->count(), 'nothing was even started');
    }

    public function test_a_fee_is_taken_from_the_credited_amount(): void
    {
        $rule = $this->rule('1.0000');

        $quote = app(FeeService::class)->quote(10000, $rule);

        $this->assertSame(100, $quote->feeMinor);
        $this->assertSame(9900, $quote->netWithdrawableMinor());
        $this->assertSame(0, $quote->bonusMinor);
    }

    public function test_a_negative_percent_is_a_bonus_on_top_capped_by_max_amount(): void
    {
        $uncapped = app(FeeService::class)->quote(10000, $this->rule('-10.0000', ['max_amount_minor' => 5000]));
        $capped = app(FeeService::class)->quote(10000, $this->rule('-10.0000', ['max_amount_minor' => 300]));

        $this->assertSame(1000, $uncapped->bonusMinor);
        $this->assertSame(10000, $uncapped->netWithdrawableMinor()); // principal untouched
        $this->assertSame(300, $capped->bonusMinor);
    }

    public function test_no_rule_means_no_fee_and_no_bonus(): void
    {
        $quote = app(FeeService::class)->quote(10000, null);

        $this->assertSame(0, $quote->feeMinor + $quote->bonusMinor);
        $this->assertSame(10000, $quote->totalCreditedMinor());
    }

    public function test_the_most_specific_rule_wins_and_rules_do_not_stack(): void
    {
        $general = $this->rule('1.0000');
        $forMethod = $this->rule('2.0000', ['payment_method_id' => $this->method->id]);
        $forCountry = $this->rule('3.0000', ['country_id' => $this->saudi->id, 'payment_method_id' => $this->method->id]);

        $picked = app(FeeService::class)->resolveRule($this->saudi, $this->method, 'provider', $this->provider->id);

        $this->assertSame($forCountry->id, $picked->id);
        $this->assertNotEquals($general->id, $picked->id);
        $this->assertNotEquals($forMethod->id, $picked->id);
    }

    public function test_an_exhausted_bonus_budget_rule_is_skipped(): void
    {
        $this->rule('-10.0000', ['max_amount_minor' => 500, 'budget_total_minor' => 1000, 'budget_used_minor' => 1000]);

        $this->assertNull(app(FeeService::class)->resolveRule($this->saudi, $this->method, 'provider', $this->provider->id));
    }

    public function test_the_quote_endpoint_shows_the_breakdown(): void
    {
        $this->rule('1.0000');

        $this->postJson('/api/provider/v1/wallet/topups/quote', [
            'payment_method_id' => $this->method->id, 'amount_minor' => 10000,
        ], ['X-Country' => 'SA'])
            ->assertOk()
            ->assertJsonPath('data.fee_minor', 100)
            ->assertJsonPath('data.withdrawable_minor', 9900)
            ->assertJsonPath('data.spend_only_minor', 0);
    }

    // -------------------------------------------------------------- initiate

    public function test_initiate_creates_a_pending_payment_with_the_gateway_link(): void
    {
        $this->fakeMyFatoorah();

        $this->initiate()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.redirect_url', 'https://pay.test/inv/1')
            ->assertJsonPath('data.requires_otp', false);

        $payment = PaymentTransaction::query()->firstOrFail();
        $this->assertSame(10000, $payment->requested_amount_minor);
        $this->assertNull($this->wallet(), 'no wallet/balance change before the gateway confirms');
        $this->assertSame(1, PaymentGatewayLog::query()->where('event', 'initiate')->count());
    }

    public function test_the_same_idempotency_key_returns_the_same_payment_without_calling_the_gateway_again(): void
    {
        $this->fakeMyFatoorah();

        $first = $this->initiate()->assertCreated()->json('data.uuid');
        $second = $this->initiate()->assertCreated()->json('data.uuid');

        $this->assertSame($first, $second);
        $this->assertSame(1, PaymentTransaction::query()->count());
        Http::assertSentCount(1);
    }

    public function test_the_same_key_with_a_different_amount_is_a_409(): void
    {
        $this->fakeMyFatoorah();

        $this->initiate(10000)->assertCreated();

        $this->initiate(20000)->assertStatus(409)->assertJsonPath('error_code', 'idempotency_conflict');
    }

    public function test_initiate_requires_the_pin(): void
    {
        $this->fakeMyFatoorah();

        $this->initiate(headers: ['X-Wallet-Pin' => ''])->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');
        $this->initiate(headers: ['X-Wallet-Pin' => '9999'])->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');

        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    public function test_the_pin_can_be_verified_on_its_own_to_unlock_the_wallet_screens(): void
    {
        $url = '/api/provider/v1/wallet/pin/verify';

        $this->postJson($url, [], ['X-Country' => 'SA'])->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');
        $this->postJson($url, [], ['X-Country' => 'SA', 'X-Wallet-Pin' => '0000'])->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');
        $this->postJson($url, [], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk()->assertJsonPath('data.verified', true);
    }

    public function test_repeated_wrong_pins_on_verify_lock_the_owner_out(): void
    {
        $url = '/api/provider/v1/wallet/pin/verify';

        foreach (range(1, 5) as $_) {
            $this->postJson($url, [], ['X-Country' => 'SA', 'X-Wallet-Pin' => '9999']);
        }

        // Even the right PIN is refused while locked.
        $this->postJson($url, [], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_locked');
    }

    public function test_a_first_time_owner_without_a_pin_is_told_to_create_one(): void
    {
        $other = Provider::create(['name' => 'NoPin', 'email' => 'nopin@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        Sanctum::actingAs($other, [], 'provider_api');

        $this->initiate()->assertStatus(428)->assertJsonPath('error_code', 'wallet_pin_not_set');
    }

    public function test_pin_creation_needs_a_confirmation_and_cannot_be_repeated(): void
    {
        $other = Provider::create(['name' => 'NoPin', 'email' => 'nopin@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        Sanctum::actingAs($other, [], 'provider_api');

        $this->postJson('/api/provider/v1/wallet/pin', ['pin' => '4321', 'pin_confirmation' => '0000'])->assertStatus(422);
        $this->postJson('/api/provider/v1/wallet/pin', ['pin' => '4321', 'pin_confirmation' => '4321'])->assertCreated();
        $this->postJson('/api/provider/v1/wallet/pin', ['pin' => '1111', 'pin_confirmation' => '1111'])->assertStatus(409);
        $this->putJson('/api/provider/v1/wallet/pin', ['current_pin' => '4321', 'pin' => '1111', 'pin_confirmation' => '1111'])->assertOk();
    }

    public function test_an_amount_outside_the_country_limits_is_rejected(): void
    {
        $this->method->countryLinks()->create(['country_id' => $this->saudi->id, 'min_amount_minor' => 1000, 'max_amount_minor' => 50000]);
        $this->fakeMyFatoorah();

        $this->initiate(500)->assertStatus(422)->assertJsonPath('error_code', 'amount_out_of_range');
        $this->initiate(60000, 'key-87654321')->assertStatus(422)->assertJsonPath('error_code', 'amount_out_of_range');
    }

    public function test_a_gateway_rejection_marks_the_payment_failed(): void
    {
        Http::fake(['mf.test/v2/SendPayment' => Http::response(['IsSuccess' => false, 'Message' => 'Bad request'])]);

        $this->initiate()->assertStatus(422)->assertJsonPath('error_code', 'gateway_rejected');

        $this->assertSame(PaymentTransactionStatus::Failed, PaymentTransaction::query()->firstOrFail()->status);
    }

    public function test_an_unreachable_gateway_is_a_502_and_fails_the_payment(): void
    {
        Http::fake(['mf.test/*' => Http::failedConnection()]);

        $this->initiate()->assertStatus(502)->assertJsonPath('error_code', 'gateway_unreachable');
        $this->assertSame(PaymentTransactionStatus::Failed, PaymentTransaction::query()->firstOrFail()->status);
    }

    public function test_another_owner_cannot_read_my_payment(): void
    {
        $payment = $this->paymentAfterInitiate();

        $other = Provider::create(['name' => 'Other', 'email' => 'other@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        Sanctum::actingAs($other, [], 'provider_api');

        $this->getJson("/api/provider/v1/wallet/topups/{$payment->uuid}", ['X-Country' => 'SA'])->assertNotFound();
    }

    // -------------------------------------------------------------- callback

    public function test_a_verified_callback_credits_the_wallet_once(): void
    {
        $payment = $this->paymentAfterInitiate();

        $this->gatewayCallback($payment)->assertOk();
        $this->gatewayCallback($payment)->assertOk(); // browser refresh / double redirect

        $wallet = $this->wallet();
        $this->assertSame(10000, $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->spend_only_minor);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::Topup)->count());
        $this->assertSame(PaymentTransactionStatus::Paid, $payment->fresh()->status);
        $this->assertSame($wallet->id, $payment->fresh()->wallet_id);
        $this->assertSame('processed', WebhookInboxEntry::query()->firstOrFail()->status->value);
    }

    public function test_a_fee_is_debited_and_recorded_as_system_income(): void
    {
        $this->rule('1.0000');
        $payment = $this->paymentAfterInitiate();

        $this->gatewayCallback($payment);

        $this->assertSame(9900, $this->wallet()->withdrawable_minor);
        $entry = FinancialEntry::query()->firstOrFail();
        $this->assertSame(100, $entry->amount_minor);
        $this->assertSame('income', $entry->type->value);
    }

    public function test_a_bonus_is_credited_spend_only_and_counts_against_the_rule_budget(): void
    {
        $rule = $this->rule('-10.0000', ['max_amount_minor' => 5000, 'budget_total_minor' => 100000]);
        $payment = $this->paymentAfterInitiate();

        $this->gatewayCallback($payment);

        $wallet = $this->wallet();
        $this->assertSame(10000, $wallet->withdrawable_minor);
        $this->assertSame(1000, $wallet->spend_only_minor);
        $this->assertSame(1000, $rule->fresh()->budget_used_minor);
        $this->assertSame('expense', FinancialEntry::query()->firstOrFail()->type->value);
    }

    public function test_the_quote_snapshot_wins_over_a_rule_that_changed_afterwards(): void
    {
        $rule = $this->rule('1.0000');
        $payment = $this->paymentAfterInitiate();

        $rule->update(['percent' => '50.0000']);
        $this->gatewayCallback($payment);

        $this->assertSame(9900, $this->wallet()->withdrawable_minor);
    }

    public function test_a_callback_the_gateway_does_not_confirm_fails_the_payment_and_credits_nothing(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->fakeMyFatoorah(paid: false);

        $this->gatewayCallback($payment)->assertOk();

        $this->assertSame(PaymentTransactionStatus::Failed, $payment->fresh()->status);
        $this->assertNull($this->wallet());
    }

    public function test_a_paid_topup_tells_the_owner_once_and_a_duplicate_callback_does_not_repeat_it(): void
    {
        $this->rule('-10.0000', ['max_amount_minor' => 5000, 'budget_total_minor' => 100000]);
        $payment = $this->paymentAfterInitiate();

        $this->gatewayCallback($payment);
        $this->gatewayCallback($payment); // the browser reloads the return page

        $notifications = $this->provider->notifications()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('wallet_topup_paid_title', $notifications[0]->data['title']);
        $this->assertSame('wallet_topup_paid_bonus_body', $notifications[0]->data['message']);
        $this->assertSame($payment->uuid, $notifications[0]->data['data']['payment_uuid']);
    }

    public function test_a_failed_topup_is_announced(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->fakeMyFatoorah(paid: false);
        $this->gatewayCallback($payment);

        $this->assertSame('wallet_topup_failed_title', $this->provider->notifications()->firstOrFail()->data['title']);
    }

    public function test_a_refund_is_announced(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->gatewayCallback($payment);
        $this->admin(['online-transactions.refund', 'online-transactions.view']);
        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/refund")->assertOk();

        $titles = $this->provider->notifications()->pluck('data')->pluck('title')->all();
        $this->assertContains('wallet_topup_paid_title', $titles);
        $this->assertContains('wallet_topup_refunded_title', $titles);
    }

    public function test_a_forged_success_querystring_alone_moves_no_money(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->fakeMyFatoorah(paid: false); // the gateway itself says: not paid

        $this->get("/api/wallet/payments/{$payment->uuid}/callback?paymentId=PAY-1&status=success&Success=true")->assertOk();

        $this->assertNull($this->wallet());
    }

    public function test_a_callback_for_an_unknown_payment_is_logged_not_fatal(): void
    {
        $this->get('/api/wallet/payments/00000000-0000-0000-0000-000000000000/callback?paymentId=X')->assertOk();

        $this->assertSame(1, PaymentGatewayLog::query()->whereNull('payment_transaction_id')->where('gateway_status_reported', 'unknown_reference')->count());
    }

    public function test_the_gateway_reporting_a_different_amount_leaves_the_payment_pending_for_review(): void
    {
        $payment = $this->paymentAfterInitiate(10000);
        $this->fakeMyFatoorah(value: 1.00);

        $this->gatewayCallback($payment);

        $fresh = $payment->fresh();
        $this->assertSame(PaymentTransactionStatus::Pending, $fresh->status);
        $this->assertSame('amount_mismatch', $fresh->failure_reason);
        $this->assertNull($this->wallet());
    }

    public function test_completion_is_atomic_when_a_step_fails_midway(): void
    {
        $this->rule('1.0000');
        FinancialEntry::query()->delete();
        \Modules\Wallet\Models\FinancialCategory::query()->where('slug', 'topup_fee')->forceDelete();
        $payment = $this->paymentAfterInitiate();

        // The callback service swallows nothing about the DB transaction: the
        // fee step throws, so the credit before it must roll back too.
        $this->expectException(FinancialCategoryNotFoundException::class);

        try {
            $this->withoutExceptionHandling()->gatewayCallback($payment);
        } finally {
            $this->assertSame(0, WalletTransaction::query()->count());
            $this->assertSame(PaymentTransactionStatus::Pending, $payment->fresh()->status);
        }
    }

    public function test_credentials_and_secrets_never_reach_the_logs(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->gatewayCallback($payment);

        $dump = PaymentGatewayLog::query()->get()->toJson().$payment->fresh()->toJson();

        $this->assertStringNotContainsString('"KEY"', $dump);
    }

    // ------------------------------------------------------------------ admin

    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_admin_reconcile_completes_a_payment_whose_browser_never_came_back(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->admin(['online-transactions.reconcile', 'online-transactions.view']);

        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(10000, $this->wallet()->withdrawable_minor);
        $this->assertSame(1, PaymentGatewayLog::query()->where('event', 'manual_reconcile')->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'GetPaymentStatus') && $request['KeyType'] === 'InvoiceId');
    }

    public function test_reconcile_never_fails_a_payment_the_gateway_has_not_confirmed(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->fakeMyFatoorah(paid: false);
        $this->admin(['online-transactions.reconcile', 'online-transactions.view']);

        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/reconcile")->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_reconcile_on_a_gateway_without_a_status_endpoint_is_a_clear_422(): void
    {
        $urpay = $this->makeMethod('ur', 'urpay', []);
        $payment = PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'payment_method_id' => $urpay->id, 'owner_type' => 'provider',
            'owner_id' => $this->provider->id, 'country_id' => $this->saudi->id, 'currency_id' => $this->saudi->currency_id,
            'requested_amount_minor' => 5000, 'status' => 'pending', 'gateway_reference' => 'REQ-1',
            'idempotency_key' => 'k1', 'request_hash' => str_repeat('a', 64),
        ]);
        $this->admin(['online-transactions.reconcile']);

        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/reconcile")
            ->assertStatus(422)->assertJsonPath('error_code', 'reconcile_unsupported');
    }

    public function test_online_transaction_endpoints_require_their_permissions(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->admin(['online-transactions.view']);

        $this->getJson('/api/admin/v1/online-transactions')->assertOk();
        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/reconcile")->assertForbidden();
        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/refund")->assertForbidden();
    }

    public function test_the_detail_view_shows_the_gateway_log_history(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->gatewayCallback($payment);
        $this->admin(['online-transactions.view']);

        $response = $this->getJson("/api/admin/v1/online-transactions/{$payment->id}")->assertOk();

        $events = collect($response->json('data.logs'))->pluck('event')->all();
        $this->assertSame(['initiate', 'redirect_verify'], $events);
        $this->assertStringNotContainsString('gateway_context', $response->getContent());
    }

    public function test_the_summary_groups_by_currency_and_status(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->gatewayCallback($payment);
        $this->admin(['online-transactions.view']);

        $this->getJson('/api/admin/v1/online-transactions/summary')
            ->assertOk()
            ->assertJsonPath('data.0.currency_code', 'SAR')
            ->assertJsonPath('data.0.by_status.paid.total_minor', 10000);
    }

    // ----------------------------------------------------------------- refund

    public function test_refund_reverses_the_credit_fee_and_the_system_income(): void
    {
        $this->rule('1.0000');
        $payment = $this->paymentAfterInitiate();
        $this->gatewayCallback($payment);
        $this->admin(['online-transactions.refund', 'online-transactions.view']);

        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/refund")->assertOk()->assertJsonPath('data.status', 'refunded');

        $wallet = $this->wallet();
        $this->assertSame(0, $wallet->withdrawable_minor);
        $this->assertSame(0, FinancialEntry::query()->count(), 'the income never happened');
    }

    public function test_refund_removes_the_bonus_and_gives_the_budget_back(): void
    {
        $rule = $this->rule('-10.0000', ['max_amount_minor' => 5000, 'budget_total_minor' => 100000]);
        $payment = $this->paymentAfterInitiate();
        $this->gatewayCallback($payment);
        $this->admin(['online-transactions.refund', 'online-transactions.view']);

        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/refund")->assertOk();

        $wallet = $this->wallet();
        $this->assertSame(0, $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->spend_only_minor);
        $this->assertSame(0, $rule->fresh()->budget_used_minor);
    }

    public function test_refund_is_refused_once_part_of_the_money_was_spent(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->gatewayCallback($payment);
        app(\Modules\Wallet\Services\WalletService::class)->debit(
            $this->wallet(), 4000, WalletBucket::Withdrawable, WalletTransactionType::Withdrawal,
        );
        $this->admin(['online-transactions.refund', 'online-transactions.view']);

        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/refund")
            ->assertStatus(409)->assertJsonPath('error_code', 'refund_not_whole');

        $this->assertSame(6000, $this->wallet()->withdrawable_minor);
        $this->assertSame(PaymentTransactionStatus::Paid, $payment->fresh()->status);
    }

    public function test_only_a_paid_payment_can_be_refunded(): void
    {
        $payment = $this->paymentAfterInitiate();
        $this->admin(['online-transactions.refund', 'online-transactions.view']);

        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/refund")
            ->assertStatus(409)->assertJsonPath('error_code', 'refund_not_paid');
    }

    // ---------------------------------------------------------------- expiry

    public function test_stale_pending_payments_expire_but_can_still_be_reconciled(): void
    {
        $payment = $this->paymentAfterInitiate();
        $payment->update(['expires_at' => now()->subMinute()]);

        $this->artisan('payment:expire-stale')->assertSuccessful();

        $this->assertSame(PaymentTransactionStatus::Expired, $payment->fresh()->status);

        $this->admin(['online-transactions.reconcile', 'online-transactions.view']);
        $this->postJson("/api/admin/v1/online-transactions/{$payment->id}/reconcile")->assertOk()->assertJsonPath('data.status', 'paid');
    }

    // -------------------------------------------------------------- fee rules

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'percent' => 1, 'percent_confirmation' => 1,
            'translations' => [['locale' => 'en', 'name' => 'Rule']],
        ], $overrides);
    }

    public function test_admin_creates_a_fee_rule(): void
    {
        $this->admin(['wallet-fee-rules.create']);

        $this->postJson('/api/admin/v1/wallet-fee-rules', $this->rulePayload())
            ->assertCreated()
            ->assertJsonPath('data.kind', 'fee')
            ->assertJsonPath('data.operation', 'topup');
    }

    public function test_a_nonzero_percent_must_be_confirmed(): void
    {
        $this->admin(['wallet-fee-rules.create']);

        $this->postJson('/api/admin/v1/wallet-fee-rules', $this->rulePayload(['percent_confirmation' => 2]))->assertStatus(422);
        $this->postJson('/api/admin/v1/wallet-fee-rules', $this->rulePayload(['percent_confirmation' => null]))->assertStatus(422);
    }

    public function test_a_bonus_rule_must_have_a_cap(): void
    {
        $this->admin(['wallet-fee-rules.create']);

        $this->postJson('/api/admin/v1/wallet-fee-rules', $this->rulePayload(['percent' => -10, 'percent_confirmation' => -10]))
            ->assertStatus(422)->assertJsonValidationErrors('max_amount_minor');

        $this->postJson('/api/admin/v1/wallet-fee-rules', $this->rulePayload(['percent' => -10, 'percent_confirmation' => -10, 'max_amount_minor' => 5000]))
            ->assertCreated()->assertJsonPath('data.kind', 'bonus');
    }

    public function test_fee_rule_endpoints_require_permission(): void
    {
        $this->admin(['wallet-fee-rules.view']);

        $this->postJson('/api/admin/v1/wallet-fee-rules', $this->rulePayload())->assertForbidden();
    }
}

