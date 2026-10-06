<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Threads (spec 122), group decisions (119–120) and smart quiet (115).
 */
class ChatThreadsDecisionsTest extends TestCase
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

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->bob->id, 'player_id' => 'bob-phone', 'platform' => 'android']);
    }

    // ------------------------------------------------------------------ threads

    public function test_thread_replies_stay_under_their_message_and_out_of_the_timeline(): void
    {
        $group = $this->group();
        $root = $this->send($this->alice, $group, ['type' => 'text', 'body' => 'Trip on Friday?'])->json('data.id');
        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'I am in', 'thread' => $root])->assertCreated()->assertJsonPath('data.thread_root', $root);
        $reply = $this->send($this->carol, $group, ['type' => 'text', 'body' => 'me too', 'thread' => $root])->json('data.id');
        // Replying to a reply continues the same thread.
        $this->send($this->alice, $group, ['type' => 'text', 'body' => 'great', 'thread' => $reply])->assertJsonPath('data.thread_root', $root);
        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'unrelated'])->assertCreated();

        $this->as($this->alice);
        $timeline = $this->getJson("/api/mobile/v1/chat/conversations/{$group}/messages", $this->headers())->json('data.messages');
        $this->assertSame(['Trip on Friday?', 'unrelated'], collect($timeline)->where('type', 'text')->pluck('body')->values()->all());
        $this->assertSame(3, collect($timeline)->firstWhere('id', $root)['thread']['count']);

        $this->getJson("/api/mobile/v1/chat/conversations/{$group}/messages?thread={$root}", $this->headers())->assertOk()
            ->assertJsonPath('data.root.id', $root)
            ->assertJsonCount(3, 'data.messages')->assertJsonPath('data.messages.0.body', 'I am in');
    }

    // ------------------------------------------------------------------ decisions

    public function test_a_message_becomes_a_vote_and_an_admin_records_the_decision(): void
    {
        $group = $this->group();
        $idea = $this->send($this->bob, $group, ['type' => 'text', 'body' => 'Move the meeting to Sunday'])->json('data.id');

        // Anyone can put it to a vote (agree / disagree by default), once.
        $this->as($this->bob);
        $decision = $this->postJson("/api/mobile/v1/chat/messages/{$idea}/decision", [], $this->headers())
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.title', 'Move the meeting to Sunday')
            ->assertJsonCount(2, 'data.options');
        $this->postJson("/api/mobile/v1/chat/messages/{$idea}/decision", [], $this->headers())->assertStatus(409);

        $poll = $decision->json('data.poll_message_id');
        foreach ([$this->alice, $this->bob, $this->carol] as $i => $voter) {
            $this->as($voter);
            $this->putJson("/api/mobile/v1/chat/messages/{$poll}/vote", ['options' => [$i === 2 ? 2 : 1]], $this->headers())->assertOk();
        }

        // Only an admin settles it; the outcome defaults to the most voted option.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/decisions/{$decision->json('data.id')}/decide", ['approve' => true], $this->headers())->assertForbidden();
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/decisions/{$decision->json('data.id')}/decide", ['approve' => true], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.outcome', 'Agree')->assertJsonPath('data.decided_by.name', 'Alice');

        $this->getJson("/api/mobile/v1/chat/groups/{$group}/decisions", $this->headers())->assertOk()
            ->assertJsonPath('data.0.title', 'Move the meeting to Sunday')->assertJsonPath('data.0.options.0.votes', 2);
    }

    // ------------------------------------------------------------------ smart quiet

    public function test_quiet_time_holds_the_notifications_and_sums_them_up_after(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 20:00', 'UTC')); // 23:00 in Riyadh
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['quiet_schedule' => ['from' => '22:00', 'to' => '07:00', 'timezone' => 'Asia/Riyadh']], $this->headers())
            ->assertOk()->assertJsonPath('data.quiet.on', true);

        $chat = $this->direct($this->alice, $this->bob);
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'are you up?']);
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'call me tomorrow']);
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'are you up'));

        // An urgent one still comes through (Bob has Alice saved).
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'URGENT: the door', 'urgent' => true]);
        Http::assertSent(fn ($r) => ($r['contents']['en'] ?? null) === 'URGENT: the door');

        // Still quiet: no summary yet. Morning: one summary.
        $this->artisan('chat:quiet-digest')->assertSuccessful();
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        Http::assertNotSent(fn ($r) => ($r['data']['event'] ?? null) === 'chat.quiet.digest');

        $this->travelTo(Carbon::parse('2026-10-07 05:00', 'UTC')); // 08:00 in Riyadh
        $this->artisan('chat:quiet-digest')->assertSuccessful();
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        Http::assertSent(fn ($r) => ($r['data']['event'] ?? null) === 'chat.quiet.digest' && $r['contents']['en'] === '2 messages in 1 chats');
    }

    // ------------------------------------------------------------------ helpers

    private function group(): string
    {
        $this->as($this->alice);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
    }

    private function direct(User $me, User $other): string
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(User $sender, string $conversation, array $data): \Illuminate\Testing\TestResponse
    {
        $this->as($sender);

        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers());
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
