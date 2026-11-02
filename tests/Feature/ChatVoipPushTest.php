<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * An iPhone with a PushKit token rings through CallKit: a ringing call goes to it as an APNs VoIP
 * push, and not also through OneSignal. Without APNs keys it gets the OneSignal call push as before.
 */
class ChatVoipPushTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
        config([
            'chat.livekit.url' => 'wss://live.example.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => 'secret-that-is-long-enough',
            'services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest',
        ]);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1']), 'api.sandbox.push.apple.com/*' => Http::response([], 200)]);
    }

    public function test_a_ringing_call_reaches_an_iphone_as_a_voip_push_and_not_through_onesignal(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        config(['services.apns.key_id' => 'KEY123', 'services.apns.team_id' => 'TEAM123', 'services.apns.key' => $pem, 'services.apns.bundle_id' => 'com.dorr.app']);

        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->postJson('/api/mobile/v1/notifications/devices', ['player_id' => 'bob-iphone', 'platform' => 'ios', 'voip_token' => 'abc123'], $this->headers())->assertOk();
        $this->postJson('/api/mobile/v1/notifications/devices', ['player_id' => 'bob-android', 'platform' => 'android'], $this->headers())->assertOk();
        $this->assertSame('abc123', NotificationDevice::query()->where('player_id', 'bob-iphone')->value('voip_token'));

        Sanctum::actingAs($this->alice, [], 'user_api');
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $call = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/calls", ['type' => 'video'], $this->headers())->assertCreated()->json('data.call.id');
        app(DeferredCallbackCollection::class)->invoke();

        $requests = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $voip = $requests->filter(fn ($r) => str_contains($r->url(), 'push.apple.com'))->values();
        $this->assertCount(1, $voip);
        $this->assertStringEndsWith('/3/device/abc123', $voip[0]->url());
        $this->assertSame('com.dorr.app.voip', $voip[0]->header('apns-topic')[0]);
        $this->assertSame('voip', $voip[0]->header('apns-push-type')[0]);
        $this->assertSame($call, $voip[0]['call_id']);
        $this->assertSame('Alice', $voip[0]['caller_name']);
        $this->assertSame('1', $voip[0]['video']);

        $ring = $requests->filter(fn ($r) => str_contains($r->url(), 'onesignal') && ($r['data']['event'] ?? null) === 'chat.call.ringing')->values();
        $this->assertCount(1, $ring);
        $this->assertSame(['bob-android'], $ring[0]['include_player_ids']);
    }

    public function test_without_apns_keys_the_iphone_gets_the_onesignal_call_push(): void
    {
        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->postJson('/api/mobile/v1/notifications/devices', ['player_id' => 'bob-iphone', 'platform' => 'ios', 'voip_token' => 'abc123'], $this->headers())->assertOk();

        Sanctum::actingAs($this->alice, [], 'user_api');
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/calls", ['type' => 'voice'], $this->headers())->assertCreated();
        app(DeferredCallbackCollection::class)->invoke();

        $requests = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $this->assertCount(0, $requests->filter(fn ($r) => str_contains($r->url(), 'push.apple.com')));
        $ring = $requests->filter(fn ($r) => str_contains($r->url(), 'onesignal') && ($r['data']['event'] ?? null) === 'chat.call.ringing')->values();
        $this->assertSame(['bob-iphone'], $ring[0]['include_player_ids']);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA', 'Accept' => 'application/json'];
    }
}
