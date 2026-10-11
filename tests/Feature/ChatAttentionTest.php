<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * My communication rules (spec 146, 151, 155): what interrupts me now, what waits for the
 * summary, what waits silently; the context I'm in; the people who always get through; the hours
 * calls ring. Only notifications change — every message still arrives.
 */
class ChatAttentionTest extends TestCase
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
        // Bob has Alice in his contacts, not Carol.
        foreach ([[$this->bob, $this->alice], [$this->alice, $this->bob], [$this->alice, $this->carol], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->bob->id, 'player_id' => 'bob-phone', 'platform' => 'android']);
        $this->travelTo(Carbon::parse('2026-10-20 09:00', 'UTC'));
    }

    public function test_by_default_everything_interrupts_and_my_choices_send_a_source_to_the_summary_or_to_wait(): void
    {
        $group = $this->group();
        $this->say($this->alice, $group, 'first');
        $this->assertCount(1, $this->pushes('chat.message.sent'));

        // Groups in the summary; channels... and strangers wait silently.
        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/attention', ['attention' => ['groups' => 'digest', 'strangers' => 'later'], 'digest_minutes' => 30], $this->headers())
            ->assertOk()->assertJsonPath('data.attention.groups', 'digest')->assertJsonPath('data.attention.contacts', 'now')->assertJsonPath('data.digest_minutes', 30);
        $this->say($this->alice, $group, 'second');
        $this->say($this->carol, $group, 'third');
        $this->assertCount(1, $this->pushes('chat.message.sent'));

        // A stranger's direct message: no notification, only the counter (hidden).
        $this->say($this->carol, $this->direct($this->carol, $this->bob), 'hi bob');
        $hidden = collect($this->pushes('chat.message.sent'))->last();
        $this->assertSame('1', $hidden['data']['hidden'] ?? null);

        // 30 minutes after the first one waiting: one summary, once.
        $this->travel(29)->minutes();
        $this->artisan('chat:attention-digest');
        $this->assertCount(0, $this->pushes('chat.attention.digest'));
        $this->travel(2)->minutes();
        $this->artisan('chat:attention-digest');
        $this->artisan('chat:attention-digest');
        $digest = $this->pushes('chat.attention.digest');
        $this->assertCount(1, $digest);
        $this->assertSame('2 messages in 1 chats waited for you', $digest[0]['contents']['en']);
    }

    public function test_a_context_shifts_attention_and_disclosure_and_people_i_chose_always_get_through(): void
    {
        $direct = $this->direct($this->alice, $this->bob);

        // In a meeting, for an hour: a contact's message waits for the summary.
        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/attention/context', ['context' => 'meeting', 'minutes' => 60], $this->headers())->assertOk()->assertJsonPath('data.context.key', 'meeting');
        $this->say($this->alice, $direct, 'quick question');
        $this->assertCount(0, $this->pushes('chat.message.sent'));

        // Alice always gets through: now, with her words.
        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/attention', ['always_through' => ['user:'.$this->alice->id]], $this->headers())->assertOk()->assertJsonPath('data.always_through.0.name', 'Alice');
        $this->say($this->alice, $direct, 'it is urgent-ish');
        $this->assertSame('it is urgent-ish', collect($this->pushes('chat.message.sent'))->last()['contents']['en']);

        // Private: everything interrupts, but shows nothing.
        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/attention', ['always_through' => []], $this->headers())->assertOk();
        $this->putJson('/api/mobile/v1/chat/attention/context', ['context' => 'private'], $this->headers())->assertOk();
        $this->say($this->alice, $direct, 'secret words');
        $last = collect($this->pushes('chat.message.sent'))->last();
        $this->assertSame('1', $last['data']['private'] ?? null);
        $this->assertStringNotContainsString('secret', json_encode($last));

        // My own change to a context wins over its preset; and a context runs out.
        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/attention', ['contexts' => ['work' => ['disclosure' => 'all', 'calls' => 'nobody']]], $this->headers())
            ->assertOk()->assertJsonPath('data.contexts.1.key', 'work')->assertJsonPath('data.contexts.1.calls', 'nobody')->assertJsonPath('data.contexts.1.custom', true);
        $this->putJson('/api/mobile/v1/chat/attention/context', ['context' => 'work', 'minutes' => 30], $this->headers())->assertOk();
        $this->travel(31)->minutes();
        $this->getJson('/api/mobile/v1/chat/attention', $this->headers())->assertOk()->assertJsonPath('data.context', null);
    }

    public function test_calls_ring_only_inside_my_call_hours_except_for_people_i_chose(): void
    {
        $direct = $this->direct($this->alice, $this->bob);
        $this->as($this->bob);
        // 09:00 UTC is 12:00 in Riyadh: outside 14:00–22:00.
        $this->putJson('/api/mobile/v1/chat/attention', ['call_hours' => ['from' => '14:00', 'to' => '22:00', 'timezone' => 'Asia/Riyadh']], $this->headers())->assertOk();

        $this->as($this->alice);
        $call = $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/calls", ['type' => 'audio'], $this->headers())->assertCreated();
        $this->assertCount(0, $this->pushes('chat.call.ringing'));
        $this->postJson("/api/mobile/v1/chat/calls/{$call->json('data.call.id')}/leave", [], $this->headers());

        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/attention', ['always_through' => ['user:'.$this->alice->id]], $this->headers())->assertOk();
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/calls", ['type' => 'audio'], $this->headers())->assertCreated();
        $this->assertCount(1, $this->pushes('chat.call.ringing'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<array<string, mixed>> */
    private function pushes(string $event): array
    {
        app(DeferredCallbackCollection::class)->invoke();

        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'onesignal') && ($r['data']['event'] ?? null) === $event)
            ->map(fn ($r) => $r->data())->values()->all();
    }

    private function say(User $who, string $conversation, string $text): void
    {
        $this->as($who);
        $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", ['type' => 'text', 'body' => $text], $this->headers())->assertCreated();
    }

    private function group(): string
    {
        $this->as($this->alice);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Team', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
    }

    private function direct(User $me, User $other): string
    {
        $this->as($me);
        $id = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $other->id], $this->headers())->assertOk()->json('data.id');
        // Accept the request on the other side (strangers write as a request first).
        $this->as($other);
        $this->postJson("/api/mobile/v1/chat/conversations/{$id}/accept", [], $this->headers());

        return $id;
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
