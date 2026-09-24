<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Notifications\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletAdminService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Notifications for wallet events: who hears about what, in which language, and that they can
 * read, count and clear them from the app.
 */
class WalletNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $alice;

    private User $bob;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        WalletSetting::query()->where('country_id', $this->saudi->id)->update(['transfers_enabled' => true]);

        $this->wallets = app(WalletService::class);
        $this->alice = $this->makeUser('Alice Smith', '+966500000001');
        $this->bob = $this->makeUser('Bob Builder', '+966500000002');
        app(PinService::class)->set($this->alice, '1234');
        app(PinService::class)->set($this->bob, '1234');

        Sanctum::actingAs($this->alice, [], 'user_api');
    }

    private function makeUser(string $name, string $phone): User
    {
        return User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function fund(User $user, int $minor): void
    {
        $wallet = $this->wallets->firstOrCreateWallet($user, $this->saudi);
        $this->wallets->credit($wallet, $minor, WalletBucket::Withdrawable, WalletTransactionType::Topup);
    }

    private function transfer(int $minor, string $key = 'key-12345678'): void
    {
        $token = $this->postJson('/api/mobile/v1/wallet/transfers/lookup', ['mode' => 'phone', 'phone' => '500000002'], ['X-Country' => 'SA'])->assertOk()->json('data.recipient_token');

        $this->postJson('/api/mobile/v1/wallet/transfers', ['recipient_token' => $token, 'amount_minor' => $minor], [
            'X-Wallet-Pin' => '1234', 'Idempotency-Key' => $key, 'X-Country' => 'SA',
        ])->assertCreated();
    }

    /** Runs what NotificationCenter deferred until after the response (the OneSignal calls). */
    private function flushDeferred(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }

    // ------------------------------------------------------------------ transfers

    public function test_a_transfer_notifies_both_sides_with_only_the_other_persons_masked_name(): void
    {
        $this->fund($this->alice, 10000);

        $this->transfer(2500);

        $sent = $this->alice->notifications()->firstOrFail();
        $received = $this->bob->notifications()->firstOrFail();

        $this->assertSame('wallet_transfer_sent_title', $sent->data['title']);
        $this->assertSame('B*** B***', $sent->data['variables']['name']);
        $this->assertSame('25.00 SAR', $sent->data['variables']['amount']);

        $this->assertSame('wallet_transfer_received_title', $received->data['title']);
        $this->assertSame('A*** S***', $received->data['variables']['name']);
        $this->assertStringNotContainsString('Alice', json_encode($received->data));
        $this->assertSame('wallet.transfer.received', $received->data['event']);
    }

    public function test_a_replayed_transfer_does_not_notify_twice(): void
    {
        $this->fund($this->alice, 10000);

        $this->transfer(2500, 'same-key-1234');
        $this->transfer(2500, 'same-key-1234');

        $this->assertSame(1, $this->alice->notifications()->count());
        $this->assertSame(1, $this->bob->notifications()->count());
    }

    public function test_a_refused_transfer_notifies_nobody(): void
    {
        // Alice has no money: the transfer is refused and nothing should be announced.
        $token = $this->postJson('/api/mobile/v1/wallet/transfers/lookup', ['mode' => 'phone', 'phone' => '500000002'], ['X-Country' => 'SA'])->json('data.recipient_token');

        $this->postJson('/api/mobile/v1/wallet/transfers', ['recipient_token' => $token, 'amount_minor' => 5000], [
            'X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'key-12345678', 'X-Country' => 'SA',
        ])->assertStatus(422);

        $this->assertSame(0, $this->alice->notifications()->count() + $this->bob->notifications()->count());
    }

    // ------------------------------------------------------------------ PIN

    public function test_creating_changing_and_locking_the_pin_are_all_announced(): void
    {
        $carol = $this->makeUser('Carol', '+966500000003');
        Sanctum::actingAs($carol, [], 'user_api');

        $this->postJson('/api/mobile/v1/wallet/pin', ['pin' => '4321', 'pin_confirmation' => '4321'], ['X-Country' => 'SA'])->assertCreated();
        $this->putJson('/api/mobile/v1/wallet/pin', ['current_pin' => '4321', 'pin' => '8765', 'pin_confirmation' => '8765'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '4321'])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/mobile/v1/wallet/pin/verify', [], ['X-Country' => 'SA', 'X-Wallet-Pin' => '0000']);
        }

        $titles = $carol->notifications()->pluck('data')->pluck('title')->all();

        $this->assertContains('wallet_pin_created_title', $titles);
        $this->assertContains('wallet_pin_changed_title', $titles);
        $this->assertContains('wallet_pin_locked_title', $titles);
    }

    public function test_no_notification_ever_contains_a_pin(): void
    {
        $carol = $this->makeUser('Carol', '+966500000003');
        Sanctum::actingAs($carol, [], 'user_api');

        $this->postJson('/api/mobile/v1/wallet/pin', ['pin' => '4321', 'pin_confirmation' => '4321'], ['X-Country' => 'SA'])->assertCreated();

        $this->assertStringNotContainsString('4321', json_encode($carol->notifications()->get()->toArray()));
    }

    // ------------------------------------------------------------------ admin action

    public function test_a_manual_adjustment_tells_the_owner_what_and_why(): void
    {
        $this->seed(FinancialCategorySeeder::class);
        $wallet = $this->wallets->firstOrCreateWallet($this->bob, $this->saudi);

        app(WalletAdminService::class)->adjust($wallet, WalletDirection::Credit, WalletBucket::SpendOnly, 5000, 'Goodwill gesture', 1);

        $n = $this->bob->notifications()->firstOrFail();

        $this->assertSame('wallet_adjusted_credit_title', $n->data['title']);
        $this->assertSame('50.00 SAR', $n->data['variables']['amount']);
        $this->assertSame('Goodwill gesture', $n->data['variables']['reason']);
    }

    // ------------------------------------------------------------------ languages

    public function test_the_same_notification_reads_in_each_viewers_own_language(): void
    {
        $this->fund($this->alice, 10000);
        $this->transfer(2500);

        $en = $this->getJson('/api/mobile/v1/notifications', ['X-Locale' => 'en'])->assertOk();
        $ar = $this->getJson('/api/mobile/v1/notifications', ['X-Locale' => 'ar'])->assertOk();

        $this->assertSame('Transfer sent', $en->json('data.0.title'));
        $this->assertSame('You sent 25.00 SAR to B*** B***.', $en->json('data.0.message'));
        $this->assertSame('تم التحويل', $ar->json('data.0.title'));
        $this->assertSame('حوّلت 25.00 SAR إلى B*** B***.', $ar->json('data.0.message'));
    }

    public function test_a_language_without_a_translation_falls_back_to_english(): void
    {
        $this->fund($this->alice, 10000);
        $this->transfer(2500);

        $this->getJson('/api/mobile/v1/notifications', ['X-Locale' => 'fr'])->assertOk();
        app()->setLocale('fr');
        $this->assertSame('Transfer sent', __('notifications.wallet_transfer_sent_title'));
    }

    public function test_real_time_messages_are_written_in_the_recipients_language_not_the_actors(): void
    {
        $this->bob->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('en'); // the person who triggered it uses English

        $message = (new GeneralNotification('wallet.transfer.received', [], '', 'wallet_transfer_received_title', 'wallet_transfer_received_body', ['amount' => '25.00 SAR', 'name' => 'A*** S***']))
            ->toBroadcast($this->bob);

        $this->assertSame('استلمت تحويلاً', $message->data['data']['payload']['title']);
    }

    public function test_the_language_an_account_uses_is_remembered(): void
    {
        $this->getJson('/api/mobile/v1/notifications/unread-count', ['X-Locale' => 'ar'])->assertOk();

        $this->assertSame('ar', $this->alice->fresh()->locale);
    }

    // ------------------------------------------------------------------ reading them

    public function test_list_count_read_and_read_all(): void
    {
        $this->fund($this->alice, 10000);
        $this->transfer(1000, 'key-000000001');
        $this->transfer(1000, 'key-000000002');

        $this->getJson('/api/mobile/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 2);

        $id = $this->getJson('/api/mobile/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->json('data.0.id');

        $this->postJson("/api/mobile/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson('/api/mobile/v1/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/mobile/v1/notifications/read-all')->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_someone_elses_notification_cannot_be_read_or_marked(): void
    {
        $this->fund($this->alice, 10000);
        $this->transfer(1000);
        $bobsId = $this->bob->notifications()->firstOrFail()->id;

        $this->postJson("/api/mobile/v1/notifications/{$bobsId}/read")->assertNotFound();
        $this->assertNull($this->bob->notifications()->firstOrFail()->read_at);
    }

    public function test_providers_have_the_same_endpoints_for_their_own_notifications(): void
    {
        $provider = Provider::create(['name' => 'Prov', 'email' => 'p@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        app(PinService::class)->set($provider, '1234');
        Sanctum::actingAs($provider, [], 'provider_api');

        $this->postJson('/api/provider/v1/wallet/pin/verify', [], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk();
        $this->putJson('/api/provider/v1/wallet/pin', ['current_pin' => '1234', 'pin' => '5678', 'pin_confirmation' => '5678'], ['X-Country' => 'SA', 'X-Wallet-Pin' => '1234'])->assertOk();

        $this->getJson('/api/provider/v1/notifications', ['X-Locale' => 'ar'])->assertOk()
            ->assertJsonPath('data.0.title', 'تم تغيير الرقم السري للمحفظة');
        $this->getJson('/api/provider/v1/notifications/unread-count')->assertJsonPath('data.count', 1);
    }

    // ------------------------------------------------------------------ push

    public function test_a_device_can_register_and_move_to_another_account(): void
    {
        $this->postJson('/api/mobile/v1/notifications/devices', ['player_id' => 'player-1', 'platform' => 'android'])->assertOk();
        $this->assertSame(1, $this->alice->notificationDevices()->count());

        // Same phone, someone else logs in: the id follows the phone.
        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->postJson('/api/mobile/v1/notifications/devices', ['player_id' => 'player-1', 'platform' => 'android'])->assertOk();

        $this->assertSame(0, $this->alice->notificationDevices()->count());
        $this->assertSame(1, $this->bob->notificationDevices()->count());

        $this->deleteJson('/api/mobile/v1/notifications/devices', ['player_id' => 'player-1'])->assertOk();
        $this->assertSame(0, $this->bob->notificationDevices()->count());
    }

    public function test_push_goes_to_the_registered_phones_in_every_language_at_once(): void
    {
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        Http::swap(new Factory);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);

        $this->bob->notificationDevices()->create(['player_id' => 'bob-phone-1']);
        $this->bob->notificationDevices()->create(['player_id' => 'bob-phone-2']);
        $this->fund($this->alice, 10000);

        $this->transfer(2500);
        $this->flushDeferred();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'onesignal.com')
                && $request['include_player_ids'] === ['bob-phone-1', 'bob-phone-2']
                && $request['headings']['ar'] === 'استلمت تحويلاً'
                && $request['headings']['en'] === 'You received a transfer'
                && str_contains($request['contents']['ar'], '25.00 SAR')
                && str_contains($request['contents']['en'], '25.00 SAR')
                && $request['data']['event'] === 'wallet.transfer.received';
        });
    }

    public function test_no_registered_phone_means_no_push_call(): void
    {
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        Http::swap(new Factory);
        Http::fake();

        $this->fund($this->alice, 10000);
        $this->transfer(2500);
        $this->flushDeferred();

        Http::assertNothingSent();
    }

    public function test_a_push_service_outage_never_breaks_the_money_movement(): void
    {
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        Http::swap(new Factory);
        Http::fake(['api.onesignal.com/*' => Http::response('down', 500)]);

        $this->bob->notificationDevices()->create(['player_id' => 'bob-phone-1']);
        $this->fund($this->alice, 10000);

        $this->transfer(2500);
        $this->flushDeferred();

        $this->assertSame(2500, $this->wallets->firstOrCreateWallet($this->bob, $this->saudi)->spend_only_minor);
        $this->assertSame(1, $this->bob->notifications()->count(), 'the in-app copy is unaffected');
    }
}
