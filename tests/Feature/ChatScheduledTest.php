<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatScheduledMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Scheduled messages: written now, sent at their time by `chat:send-scheduled`.
 */
class ChatScheduledTest extends TestCase
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

        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
    }

    public function test_a_scheduled_message_goes_out_at_its_time(): void
    {
        $chat = $this->direct($this->alice, $this->bob);

        $this->as($this->alice);
        $id = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", [
            'body' => 'Happy birthday! 🎂', 'send_at' => now()->addHour()->toIso8601String(), 'silent' => true,
        ], $this->headers())->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", $this->headers())->assertOk()->assertJsonCount(1, 'data');

        // Not yet.
        $this->artisan('chat:send-scheduled')->assertSuccessful();
        $this->assertSame(0, ChatMessage::query()->where('body', 'Happy birthday! 🎂')->count());

        $this->travel(61)->minutes();
        $this->artisan('chat:send-scheduled')->assertSuccessful();

        // It became a normal message from Alice, with the scheduled id as its id.
        $message = ChatMessage::query()->where('uuid', $id)->firstOrFail();
        $this->assertSame('Happy birthday! 🎂', $message->body);
        $this->assertTrue($message->is_silent);
        $this->assertTrue($message->isFrom('user', $this->alice->id));
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", $this->headers())->assertOk()->assertJsonCount(0, 'data');

        // Running again sends nothing twice.
        $this->artisan('chat:send-scheduled')->assertSuccessful();
        $this->assertSame(1, ChatMessage::query()->where('body', 'Happy birthday! 🎂')->count());
    }

    public function test_the_time_must_be_ahead_and_within_a_year(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->alice);

        foreach ([now()->subMinute(), now()->addDays(400)] as $when) {
            $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", ['body' => 'x', 'send_at' => $when->toIso8601String()], $this->headers())
                ->assertUnprocessable()->assertJsonPath('error_code', 'chat_schedule_time_invalid');
        }
    }

    public function test_change_send_now_and_cancel(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->alice);

        $first = $this->schedule($chat, 'draft', now()->addDay());
        $this->patchJson("/api/mobile/v1/chat/scheduled/{$first}", ['body' => 'final', 'send_at' => now()->addDays(2)->toIso8601String()], $this->headers())
            ->assertOk()->assertJsonPath('data.body', 'final');

        // Send now: no waiting.
        $this->postJson("/api/mobile/v1/chat/scheduled/{$first}/send", [], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $first)->assertJsonPath('data.body', 'final');

        $second = $this->schedule($chat, 'never mind', now()->addDay());
        $this->deleteJson("/api/mobile/v1/chat/scheduled/{$second}", [], $this->headers())->assertOk();
        $this->deleteJson("/api/mobile/v1/chat/scheduled/{$second}", [], $this->headers())->assertNotFound();

        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_the_author_sees_and_changes_them(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->alice);
        $id = $this->schedule($chat, 'surprise', now()->addDay());

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", $this->headers())->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/mobile/v1/chat/scheduled/{$id}", ['body' => 'spoiled'], $this->headers())->assertNotFound();
        $this->deleteJson("/api/mobile/v1/chat/scheduled/{$id}", [], $this->headers())->assertNotFound();
    }

    public function test_one_that_can_no_longer_be_sent_stays_as_failed_with_the_reason(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->alice);
        $id = $this->schedule($chat, 'see you', now()->addHour());

        // Bob blocks Alice before it goes out.
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/chat/blocks', ['participant_id' => $this->alice->id], $this->headers())->assertOk();

        $this->travel(2)->hours();
        $this->artisan('chat:send-scheduled')->assertSuccessful();

        $row = ChatScheduledMessage::query()->where('uuid', $id)->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertSame('chat_blocked', $row->error_code);

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", $this->headers())->assertOk()
            ->assertJsonPath('data.0.status', 'failed')->assertJsonPath('data.0.error_code', 'chat_blocked');

        // Editing a failed one queues it again, a minute from now.
        $this->patchJson("/api/mobile/v1/chat/scheduled/{$id}", ['body' => 'see you!'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertTrue($row->refresh()->send_at->isFuture());
    }

    // ================================================================ helpers

    private function schedule(string $chat, string $body, CarbonInterface $when): string
    {
        return $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/scheduled", ['body' => $body, 'send_at' => $when->toIso8601String()], $this->headers())
            ->assertCreated()->json('data.id');
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
}
