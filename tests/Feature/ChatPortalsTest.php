<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatCategory;
use Modules\Chat\Models\ChatPackage;
use Modules\Chat\Models\ChatPortal;
use Modules\Chat\Models\ChatSubscription;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\PaymentMethodType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * The one payment screen (Checkout) and what the chat sells on it: merchant portals' listing and
 * channels' verification (docs/remaining_chat.md ج.0 / ج.2 / ج.3).
 */
class ChatPortalsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $merchant;

    private User $visitor;

    private ChatCategory $sport;

    private ChatPackage $month;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }
        $this->seed(FinancialCategorySeeder::class);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->merchant = $make('Merchant', '+966500000001');
        $this->visitor = $make('Visitor', '+966500000002');
        app(PinService::class)->set($this->merchant, '1234');

        $this->sport = ChatCategory::query()->create(['sort_order' => 1, 'status' => true]);
        $this->sport->translations()->create(['locale' => 'en', 'name' => 'Sport']);

        $this->month = $this->package(ChatPackage::KIND_PORTAL, 'month', 1, 5000, 'Monthly');
    }

    // ------------------------------------------------------------------ portals + wallet

    public function test_a_merchant_adds_a_portal_pays_from_the_wallet_and_it_shows_on_the_page(): void
    {
        $portal = $this->addPortal('Ahmed Sports', 'Football gear');

        // Not paid yet: mine, but not on the page.
        $this->as($this->visitor);
        $this->getJson('/api/mobile/v1/chat/portals', $this->headers())->assertOk()->assertJsonCount(0, 'data');

        // 30 SAR spend-only + 100 SAR withdrawable; the 50 SAR listing takes the spend-only first.
        $wallet = $this->fund($this->merchant, withdrawable: 10000, spendOnly: 3000);

        $this->as($this->merchant);
        $this->getJson('/api/mobile/v1/chat/portals/packages', $this->headers())->assertOk()
            ->assertJsonPath('data.0.amount_minor', 5000)->assertJsonPath('data.0.currency_code', 'SAR');

        $checkout = $this->checkout('chat_portal_listing', ['portal_id' => $portal, 'package_id' => $this->month->id])
            ->assertCreated()
            ->assertJsonPath('data.amount_minor', 5000)
            ->assertJsonPath('data.wallet.available_minor', 13000)
            ->assertJsonPath('data.wallet.enough', true)
            ->json('data.id');

        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_via', 'wallet');

        $wallet->refresh();
        $this->assertSame(0, $wallet->spend_only_minor);
        $this->assertSame(8000, $wallet->withdrawable_minor);
        $this->assertSame(2, $wallet->transactions()->where('type', WalletTransactionType::ServicePayment->value)->count());
        $this->assertSame(5000, (int) FinancialEntry::query()->sum('amount_minor'));

        $row = ChatPortal::query()->where('uuid', $portal)->firstOrFail();
        $this->assertTrue($row->isListed());
        $this->assertTrue($row->listed_until->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));

        // Now on the page, under its category.
        $this->as($this->visitor);
        $this->getJson('/api/mobile/v1/chat/portals', $this->headers())->assertOk()
            ->assertJsonPath('data.0.category.name', 'Sport')
            ->assertJsonPath('data.0.portals.0.id', $portal)
            ->assertJsonPath('data.0.portals.0.name', 'Ahmed Sports');

        // Paying the same checkout twice isn't possible.
        $this->as($this->merchant);
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])
            ->assertStatus(409)->assertJsonPath('error_code', 'checkout_not_pending');
    }

    public function test_not_enough_balance_says_how_much_is_missing_and_a_renewal_stacks(): void
    {
        $portal = $this->addPortal('Shop', null);
        $this->fund($this->merchant, withdrawable: 2000);

        $this->as($this->merchant);
        $checkout = $this->checkout('chat_portal_listing', ['portal_id' => $portal, 'package_id' => $this->month->id])
            ->assertJsonPath('data.wallet.enough', false)->assertJsonPath('data.wallet.shortfall_minor', 3000)->json('data.id');

        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'checkout_insufficient_balance')->assertJsonPath('data.shortfall_minor', 3000);

        // Topped up: paid; a second month starts where the first ends.
        $this->fund($this->merchant, withdrawable: 10000);
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])->assertOk();
        $firstEnd = ChatPortal::query()->where('uuid', $portal)->value('listed_until');

        $again = $this->checkout('chat_portal_listing', ['portal_id' => $portal, 'package_id' => $this->month->id])->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$again}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])->assertOk();

        $this->assertSame(2, ChatSubscription::query()->count());
        $this->assertEquals(\Illuminate\Support\Carbon::parse($firstEnd)->addMonth()->toDateString(), ChatPortal::query()->where('uuid', $portal)->first()->listed_until->toDateString());
    }

    public function test_paying_through_a_gateway_settles_the_moment_the_gateway_confirms(): void
    {
        $portal = $this->addPortal('Shop', null);
        $method = PaymentMethod::create(['code' => 'mf', 'gateway' => 'myfatoorah', 'type' => PaymentMethodType::Online, 'is_global' => true, 'supports_topup' => true, 'status' => true, 'credentials' => ['api_url' => 'https://mf.test', 'api_key' => 'KEY']]);
        Http::fake([
            'mf.test/v2/SendPayment' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceURL' => 'https://pay.test/inv/1', 'InvoiceId' => 555]]),
            'mf.test/v2/GetPaymentStatus' => Http::response(['IsSuccess' => true, 'Data' => ['InvoiceId' => 555, 'InvoiceValue' => 50.00, 'InvoiceTransactions' => [['TransactionStatus' => 'Succss']]]]),
        ]);

        $this->as($this->merchant);
        $checkout = $this->checkout('chat_portal_listing', ['portal_id' => $portal, 'package_id' => $this->month->id])
            ->assertJsonPath('data.payment_methods.0.id', $method->id)
            ->assertJsonPath('data.payment_methods.0.charge_minor', 5000)
            ->json('data.id');

        $payment = $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'gateway', 'payment_method_id' => $method->id], $this->headers() + ['X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'pay-12345678'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.payment.redirect_url', 'https://pay.test/inv/1')
            ->json('data.payment.uuid');

        // A second hand-off while that one is open is refused.
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])
            ->assertStatus(409)->assertJsonPath('error_code', 'checkout_gateway_pending');

        // The gateway calls back (the app may be closed): paid, listed, and the wallet is back to 0.
        $this->get("/api/wallet/payments/{$payment}/callback?paymentId=PAY-1");

        $this->getJson("/api/mobile/v1/wallet/checkouts/{$checkout}", $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_via', 'gateway');
        $this->assertTrue(ChatPortal::query()->where('uuid', $portal)->first()->isListed());
        $this->assertSame(0, Wallet::query()->where('owner_id', $this->merchant->id)->first()->totalBalanceMinor());
    }

    public function test_only_my_own_portal_can_be_bought_and_a_package_needs_a_price_here(): void
    {
        $portal = $this->addPortal('Shop', null);
        $noPrice = ChatPackage::query()->create(['kind' => ChatPackage::KIND_PORTAL, 'period' => 'year', 'period_count' => 1, 'status' => true]);

        $this->as($this->visitor);
        $this->checkout('chat_portal_listing', ['portal_id' => $portal, 'package_id' => $this->month->id])->assertNotFound()->assertJsonPath('error_code', 'chat_portal_not_found');

        $this->as($this->merchant);
        $this->checkout('chat_portal_listing', ['portal_id' => $portal, 'package_id' => $noPrice->id])->assertUnprocessable()->assertJsonPath('error_code', 'chat_package_unavailable');
        $this->checkout('nothing_like_this', [])->assertUnprocessable()->assertJsonPath('error_code', 'checkout_unknown_purpose');
    }

    public function test_views_count_once_a_day_and_the_portal_outlives_its_listing(): void
    {
        $portal = $this->addPortal('Shop', null);
        ChatPortal::query()->where('uuid', $portal)->update(['listed_until' => now()->addDay()]);

        $this->as($this->visitor);
        $this->postJson("/api/mobile/v1/chat/portals/{$portal}/open", [], $this->headers())->assertOk()->assertJsonPath('data.website_url', 'https://shop.example.com');
        $this->postJson("/api/mobile/v1/chat/portals/{$portal}/open", [], $this->headers())->assertOk()->assertJsonPath('data.views_count', 1);

        // The listing ran out: off the page, still the merchant's.
        $this->travel(2)->days();
        $this->getJson('/api/mobile/v1/chat/portals', $this->headers())->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->merchant);
        $this->getJson('/api/mobile/v1/chat/portals/mine', $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $portal)->assertJsonPath('data.0.is_listed', false);
    }

    public function test_the_ai_writes_the_other_languages(): void
    {
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{"ar": {"name": "أحمد للرياضة", "description": "معدات كرة القدم"}}']]]])]);

        $this->as($this->merchant);
        $this->postJson('/api/mobile/v1/chat/portals/translate', ['from' => 'en', 'name' => 'Ahmed Sports', 'description' => 'Football gear'], $this->headers())
            ->assertOk()->assertJsonPath('data.ar.name', 'أحمد للرياضة')->assertJsonPath('data.ar.description', 'معدات كرة القدم');
    }

    // ------------------------------------------------------------------ channels

    public function test_a_channel_is_verified_while_its_package_runs_and_the_directory_groups_by_category(): void
    {
        $verification = $this->package(ChatPackage::KIND_CHANNEL_VERIFICATION, 'month', 1, 2000, 'Verified monthly');
        $this->fund($this->merchant, withdrawable: 5000);

        $this->as($this->merchant);
        $channel = $this->postJson('/api/mobile/v1/chat/channels', ['name' => 'Goals', 'is_public' => true, 'category_id' => $this->sport->id], $this->headers())->assertCreated()->json('data.id');

        $this->getJson("/api/mobile/v1/chat/channels/{$channel}/verification", $this->headers())->assertOk()
            ->assertJsonPath('data.is_verified', false)->assertJsonPath('data.packages.0.id', $verification->id);

        $checkout = $this->checkout('chat_channel_verification', ['channel_id' => $channel, 'package_id' => $verification->id])->json('data.id');
        $this->postJson("/api/mobile/v1/wallet/checkouts/{$checkout}/pay", ['method' => 'wallet'], $this->headers() + ['X-Wallet-Pin' => '1234'])->assertOk();

        // A follower sees the ✔ and the category; the directory puts it under Sport.
        $this->as($this->visitor);
        $this->postJson("/api/mobile/v1/chat/channels/{$channel}/follow", [], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/channels/{$channel}", $this->headers())->assertOk()
            ->assertJsonPath('data.is_verified', true)->assertJsonPath('data.category.name', 'Sport');
        $this->getJson('/api/mobile/v1/chat/channels/directory', $this->headers())->assertOk()
            ->assertJsonPath('data.0.category.name', 'Sport')->assertJsonPath('data.0.channels.0.id', $channel);

        // A month later the ✔ is gone (unless the admin gave it by hand).
        $this->travel(32)->days();
        $this->getJson("/api/mobile/v1/chat/channels/{$channel}", $this->headers())->assertJsonPath('data.is_verified', false);

        // Someone else can't buy it for my channel.
        $this->checkout('chat_channel_verification', ['channel_id' => $channel, 'package_id' => $verification->id])->assertNotFound();
    }

    // ------------------------------------------------------------------ admin

    public function test_the_admin_manages_categories_packages_portals_and_verifies_a_channel_by_hand(): void
    {
        $this->asAdmin(['chat-categories.create', 'chat-categories.view', 'chat-packages.create', 'chat-portals.view', 'chat-portals.update', 'chat-channels.view', 'chat-channels.update']);

        $this->post('/api/admin/v1/chat-categories', [
            'translations' => [['locale' => 'en', 'name' => 'News'], ['locale' => 'ar', 'name' => 'أخبار']],
            'icon' => UploadedFile::fake()->image('news.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.name', 'News');
        $this->assertNotNull(ChatCategory::query()->latest('id')->first()->iconUrl());

        $this->postJson('/api/admin/v1/chat-packages', [
            'kind' => 'channel_verification', 'period' => 'year', 'period_count' => 1,
            'translations' => [['locale' => 'en', 'name' => 'Verified yearly']],
            'prices' => [['country_id' => $this->saudi->id, 'amount_minor' => 20000]],
        ])->assertCreated()->assertJsonPath('data.prices.0.currency_code', 'SAR');

        // A portal switched off leaves the page and can't be bought.
        $portal = $this->addPortal('Shop', null);
        ChatPortal::query()->where('uuid', $portal)->update(['listed_until' => now()->addMonth()]);
        $this->asAdmin(['chat-portals.view', 'chat-portals.update', 'chat-channels.view', 'chat-channels.update'], 'b@example.com');
        $this->getJson('/api/admin/v1/chat-portals')->assertOk()->assertJsonPath('data.0.owner', 'Merchant');
        $this->patchJson("/api/admin/v1/chat-portals/{$portal}/status", ['status' => false])->assertOk();
        $this->as($this->visitor);
        $this->getJson('/api/mobile/v1/chat/portals', $this->headers())->assertOk()->assertJsonCount(0, 'data');

        // Verified by hand.
        $this->as($this->merchant);
        $channel = $this->postJson('/api/mobile/v1/chat/channels', ['name' => 'Daily', 'is_public' => true], $this->headers())->json('data.id');
        $this->asAdmin(['chat-channels.view', 'chat-channels.update'], 'c@example.com');
        $this->patchJson("/api/admin/v1/chat-channels/{$channel}/verify", ['verified' => true])->assertOk()->assertJsonPath('data.is_verified', true);
        $this->getJson('/api/admin/v1/chat-channels?verified=1')->assertOk()->assertJsonPath('data.0.id', $channel);

        // Without the permission: no.
        $this->asAdmin([], 'd@example.com');
        $this->getJson('/api/admin/v1/chat-packages')->assertForbidden();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<string>  $permissions
     */
    private function asAdmin(array $permissions, string $email = 'a@example.com'): void
    {
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'A', 'email' => $email, 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function package(string $kind, string $period, int $count, int $amountMinor, string $name): ChatPackage
    {
        $package = ChatPackage::query()->create(['kind' => $kind, 'period' => $period, 'period_count' => $count, 'status' => true]);
        $package->translations()->create(['locale' => 'en', 'name' => $name]);
        $package->prices()->create(['country_id' => $this->saudi->id, 'amount_minor' => $amountMinor]);

        return $package;
    }

    private function addPortal(string $name, ?string $description): string
    {
        $this->as($this->merchant);

        return $this->post('/api/mobile/v1/chat/portals', [
            'website_url' => 'https://shop.example.com',
            'category_id' => $this->sport->id,
            'translations' => [['locale' => 'en', 'name' => $name, 'description' => $description]],
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ], $this->headers() + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.is_listed', false)->json('data.id');
    }

    private function fund(User $user, int $withdrawable = 0, int $spendOnly = 0): Wallet
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->firstOrCreateWallet($user, $this->saudi);
        if ($withdrawable > 0) {
            $wallets->credit($wallet, $withdrawable, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        }
        if ($spendOnly > 0) {
            $wallets->credit($wallet, $spendOnly, WalletBucket::SpendOnly, WalletTransactionType::TransferIn);
        }

        return $wallet->refresh();
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function checkout(string $purpose, array $reference): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mobile/v1/wallet/checkouts', ['purpose' => $purpose, 'reference' => $reference], $this->headers());
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
