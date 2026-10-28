<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\Ar\AiArOrder;
use Modules\AI\Models\Ar\AiArSetting;
use Modules\AI\Services\Ar\AiArAssetService;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/** Escrow for a priced design: charge on accept, hold, deliver, confirm / dispute / auto-release, commission, refunds. */
class AiArOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Provider $provider;

    private Country $country;

    private int $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(FinancialCategorySeeder::class);
        $this->mock(\App\Services\Notifications\NotificationCenter::class, fn ($m) => $m->shouldReceive('send')->andReturnNull());
        $this->mock(PinService::class, function ($m) {
            $m->shouldReceive('has')->andReturnTrue();
            $m->shouldReceive('verify')->andReturnNull();
            $m->shouldReceive('mustChange')->andReturnFalse();
        });

        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $this->country = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id]);
        app()->instance('resolved_country', $this->country);

        $this->customer = User::query()->create(['name' => 'C', 'email' => 'c-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        $this->provider = Provider::query()->create(['name' => 'Nile', 'email' => 'p-'.uniqid().'@example.test', 'phone' => '10'.random_int(10000000, 99999999), 'country_id' => $this->country->id, 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        $this->fund(100000); // 1000.00

        $json = json_encode(['asset' => ['version' => '2.0'], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]], 'accessors' => [['count' => 8, 'min' => [0, 0, 0], 'max' => [1, 1, 1]], ['count' => 36]]]);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
        $glb = pack('V3', 0x46546C67, 2, 20 + strlen($json)).pack('V2', strlen($json), 0x4E4F534A).$json;
        $this->assetId = app(AiArAssetService::class)->create(['name_en' => 'Sofa', 'category' => 'furniture'], UploadedFile::fake()->createWithContent('s.glb', $glb), $this->customer)->id;
    }

    private function fund(int $minor): void
    {
        app(WalletService::class)->firstOrCreateWallet($this->customer, $this->country)->update(['spend_only_minor' => $minor, 'withdrawable_minor' => 0]);
    }

    private function customerBalance(): int
    {
        $w = Wallet::query()->where('owner_type', \Modules\Wallet\Support\OwnerType::aliasFor($this->customer))->where('owner_id', $this->customer->id)->first();

        return (int) $w->spend_only_minor + (int) $w->withdrawable_minor;
    }

    private function providerWithdrawable(): int
    {
        return (int) Wallet::query()->where('owner_type', \Modules\Wallet\Support\OwnerType::aliasFor($this->provider))->where('owner_id', $this->provider->id)->value('withdrawable_minor');
    }

    private function asCustomer(): void
    {
        Sanctum::actingAs($this->customer, ['*'], 'user_api');
    }

    private function asProvider(): void
    {
        Sanctum::actingAs($this->provider, ['*'], 'provider_api');
    }

    /** A design that the provider approved with a quote; returns the experience id. */
    private function approved(int $quote = 20000): int
    {
        $this->asCustomer();
        $exp = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'decor', 'title' => 'Living room'])->json('data.id');
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", ['items' => [['asset_id' => $this->assetId, 'transform' => ['position' => [0, 0, -1], 'rotation' => [0, 0, 0, 1], 'scale' => [1, 1, 1]]]]])->assertOk();
        $review = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');
        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'approve', 'quote_minor' => $quote])->assertOk();
        $this->asCustomer();

        return $exp;
    }

    private function paid(int $quote = 20000): AiArOrder
    {
        $exp = $this->approved($quote);
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept-and-pay", [], ['X-Wallet-Pin' => '4821'])->assertStatus(201);

        return AiArOrder::latest("id")->firstOrFail();
    }

    public function test_paying_charges_the_wallet_snapshots_the_commission_and_locks_the_design(): void
    {
        $exp = $this->approved(20000); // 200.00
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept")->assertStatus(409);

        $res = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept-and-pay", [], ['X-Wallet-Pin' => '4821'])->assertStatus(201)->json('data');

        $this->assertSame(100000 - 20000, $this->customerBalance());
        $this->assertSame(0, $this->providerWithdrawable(), 'the provider is not paid before delivery');
        $this->assertEquals([20000, 10.0, 2000, 18000, 'paid'], [$res['amount_minor'], $res['commission_percent'], $res['commission_minor'], $res['provider_net_minor'], $res['status']]);
        $this->getJson("/api/user/v1/ai-ar/experiences/$exp")->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept-and-pay", [], ['X-Wallet-Pin' => '4821'])->assertStatus(409);
        $this->assertSame(1, AiArOrder::count());

        AiArSetting::current()->update(['commission_percent' => 25]);
        $this->assertSame(2000, AiArOrder::first()->commission_minor, 'changing the setting never touches an open order');
    }

    public function test_not_enough_balance_charges_nothing_and_keeps_the_design_approved(): void
    {
        $this->fund(5000);
        $exp = $this->approved(20000);

        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept-and-pay", [], ['X-Wallet-Pin' => '4821'])->assertStatus(402);

        $this->assertSame(5000, $this->customerBalance());
        $this->assertSame(0, AiArOrder::count());
        $this->getJson("/api/user/v1/ai-ar/experiences/$exp")->assertJsonPath('data.status', 'approved');
    }

    public function test_delivery_then_confirmation_pays_the_provider_minus_commission_once(): void
    {
        $order = $this->paid(20000);

        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/confirm")->assertStatus(409); // nothing delivered yet

        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$order->id}/deliver", ['note' => 'Done'])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame(0, $this->providerWithdrawable());

        $this->asCustomer();
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/confirm")->assertOk()->assertJsonPath('data.status', 'released');
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/confirm")->assertStatus(409);

        $this->assertSame(18000, $this->providerWithdrawable());
        $entry = FinancialEntry::query()->firstOrFail();
        $this->assertSame([2000, 'income'], [$entry->amount_minor, $entry->type->value]);
    }

    public function test_unanswered_delivery_is_released_automatically_after_the_window(): void
    {
        $order = $this->paid(20000);
        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$order->id}/deliver")->assertOk();

        $this->artisan('ai:release-ar-orders')->expectsOutput('released=0')->assertSuccessful();
        $this->assertSame(AiArOrder::DELIVERED, $order->refresh()->status);

        $order->update(['release_at' => now()->subMinute()]);
        $this->artisan('ai:release-ar-orders')->expectsOutput('released=1')->assertSuccessful();
        $this->artisan('ai:release-ar-orders')->expectsOutput('released=0')->assertSuccessful();

        $this->assertSame(AiArOrder::RELEASED, $order->refresh()->status);
        $this->assertSame(18000, $this->providerWithdrawable());
    }

    public function test_a_dispute_freezes_the_money_until_an_admin_decides(): void
    {
        $order = $this->paid(20000);
        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$order->id}/deliver")->assertOk();

        $this->asCustomer();
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/dispute", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/dispute", ['reason' => 'Wrong sofa delivered'])->assertOk()->assertJsonPath('data.status', 'disputed');

        $order->update(['release_at' => now()->subDay()]);
        $this->artisan('ai:release-ar-orders')->expectsOutput('released=0')->assertSuccessful();
        $this->assertSame(0, $this->providerWithdrawable());

        Sanctum::actingAs(Admin::query()->create(['name' => 'A', 'email' => 'ad-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]), ['*'], 'admin_api');
        $this->getJson('/api/admin/v1/ai-ar/orders?status=disputed')->assertJsonCount(1, 'data');
        $this->postJson("/api/admin/v1/ai-ar/orders/{$order->id}/resolve", ['resolution' => 'refund'])->assertStatus(422); // a reason is required
        $this->postJson("/api/admin/v1/ai-ar/orders/{$order->id}/resolve", ['resolution' => 'refund', 'note' => 'Customer is right'])->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->postJson("/api/admin/v1/ai-ar/orders/{$order->id}/resolve", ['resolution' => 'release', 'note' => 'again'])->assertStatus(409);

        $this->assertSame(100000, $this->customerBalance());
        $this->assertSame(0, $this->providerWithdrawable());
        $this->assertSame(0, FinancialEntry::count(), 'no commission on a refunded order');
    }

    public function test_admin_can_decide_for_the_provider_instead(): void
    {
        $order = $this->paid(20000);
        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$order->id}/deliver")->assertOk();
        $this->asCustomer();
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/dispute", ['reason' => 'Not what I wanted'])->assertOk();

        Sanctum::actingAs(Admin::query()->create(['name' => 'A', 'email' => 'ad-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]), ['*'], 'admin_api');
        $this->postJson("/api/admin/v1/ai-ar/orders/{$order->id}/resolve", ['resolution' => 'release', 'note' => 'As approved'])->assertOk()->assertJsonPath('data.status', 'released');

        $this->assertSame(18000, $this->providerWithdrawable());
        $this->assertSame(80000, $this->customerBalance());
    }

    public function test_cancelling_or_declining_before_delivery_refunds_in_full_and_unlocks_the_design(): void
    {
        $order = $this->paid(20000);
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/cancel")->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertSame(100000, $this->customerBalance());
        $this->getJson('/api/user/v1/ai-ar/experiences/'.$order->experience_id)->assertJsonPath('data.status', 'active');
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/cancel")->assertStatus(409);

        $second = $this->approved(10000);
        $this->postJson("/api/user/v1/ai-ar/experiences/$second/accept-and-pay", [], ['X-Wallet-Pin' => '4821'])->assertStatus(201);
        $job = AiArOrder::latest('id')->first();
        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$job->id}/decline")->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertSame(100000, $this->customerBalance());

        $third = $this->paid(10000);
        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$third->id}/deliver")->assertOk();
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$third->id}/decline")->assertStatus(409);
        $this->asCustomer();
        $this->postJson("/api/user/v1/ai-ar/orders/{$third->id}/cancel")->assertStatus(409);
    }

    public function test_orders_are_private_to_their_customer_and_provider(): void
    {
        $order = $this->paid(20000);

        $other = User::query()->create(['name' => 'O', 'email' => 'o-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        Sanctum::actingAs($other, ['*'], 'user_api');
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/confirm")->assertStatus(404);
        $this->postJson("/api/user/v1/ai-ar/orders/{$order->id}/cancel")->assertStatus(404);

        $stranger = Provider::query()->create(['name' => 'Other', 'email' => 'x-'.uniqid().'@example.test', 'phone' => '10'.random_int(10000000, 99999999), 'country_id' => $this->country->id, 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        Sanctum::actingAs($stranger, ['*'], 'provider_api');
        $this->getJson('/api/provider/v1/ai-ar/jobs')->assertJsonCount(0, 'data');
        $this->postJson("/api/provider/v1/ai-ar/jobs/{$order->id}/deliver")->assertStatus(404);

        $this->asProvider();
        $this->getJson('/api/provider/v1/ai-ar/jobs')->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider_net_minor', 18000);
    }

    public function test_free_design_without_quote_is_still_accepted_without_payment(): void
    {
        $this->asCustomer();
        $exp = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'decor', 'title' => 'Free'])->json('data.id');
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", ['items' => [['asset_id' => $this->assetId, 'transform' => ['position' => [0, 0, -1], 'rotation' => [0, 0, 0, 1], 'scale' => [1, 1, 1]]]]])->assertOk();
        $review = $this->postJson("/api/user/v1/ai-ar/experiences/$exp/submit", ['provider_id' => $this->provider->id])->json('data.id');
        $this->asProvider();
        $this->postJson("/api/provider/v1/ai-ar/inbox/$review/decide", ['decision' => 'approve'])->assertOk();
        $this->asCustomer();

        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept-and-pay", [], ['X-Wallet-Pin' => '4821'])->assertStatus(409);
        $this->postJson("/api/user/v1/ai-ar/experiences/$exp/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(100000, $this->customerBalance());
    }
}
