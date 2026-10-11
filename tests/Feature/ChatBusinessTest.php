<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Business tools: quick replies ("/"), opening hours, and the welcome / away messages.
 * Alice runs a shop; Bob is a customer writing to her for the first time.
 */
class ChatBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

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

        // A Monday, 20:00 UTC.
        $this->travelTo(Carbon::parse('2026-10-05 20:00:00', 'UTC'));
    }

    // ================================================================ quick replies

    public function test_quick_replies_are_saved_listed_changed_and_removed(): void
    {
        $this->as($this->alice);

        $id = $this->postJson('/api/mobile/v1/chat/quick-replies', ['shortcut' => '/Hours', 'body' => 'We open 9 to 5.'], $this->headers())
            ->assertCreated()->assertJsonPath('data.shortcut', 'hours')->json('data.id');
        $this->postJson('/api/mobile/v1/chat/quick-replies', ['shortcut' => 'hours', 'body' => 'again'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_quick_reply_taken');
        $this->postJson('/api/mobile/v1/chat/quick-replies', ['shortcut' => 'two words', 'body' => 'x'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('shortcut');
        $this->postJson('/api/mobile/v1/chat/quick-replies', ['shortcut' => 'عنوان', 'body' => 'شارع التحلية'], $this->headers())->assertCreated();

        $this->getJson('/api/mobile/v1/chat/quick-replies', $this->headers())->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson("/api/mobile/v1/chat/quick-replies/{$id}", ['body' => 'We open 10 to 6.'], $this->headers())
            ->assertOk()->assertJsonPath('data.body', 'We open 10 to 6.');

        // Someone else's can't be touched.
        $this->as($this->bob);
        $this->deleteJson("/api/mobile/v1/chat/quick-replies/{$id}", [], $this->headers())->assertNotFound();

        $this->as($this->alice);
        $this->deleteJson("/api/mobile/v1/chat/quick-replies/{$id}", [], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/business', $this->headers())->assertOk()->assertJsonCount(1, 'data.quick_replies');
    }

    // ================================================================ welcome

    public function test_a_new_customer_is_welcomed_once(): void
    {
        $this->business(['welcome_enabled' => true, 'welcome_message' => 'Welcome to our shop!']);

        $chat = $this->direct($this->bob, $this->alice);
        $hi = $this->send($this->bob, $chat, 'hi')->assertCreated()->json('data.id');

        $welcome = $this->autoReplies($chat, 'welcome');
        $this->assertCount(1, $welcome);
        $this->assertSame('Welcome to our shop!', $welcome->first()->body);
        $this->assertTrue($welcome->first()->isFrom('user', $this->alice->id));

        // Sent by itself: Alice's message request isn't accepted, and Bob's message isn't read.
        $conversation = ChatConversation::query()->where('uuid', $chat)->first();
        $this->assertSame('pending', $conversation->status->value);
        $aliceRow = ChatParticipant::query()->where('conversation_id', $conversation->id)->where('participant_id', $this->alice->id)->first();
        $this->assertNull($aliceRow->last_read_message_id);
        $this->assertSame(1, $aliceRow->unread_count);

        // Bob sees it labelled as automatic.
        $this->as($this->bob);
        $last = collect($this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())->json('data.messages'))->last();
        $this->assertSame('welcome', $last['meta']['auto_reply']);
        $this->assertNotSame($hi, $last['id']);

        // Not again for the next message…
        $this->send($this->bob, $chat, 'are you there?')->assertCreated();
        $this->assertCount(1, $this->autoReplies($chat, 'welcome'));

        // …but again after two quiet weeks.
        $this->travel(15)->days();
        $this->send($this->bob, $chat, 'hello again')->assertCreated();
        $this->assertCount(2, $this->autoReplies($chat, 'welcome'));
    }

    public function test_switching_a_message_on_needs_its_text(): void
    {
        $this->as($this->alice);
        $this->patchJson('/api/mobile/v1/chat/business', ['welcome_enabled' => true], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_business_message_required');
        $this->patchJson('/api/mobile/v1/chat/business', ['away_enabled' => true, 'away_message' => 'Closed', 'away_mode' => 'outside_hours'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_business_hours_required');
    }

    // ================================================================ away & hours

    public function test_the_away_message_goes_out_when_closed_once_per_twelve_hours(): void
    {
        $this->business([
            'away_enabled' => true, 'away_message' => 'We are closed, back at 9.', 'away_mode' => 'outside_hours',
            'hours' => $this->everyDay('09:00', '17:00'), 'timezone' => 'UTC',
        ]);
        $chat = $this->direct($this->bob, $this->alice);

        // 20:00: closed.
        $this->send($this->bob, $chat, 'hello?')->assertCreated();
        $this->send($this->bob, $chat, 'anyone?')->assertCreated();
        $this->assertCount(1, $this->autoReplies($chat, 'away'));

        // Next day 10:00: open — no away message.
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
        $this->send($this->bob, $chat, 'good morning')->assertCreated();
        $this->assertCount(1, $this->autoReplies($chat, 'away'));

        // 22:00, 26 hours after the first: closed again, and it's been long enough.
        $this->travelTo(Carbon::parse('2026-10-06 22:00:00', 'UTC'));
        $this->send($this->bob, $chat, 'one more thing')->assertCreated();
        $this->assertCount(2, $this->autoReplies($chat, 'away'));
    }

    public function test_hours_follow_the_business_time_zone_and_can_run_past_midnight(): void
    {
        // A café in Riyadh (UTC+3), open 18:00–02:00 every day.
        $this->business([
            'away_enabled' => true, 'away_message' => 'Closed now.', 'away_mode' => 'outside_hours',
            'hours' => $this->everyDay('18:00', '02:00'), 'timezone' => 'Asia/Riyadh',
        ]);
        $chat = $this->direct($this->bob, $this->alice);

        // 22:00 UTC = 01:00 in Riyadh: still open from the evening before.
        $this->travelTo(Carbon::parse('2026-10-05 22:00:00', 'UTC'));
        $this->send($this->bob, $chat, 'still open?')->assertCreated();
        $this->assertCount(0, $this->autoReplies($chat, 'away'));

        // 00:30 UTC = 03:30 in Riyadh: closed.
        $this->travelTo(Carbon::parse('2026-10-06 00:30:00', 'UTC'));
        $this->send($this->bob, $chat, 'hello?')->assertCreated();
        $this->assertCount(1, $this->autoReplies($chat, 'away'));

        // The customer sees the hours and that it's closed now.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertOk()
            ->assertJsonPath('data.business.open_now', false)
            ->assertJsonPath('data.business.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.business.hours.1.from', '18:00');
    }

    public function test_away_wins_over_welcome_and_always_needs_no_hours(): void
    {
        $this->business([
            'welcome_enabled' => true, 'welcome_message' => 'Welcome!',
            'away_enabled' => true, 'away_message' => 'On holiday until Sunday.', 'away_mode' => 'always',
        ]);
        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, 'hi')->assertCreated();

        $this->assertCount(1, $this->autoReplies($chat, 'away'));
        $this->assertCount(0, $this->autoReplies($chat, 'welcome'));
    }

    public function test_no_automatic_replies_in_groups(): void
    {
        $this->business(['welcome_enabled' => true, 'welcome_message' => 'Welcome!']);

        $this->as($this->bob);
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Club', 'members' => [$this->alice->id]], $this->headers())->json('data.conversation.id');
        $this->send($this->bob, $group, 'hi all')->assertCreated();

        $this->assertCount(0, $this->autoReplies($group, 'welcome'));
    }

    public function test_business_tools_can_be_limited_to_some_accounts(): void
    {
        $this->business(['welcome_enabled' => true, 'welcome_message' => 'Welcome!']);
        config(['chat.business_participants' => ['provider']]);

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/business', $this->headers())->assertForbidden()->assertJsonPath('error_code', 'chat_business_unavailable');

        $chat = $this->direct($this->bob, $this->alice);
        $this->send($this->bob, $chat, 'hi')->assertCreated();
        $this->assertCount(0, $this->autoReplies($chat, 'welcome'));
    }

    // ================================================================ helpers

    /**
     * @param  array<string, mixed>  $data
     */
    private function business(array $data): void
    {
        $this->as($this->alice);
        $this->patchJson('/api/mobile/v1/chat/business', $data, $this->headers())->assertOk();
    }

    /**
     * @return list<array{open: bool, from: string, to: string}>
     */
    private function everyDay(string $from, string $to): array
    {
        return array_fill(0, 7, ['open' => true, 'from' => $from, 'to' => $to]);
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    private function autoReplies(string $chat, string $kind)
    {
        $id = ChatConversation::query()->where('uuid', $chat)->value('id');

        return ChatMessage::query()->where('conversation_id', $id)->get()->filter(fn (ChatMessage $m) => ($m->meta['auto_reply'] ?? null) === $kind)->values();
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

    private function direct(User $me, User $other): string
    {
        $this->as($me);

        return $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
    }

    private function send(User $sender, string $conversation, string $body): TestResponse
    {
        $this->as($sender);

        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", ['type' => 'text', 'body' => $body], $this->headers());
    }
}
