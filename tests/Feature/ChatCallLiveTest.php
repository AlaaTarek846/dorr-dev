<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * A call broadcast live (spec 93): someone in the call (a group's admin) starts it; viewers get a
 * token that can't publish and keeps them hidden; members-only or anyone with the link; its card
 * goes into chats; it ends with the call.
 */
class ChatCallLiveTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        foreach ([$this->bob, $this->carol] as $other) {
            foreach ([[$this->alice, $other], [$other, $this->alice]] as [$owner, $contact]) {
                ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
            }
        }
        config(['chat.livekit.url' => 'wss://live.example.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => 'secret-that-is-long-enough']);
        Http::fake();
        $this->travelTo(Carbon::parse('2026-10-20 18:00', 'UTC'));
    }

    public function test_a_group_call_goes_live_viewers_only_watch_and_it_ends_with_the_call(): void
    {
        $this->as($this->alice);
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Studio', 'members' => [$this->bob->id]], $this->headers())->json('data.conversation.id');
        $call = $this->postJson("/api/mobile/v1/chat/conversations/{$group}/calls", ['type' => 'video'], $this->headers())->assertCreated()->json('data.call.id');
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/accept", [], $this->headers())->assertOk();
        // Only an admin of the group starts it.
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/live", ['audience' => 'link'], $this->headers())->assertForbidden();

        $this->as($this->alice);
        $live = $this->postJson("/api/mobile/v1/chat/calls/{$call}/live", ['title' => 'Podcast #1', 'audience' => 'members'], $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'live')->assertJsonPath('data.title', 'Podcast #1');
        $token = $live->json('data.token');

        // Members only: Carol (not in the group) can't watch.
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/lives/watch/{$token}", [], $this->headers())->assertForbidden()->assertJsonPath('error_code', 'chat_live_members_only');
        // Bob watches with a token that can't publish and keeps him hidden.
        $this->as($this->bob);
        $watch = $this->postJson("/api/mobile/v1/chat/lives/watch/{$token}", [], $this->headers())->assertOk()->assertJsonPath('data.live.viewers', 1);
        $grants = json_decode(base64_decode(strtr(explode('.', $watch->json('data.join.token'))[1], '-_', '+/')), true)['video'];
        $this->assertFalse($grants['canPublish']);
        $this->assertTrue($grants['hidden']);

        // Its card can go only into its own chat (members only).
        $this->as($this->alice);
        $direct = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->carol->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/lives/watch/{$token}/share", ['conversation_ids' => [$direct, $group]], $this->headers())->assertOk()->assertJsonPath('data.sent', 1);
        $this->assertSame($token, ChatMessage::query()->where('type', 'live_card')->sole()->meta['live']['token']);

        // The call ends: so does the live; watching gives no room any more.
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/leave", [], $this->headers())->assertOk();
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/leave", [], $this->headers());
        $this->postJson("/api/mobile/v1/chat/lives/watch/{$token}", [], $this->headers())->assertOk()
            ->assertJsonPath('data.live.status', 'ended')->assertJsonPath('data.join', null);
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
