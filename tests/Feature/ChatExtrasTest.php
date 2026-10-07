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
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Link cards, group join approval, polls, view-once media and live location.
 */
class ChatExtrasTest extends TestCase
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

        // Everyone knows everyone (no message requests in the way).
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice], [$this->alice, $this->carol], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        Storage::fake('public');
        config(['chat.link_preview_check_dns' => false]);
    }

    // ================================================================ link cards

    public function test_a_link_gets_a_card_after_sending(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('<html><head><title>Fallback</title>'
                .'<meta property="og:title" content="Dorr &amp; friends">'
                .'<meta property="og:description" content="The super app">'
                .'<meta property="og:image" content="/cover.jpg">'
                .'<meta property="og:site_name" content="Example"></head></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $chat = $this->direct($this->alice, $this->bob);
        $id = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'look https://example.com/page!'])->json('data.id');

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertOk()
            ->assertJsonPath('data.messages.0.id', $id)
            ->assertJsonPath('data.messages.0.link_preview.title', 'Dorr & friends')
            ->assertJsonPath('data.messages.0.link_preview.image', 'https://example.com/cover.jpg')
            ->assertJsonPath('data.messages.0.link_preview.site_name', 'Example');

        // The composer asks for the same card while typing — served from the cache.
        $this->getJson('/api/mobile/v1/chat/link-preview?url='.urlencode('https://example.com/page'), $this->headers())
            ->assertOk()->assertJsonPath('data.description', 'The super app');
        Http::assertSentCount(1);
    }

    public function test_private_addresses_are_never_fetched(): void
    {
        config(['chat.link_preview_check_dns' => true]);
        Http::fake();

        $this->as($this->alice);
        foreach (['http://127.0.0.1/admin', 'http://10.0.0.5/', 'http://192.168.1.1/', 'file:///etc/passwd'] as $url) {
            $this->getJson('/api/mobile/v1/chat/link-preview?url='.urlencode($url), $this->headers())->assertOk()->assertJsonPath('data', []);
        }
        Http::assertNothingSent();
    }

    // ================================================================ join approval

    public function test_joining_by_link_waits_for_an_admin_when_approval_is_on(): void
    {
        $group = $this->group($this->alice, [$this->bob]);
        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['approve_joins' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.group.approve_joins', true);
        $token = $this->getJson("/api/mobile/v1/chat/groups/{$group}/invite", $this->headers())->json('data.token');

        // Carol asks; she's not in yet.
        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/chat/invites/{$token}", $this->headers())->assertJsonPath('data.approve_joins', true)->assertJsonPath('data.request_status', null);
        $this->postJson("/api/mobile/v1/chat/invites/{$token}/join", [], $this->headers())->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/mobile/v1/chat/invites/{$token}/join", [], $this->headers())->assertStatus(202); // asking twice = one request
        $this->getJson("/api/mobile/v1/chat/invites/{$token}", $this->headers())->assertJsonPath('data.request_status', 'pending');
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}/messages", $this->headers())->assertStatus(403);

        // Members can't see or answer requests; the admin can.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/join-requests", $this->headers())->assertStatus(403);
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertJsonPath('data.group.pending_join_requests', 1);
        $requestId = $this->getJson("/api/mobile/v1/chat/groups/{$group}/join-requests", $this->headers())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.profile.name', 'Carol')->json('data.0.id');

        $this->postJson("/api/mobile/v1/chat/groups/{$group}/join-requests/{$requestId}/approve", [], $this->headers())->assertOk()->assertJsonCount(0, 'data');

        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}/messages", $this->headers())->assertOk();
    }

    public function test_a_rejected_request_does_not_join(): void
    {
        $group = $this->group($this->alice, [$this->bob]);
        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/groups/{$group}/settings", ['approve_joins' => true], $this->headers());
        $token = $this->getJson("/api/mobile/v1/chat/groups/{$group}/invite", $this->headers())->json('data.token');

        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/invites/{$token}/join", [], $this->headers())->assertStatus(202);

        $this->as($this->alice);
        $id = $this->getJson("/api/mobile/v1/chat/groups/{$group}/join-requests", $this->headers())->json('data.0.id');
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/join-requests/{$id}/reject", [], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/join-requests/{$id}/approve", [], $this->headers())->assertNotFound();

        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}/messages", $this->headers())->assertStatus(403);
    }

    // ================================================================ polls

    public function test_polls_count_votes_and_respect_single_choice(): void
    {
        $group = $this->group($this->alice, [$this->bob, $this->carol]);
        $poll = $this->send($this->alice, $group, ['type' => 'poll', 'body' => 'Lunch?', 'poll_options' => ['Pizza', 'Sushi', 'Pizza', ' ']])
            ->assertCreated()->assertJsonCount(2, 'data.poll.options')->json('data.id');

        $this->send($this->alice, $group, ['type' => 'poll', 'body' => 'Bad', 'poll_options' => ['Only one']])->assertStatus(422);

        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$poll}/vote", ['options' => [1, 2]], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_poll_invalid_vote');
        $this->putJson("/api/mobile/v1/chat/messages/{$poll}/vote", ['options' => [2]], $this->headers())->assertOk()
            ->assertJsonPath('data.poll.my_votes', [2])->assertJsonPath('data.poll.options.1.votes', 1);

        $this->as($this->carol);
        $this->putJson("/api/mobile/v1/chat/messages/{$poll}/vote", ['options' => [2]], $this->headers())->assertOk()->assertJsonPath('data.poll.voters', 2);
        // Changing my mind moves my vote.
        $this->putJson("/api/mobile/v1/chat/messages/{$poll}/vote", ['options' => [1]], $this->headers())->assertOk()
            ->assertJsonPath('data.poll.options.0.votes', 1)->assertJsonPath('data.poll.options.1.votes', 1);

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/messages/{$poll}/votes", $this->headers())->assertOk()
            ->assertJsonPath('data.0.voters.0.name', 'Carol')->assertJsonPath('data.1.voters.0.name', 'Bob');
    }

    // ================================================================ view once

    public function test_view_once_media_opens_once_for_the_recipient_only(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $id = $this->send($this->alice, $chat, ['type' => 'image', 'view_once' => true, 'body' => 'secret caption', 'files' => [UploadedFile::fake()->image('p.jpg')]])
            ->assertCreated()->assertJsonPath('data.view_once', true)->assertJsonCount(0, 'data.attachments')->json('data.id');

        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'x', 'view_once' => true])->assertStatus(422);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$id}/open", [], $this->headers())->assertStatus(403);
        $this->postJson('/api/mobile/v1/chat/messages/forward', ['messages' => [$id], 'conversations' => [$chat]], $this->headers())->assertStatus(422);

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertJsonPath('data.messages.0.view_once_opened', false)
            ->assertJsonCount(0, 'data.messages.0.attachments');
        $this->postJson("/api/mobile/v1/chat/messages/{$id}/open", [], $this->headers())->assertOk()->assertJsonCount(1, 'data.attachments');
        $this->postJson("/api/mobile/v1/chat/messages/{$id}/open", [], $this->headers())->assertStatus(410)->assertJsonPath('error_code', 'chat_view_once_opened');
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertJsonPath('data.messages.0.view_once_opened', true);

        // The sender sees "opened".
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertJsonPath('data.messages.0.view_once_opened', true);

        // And the file is removed once everyone opened it.
        $this->travel(15)->minutes();
        $this->artisan('chat:purge')->assertSuccessful();
        $this->assertCount(0, ChatMessage::query()->where('uuid', $id)->first()->getMedia(ChatMessage::ATTACHMENTS));
    }

    // ================================================================ live location

    public function test_live_location_moves_until_it_is_stopped(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $id = $this->send($this->alice, $chat, ['type' => 'location', 'latitude' => 24.7, 'longitude' => 46.6, 'live_seconds' => 900])
            ->assertCreated()->assertJsonPath('data.live_location.active', true)->json('data.id');

        $this->send($this->alice, $chat, ['type' => 'location', 'latitude' => 24.7, 'longitude' => 46.6, 'live_seconds' => 999])->assertStatus(422);

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/live-locations', $this->headers())->assertOk()->assertJsonPath('data.0.message_id', $id);
        $this->putJson("/api/mobile/v1/chat/messages/{$id}/live-location", ['latitude' => 24.8, 'longitude' => 46.7], $this->headers())->assertOk();

        // Only the sender moves it.
        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$id}/live-location", ['latitude' => 1, 'longitude' => 1], $this->headers())->assertStatus(403);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->assertJsonPath('data.messages.0.meta.latitude', 24.8);

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/messages/{$id}/live-location/stop", [], $this->headers())->assertOk()->assertJsonPath('data.live_location.active', false);
        $this->putJson("/api/mobile/v1/chat/messages/{$id}/live-location", ['latitude' => 25, 'longitude' => 47], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_live_location_ended');
        $this->getJson('/api/mobile/v1/chat/live-locations', $this->headers())->assertJsonCount(0, 'data');
    }

    // ================================================================ helpers

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

    private function direct(User $me, User $other): string
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
    }

    /**
     * @param  list<User>  $members
     */
    private function group(User $owner, array $members): string
    {
        $this->as($owner);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => array_map(fn (User $u) => $u->id, $members)], $this->headers())
            ->assertCreated()->json('data.conversation.id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(User $sender, string $conversation, array $data): \Illuminate\Testing\TestResponse
    {
        $this->as($sender);

        return $this->post("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers() + ['Accept' => 'application/json']);
    }
}
