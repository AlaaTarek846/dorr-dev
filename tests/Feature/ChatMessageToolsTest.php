<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Message tools: reminders (47, 121), "needs a reply" (118), urgent messages (116, 117) and a
 * personal status (90).
 */
class ChatMessageToolsTest extends TestCase
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

        // Alice and Bob saved each other; Carol is a stranger to Bob.
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->bob->id, 'player_id' => 'bob-phone', 'platform' => 'android']);
    }

    // ================================================================ reminders

    public function test_a_reminder_goes_out_once_at_its_time(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $message = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'Dentist on Thursday'])->json('data.id');

        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$message}/reminder", ['remind_at' => now()->addHour()->toIso8601String(), 'note' => 'Book a taxi'], $this->headers())
            ->assertOk()->assertJsonPath('data.note', 'Book a taxi');
        $this->getJson('/api/mobile/v1/chat/reminders', $this->headers())->assertOk()->assertJsonPath('data.0.message.id', $message);
        $this->assertNotNull(collect($this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->json('data.messages'))->firstWhere('id', $message)['reminder_at']);

        // Not yet; then due — sent once, with Bob's note.
        $this->artisan('chat:send-reminders')->assertSuccessful();
        Http::assertNotSent(fn ($r) => ($r['data']['event'] ?? null) === 'chat.reminder.due');
        $this->travel(61)->minutes();
        $this->artisan('chat:send-reminders')->assertSuccessful();
        $this->artisan('chat:send-reminders')->assertSuccessful();
        // Pushes go out deferred — after the command, like in production.
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(2); // Alice's message + the one reminder
        Http::assertSent(fn ($r) => ($r['data']['event'] ?? null) === 'chat.reminder.due' && $r['contents']['en'] === 'Book a taxi' && $r['include_player_ids'] === ['bob-phone']);

        $this->getJson('/api/mobile/v1/chat/reminders', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_reminder_is_mine_and_can_be_removed(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $message = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'hi'])->json('data.id');

        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$message}/reminder", ['remind_at' => now()->subMinute()->toIso8601String()], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_schedule_time_invalid');
        $this->putJson("/api/mobile/v1/chat/messages/{$message}/reminder", ['remind_at' => now()->addDay()->toIso8601String()], $this->headers())->assertOk();

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/reminders', $this->headers())->assertOk()->assertJsonCount(0, 'data');

        $this->as($this->carol);
        $this->putJson("/api/mobile/v1/chat/messages/{$message}/reminder", ['remind_at' => now()->addDay()->toIso8601String()], $this->headers())->assertForbidden();

        $this->as($this->bob);
        $this->deleteJson("/api/mobile/v1/chat/messages/{$message}/reminder", [], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/reminders', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    // ================================================================ needs a reply

    public function test_a_message_waits_for_my_reply_until_i_answer(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $question = $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'Can you send the invoice?'])->json('data.id');

        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$question}/follow-up", ['on' => true], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/messages/follow-up', $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $question)->assertJsonPath('data.0.is_follow_up', true);

        // Bob answers in the chat: off the list by itself.
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Sent!'])->assertCreated();
        $this->getJson('/api/mobile/v1/chat/messages/follow-up', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_in_a_group_only_a_reply_to_it_answers_it(): void
    {
        $this->as($this->alice);
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
        $question = $this->send($this->alice, $group, ['type' => 'text', 'body' => 'Who brings the cake?'])->json('data.id');

        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$question}/follow-up", ['on' => true], $this->headers())->assertOk();
        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'unrelated'])->assertCreated();
        $this->getJson('/api/mobile/v1/chat/messages/follow-up', $this->headers())->assertOk()->assertJsonCount(1, 'data');

        $this->send($this->bob, $group, ['type' => 'text', 'body' => 'Me!', 'reply_to' => $question])->assertCreated();
        $this->getJson('/api/mobile/v1/chat/messages/follow-up', $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    // ================================================================ urgent

    public function test_an_urgent_message_gets_through_a_mute_a_few_times_a_day(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->bob);
        $this->patchJson("/api/mobile/v1/chat/conversations/{$chat}/settings", ['mute' => 'always'], $this->headers())->assertOk();

        // Muted: a normal message makes no sound on Bob's phone…
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'normal'])->assertCreated();
        Http::assertNothingSent();

        // …an urgent one does (Alice is in Bob's contacts, the default audience).
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => 'Call me now', 'urgent' => true])
            ->assertCreated()->assertJsonPath('data.is_urgent', true);
        Http::assertSent(fn ($r) => $r['headings']['en'] === '🚨 Alice' && $r['data']['urgent'] === '1');

        $this->send($this->alice, $chat, ['type' => 'text', 'body' => '2', 'urgent' => true])->assertCreated();
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => '3', 'urgent' => true])->assertCreated();
        $this->send($this->alice, $chat, ['type' => 'text', 'body' => '4', 'urgent' => true])
            ->assertStatus(429)->assertJsonPath('error_code', 'chat_urgent_quota');
    }

    public function test_urgent_needs_the_recipients_permission_and_a_one_to_one_chat(): void
    {
        // Carol isn't in Bob's contacts.
        $chat = $this->direct($this->carol, $this->bob);
        $this->send($this->carol, $chat, ['type' => 'text', 'body' => 'urgent!', 'urgent' => true])
            ->assertForbidden()->assertJsonPath('error_code', 'chat_urgent_not_allowed');

        // Bob closes it to everyone.
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['who_can_urgent' => 'nobody'], $this->headers())->assertOk()->assertJsonPath('data.who_can_urgent', 'nobody');
        $withAlice = $this->direct($this->alice, $this->bob);
        $this->send($this->alice, $withAlice, ['type' => 'text', 'body' => 'urgent!', 'urgent' => true])->assertForbidden();

        $this->as($this->alice);
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'G', 'members' => [$this->bob->id]], $this->headers())->json('data.conversation.id');
        $this->send($this->alice, $group, ['type' => 'text', 'body' => 'urgent!', 'urgent' => true])
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_urgent_direct_only');
    }

    // ================================================================ personal status

    public function test_a_status_is_shown_to_its_audience_until_it_ends(): void
    {
        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/status', ['emoji' => '🏖️', 'text' => 'On holiday', 'until' => now()->addDays(2)->toIso8601String()], $this->headers())
            ->assertOk()->assertJsonPath('data.status.active', true)->assertJsonPath('data.status.audience', 'contacts');
        $this->putJson('/api/mobile/v1/chat/status', ['text' => ' '], $this->headers())->assertUnprocessable();

        // Alice (a contact) sees it; Carol (a stranger) doesn't.
        $withAlice = $this->direct($this->alice, $this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$withAlice}", $this->headers())->assertOk()->assertJsonPath('data.peer.status.text', 'On holiday');
        $withCarol = $this->direct($this->carol, $this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$withCarol}", $this->headers())->assertOk()->assertJsonPath('data.peer.status', null);

        // Over after two days.
        $this->travel(3)->days();
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$withAlice}", $this->headers())->assertOk()->assertJsonPath('data.peer.status', null);
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
     * @param  array<string, mixed>  $data
     */
    private function send(User $sender, string $conversation, array $data): \Illuminate\Testing\TestResponse
    {
        $this->as($sender);

        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers());
    }
}
