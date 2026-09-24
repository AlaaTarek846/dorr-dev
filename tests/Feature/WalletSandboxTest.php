<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Provider\Models\Provider;
use Modules\Wallet\Database\Seeders\PaymentMethodSeeder;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Exceptions\PaymentGatewayException;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\PaymentGatewayRegistry;
use Modules\Wallet\Services\PinService;
use Tests\TestCase;

/**
 * The development/demo fake bank, plus the cross-payment reference guard that
 * protects every gateway's callback.
 */
class WalletSandboxTest extends TestCase
{
    use RefreshDatabase;

    private Provider $me;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->seed(PaymentMethodSeeder::class);
        $this->method = PaymentMethod::query()->where('code', 'sandbox_card')->firstOrFail();

        $this->me = Provider::create(['name' => 'Mona', 'email' => 'mona@example.com', 'country_id' => $saudi->id, 'status' => 'active']);
        app(PinService::class)->set($this->me, '1234');
        Sanctum::actingAs($this->me, [], 'provider_api');
    }

    private function startTopup(int $amount, string $key): PaymentTransaction
    {
        $uuid = $this->postJson('/api/provider/v1/wallet/topups', [
            'payment_method_id' => $this->method->id, 'amount_minor' => $amount,
        ], ['X-Wallet-Pin' => '1234', 'Idempotency-Key' => $key, 'X-Country' => 'SA'])->assertCreated()->json('data.uuid');

        return PaymentTransaction::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function reference(PaymentTransaction $payment): string
    {
        return $payment->gateway_reference;
    }

    private function decide(string $reference, string $result): string
    {
        return $this->post("/api/wallet/sandbox/{$reference}", ['result' => $result])->assertRedirect()->headers->get('Location');
    }

    private function balance(): int
    {
        return (int) Wallet::query()->where('owner_type', 'provider')->where('owner_id', $this->me->id)->value('withdrawable_minor');
    }

    public function test_the_seeder_offers_the_sandbox_method_in_local_and_testing(): void
    {
        $this->assertTrue($this->method->status);
        $this->assertTrue($this->method->is_global);

        $this->getJson('/api/provider/v1/wallet/payment-methods', ['X-Country' => 'SA'])
            ->assertOk()->assertJsonFragment(['code' => 'sandbox_card']);
    }

    public function test_approving_at_the_fake_bank_credits_the_wallet_through_the_normal_callback(): void
    {
        $payment = $this->startTopup(5000, 'key-00000001');
        $this->assertStringContainsString('/api/wallet/sandbox/', $payment->redirect_url);

        $this->get($payment->redirect_url)->assertOk()->assertSee('50.00');
        $location = $this->decide($this->reference($payment), 'approve');
        $this->assertStringContainsString("/api/wallet/payments/{$payment->uuid}/callback", $location);
        $this->assertSame(0, $this->balance(), 'nothing is credited by the redirect itself');

        $this->get($location)->assertOk();

        $this->assertSame(5000, $this->balance());
        $this->assertSame(PaymentTransactionStatus::Paid, $payment->fresh()->status);
    }

    public function test_declining_fails_the_payment_without_credit(): void
    {
        $payment = $this->startTopup(5000, 'key-00000002');

        $this->get($this->decide($this->reference($payment), 'decline'))->assertOk();

        $this->assertSame(0, $this->balance());
        $this->assertSame(PaymentTransactionStatus::Failed, $payment->fresh()->status);
    }

    public function test_a_forged_callback_without_the_bank_approving_moves_no_money(): void
    {
        $payment = $this->startTopup(5000, 'key-00000003');

        $this->get("/api/wallet/payments/{$payment->uuid}/callback?reference={$this->reference($payment)}&status=success&paid=true")->assertOk();

        $this->assertSame(0, $this->balance());
    }

    public function test_someone_elses_approved_reference_cannot_complete_another_payment(): void
    {
        $small = $this->startTopup(1000, 'key-00000004');
        $big = $this->startTopup(1000, 'key-00000005'); // same amount: the amount check alone can't catch this
        $this->decide($this->reference($small), 'approve');

        // Replay the *paid* payment's reference against the other payment's callback.
        $this->get("/api/wallet/payments/{$big->uuid}/callback?reference={$this->reference($small)}")->assertOk();

        $this->assertSame(0, $this->balance());
        $this->assertSame(PaymentTransactionStatus::Pending, $big->fresh()->status);
        $this->assertSame('reference_mismatch', $big->fresh()->failure_reason);

        // The payment that was really paid still completes — once.
        $this->get("/api/wallet/payments/{$small->uuid}/callback?reference={$this->reference($small)}")->assertOk();
        $this->get("/api/wallet/payments/{$small->uuid}/callback?reference={$this->reference($small)}")->assertOk();
        $this->assertSame(1000, $this->balance());
    }

    public function test_the_sandbox_disappears_when_the_config_turns_it_off(): void
    {
        $payment = $this->startTopup(1000, 'key-00000006');
        config(['wallet.sandbox_enabled' => false]);

        $this->get($payment->redirect_url)->assertNotFound();
        $this->post("/api/wallet/sandbox/{$this->reference($payment)}", ['result' => 'approve'])->assertNotFound();

        $this->expectException(PaymentGatewayException::class);
        app(PaymentGatewayRegistry::class)->driver('sandbox');
    }
}
