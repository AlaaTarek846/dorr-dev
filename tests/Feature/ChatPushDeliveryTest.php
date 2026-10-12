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
 * A chat message reaches the other one's phone, and a person shows by my contact's name for
 * them, else their own name, else their number.
 */
class ChatPushDeliveryTest extends TestCase
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
        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->bob = User::create(['name' => null, 'phone' => '+966500000002', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
    }

    public function test_a_message_reaches_a_phone_registered_the_way_the_app_does_it(): void
    {
        // The app registers through notifications/devices: saved under the account's morph class.
        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->postJson('/api/mobile/v1/notifications/devices', ['player_id' => 'bob-phone', 'platform' => 'android'], $this->headers())->assertOk();
        $this->assertSame($this->bob->getMorphClass(), NotificationDevice::query()->value('owner_type'));

        Sanctum::actingAs($this->alice, [], 'user_api');
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'hi Bob'], $this->headers())->assertCreated();

        app(DeferredCallbackCollection::class)->invoke();
        $pushes = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'onesignal') && ($r['data']['event'] ?? null) === 'chat.message.sent')->values();
        $this->assertCount(1, $pushes);
        $this->assertSame(['bob-phone'], $pushes[0]['include_player_ids']);
    }

    public function test_a_person_shows_by_my_contacts_name_else_their_name_else_their_number(): void
    {
        Sanctum::actingAs($this->alice, [], 'user_api');
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');

        // Bob never set a name and I haven't saved him: his number.
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertOk()
            ->assertJsonPath('data.title', '+966500000002')->assertJsonPath('data.peer.name', '+966500000002');

        // I saved him: my name for him.
        ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $this->alice->id, 'name' => 'Bobby (work)', 'phone' => $this->bob->phone, 'contact_type' => 'user', 'contact_id' => $this->bob->id, 'source' => 'manual']);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertOk()->assertJsonPath('data.title', 'Bobby (work)');

        // Bob sees Alice by the name she chose (he hasn't saved her).
        Sanctum::actingAs($this->bob, [], 'user_api');
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertOk()->assertJsonPath('data.title', 'Alice');
    }

    public function test_a_push_to_all_users_goes_to_onesignals_all_segment(): void
    {
        $this->assertTrue(sendPushNotification(message: 'Hello everyone', playerIds: null, type: 'All Users'));
        $this->assertFalse(sendPushNotification(message: 'Nobody', playerIds: null));

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(1, $sent);
        $this->assertSame(['All'], $sent[0]['included_segments']);
        $this->assertArrayNotHasKey('include_player_ids', $sent[0]->data());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA', 'Accept' => 'application/json'];
    }
}
