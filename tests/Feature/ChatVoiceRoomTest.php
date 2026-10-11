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
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Standing voice rooms (spec 91): every group has one; members drop in and out; hosts (the
 * group's admins) choose who may speak — enforced by LiveKit (the token, and live changes
 * through its RoomService) — and members who vanish are swept out.
 */
class ChatVoiceRoomTest extends TestCase
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
        Http::fake(['live.example.test/*' => Http::response([])]);
        $this->travelTo(Carbon::parse('2026-10-20 18:00', 'UTC'));
    }

    public function test_members_drop_in_hosts_decide_who_speaks_and_livekit_enforces_it(): void
    {
        $group = $this->group();

        // Bob drops in: everyone may speak by default.
        $this->as($this->bob);
        $bob = $this->postJson("/api/mobile/v1/chat/conversations/{$group}/voice-room/join", [], $this->headers())->assertOk()
            ->assertJsonPath('data.room.count', 1)->assertJsonPath('data.room.me.role', 'speaker');
        $this->assertTrue($this->grants($bob->json('data.join.token'))['canPublish']);
        $room = $bob->json('data.room.id');

        // Alice (the group admin) is a host, and makes it "only those I let speak".
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$group}/voice-room/join", [], $this->headers())->assertOk()->assertJsonPath('data.room.me.role', 'host');
        $this->patchJson("/api/mobile/v1/chat/voice-rooms/{$room}", ['speak_policy' => 'approved'], $this->headers())->assertOk()->assertJsonPath('data.speak_policy', 'approved');

        // Carol comes in as a listener — LiveKit won't let her publish — and raises her hand.
        $this->as($this->carol);
        $carol = $this->postJson("/api/mobile/v1/chat/conversations/{$group}/voice-room/join", [], $this->headers())->assertOk()->assertJsonPath('data.room.me.role', 'listener');
        $this->assertFalse($this->grants($carol->json('data.join.token'))['canPublish']);
        $this->postJson("/api/mobile/v1/chat/voice-rooms/{$room}/hand", ['raised' => true], $this->headers())->assertOk();
        // Only a host decides.
        $carolId = $this->pid($this->carol, $group);
        $this->postJson("/api/mobile/v1/chat/voice-rooms/{$room}/members/{$carolId}/role", ['role' => 'speaker'], $this->headers())->assertForbidden();

        $this->as($this->alice);
        $shown = $this->getJson("/api/mobile/v1/chat/conversations/{$group}/voice-room", $this->headers())->assertOk()->assertJsonPath('data.count', 3);
        $this->assertTrue(collect($shown->json('data.members'))->firstWhere('name', 'Carol')['hand_raised']);
        $this->postJson("/api/mobile/v1/chat/voice-rooms/{$room}/members/{$carolId}/role", ['role' => 'speaker'], $this->headers())->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/twirp/livekit.RoomService/UpdateParticipant') && $r['permission']['can_publish'] === true
            && $r['identity'] === 'user:'.$this->carol->id && str_starts_with($r->url(), 'https://live.example.test'));

        // A host puts Bob out (LiveKit too); he can come back.
        $bobId = $this->pid($this->bob, $group);
        $this->postJson("/api/mobile/v1/chat/voice-rooms/{$room}/members/{$bobId}/remove", [], $this->headers())->assertOk()->assertJsonPath('data.count', 2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'RemoveParticipant') && $r['identity'] === 'user:'.$this->bob->id);

        // Carol leaves; Alice's phone goes quiet — after 90 s without a heartbeat she's out too.
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/voice-rooms/{$room}/leave", [], $this->headers())->assertOk();
        $this->travel(60)->seconds();
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/voice-rooms/{$room}/ping", [], $this->headers())->assertOk();
        $this->travel(80)->seconds();
        $this->artisan('chat:voice-rooms-sweep');
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}/voice-room", $this->headers())->assertOk()->assertJsonPath('data.count', 1);
        $this->travel(20)->seconds();
        $this->artisan('chat:voice-rooms-sweep');
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}/voice-room", $this->headers())->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_a_direct_chat_has_no_voice_room(): void
    {
        $this->as($this->alice);
        $direct = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/voice-room/join", [], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_voice_groups_only');
    }

    /** @return array<string, mixed> the token's room grants */
    private function grants(string $jwt): array
    {
        $payload = json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);

        return $payload['video'];
    }

    private function pid(User $user, string $conversation): int
    {
        $id = ChatConversation::query()->where('uuid', $conversation)->value('id');

        return (int) ChatParticipant::query()->where('conversation_id', $id)->where('participant_id', $user->id)->value('id');
    }

    private function group(): string
    {
        $this->as($this->alice);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
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
