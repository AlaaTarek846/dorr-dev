<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatPersonalMoment;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * DORR Moments together and kept: a group card everyone signs (spec 163) and capsules (166).
 */
class ChatMomentsTogetherTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private User $dave;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        $this->dave = $make('Dave', '+966500000004');
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'n1'])]);
        NotificationDevice::query()->create(['owner_type' => User::class, 'owner_id' => $this->carol->id, 'player_id' => 'carol-phone', 'platform' => 'android']);
    }

    public function test_a_group_card_everyone_signs_then_the_organiser_sends_it(): void
    {
        $eid = ChatMoment::query()->where('key', 'eid_al_fitr')->firstOrFail();

        $this->as($this->alice);
        $card = $this->postJson('/api/mobile/v1/chat/collab-cards', ['recipient_id' => $this->bob->id, 'moment_id' => $eid->id, 'members' => [$this->carol->id, $this->bob->id]], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.is_organiser', true)
            ->assertJsonCount(2, 'data.members') // Alice + Carol — never the recipient.
            ->json('data.id');

        app(DeferredCallbackCollection::class)->invoke();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'onesignal') && in_array('carol-phone', $r['include_subscription_ids'] ?? $r['include_player_ids'] ?? [], true));

        // The recipient can't see it; a stranger can't either.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/collab-cards/{$card}", $this->headers())->assertNotFound();
        $this->as($this->dave);
        $this->postJson("/api/mobile/v1/chat/collab-cards/{$card}/contribution", ['text' => 'hi'], $this->headers())->assertNotFound();

        // Nobody signed yet → can't send.
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/collab-cards/{$card}/send", [], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_collab_empty');

        $this->as($this->carol);
        $this->getJson('/api/mobile/v1/chat/collab-cards', $this->headers())->assertOk()->assertJsonCount(1, 'data');
        $this->post("/api/mobile/v1/chat/collab-cards/{$card}/contribution", ['text' => 'Eid Mubarak Bob!', 'voice' => UploadedFile::fake()->create('c.m4a', 50, 'audio/mp4')], $this->headers() + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.mine.signed', true);
        // Only the organiser sends.
        $this->postJson("/api/mobile/v1/chat/collab-cards/{$card}/send", [], $this->headers())->assertForbidden();

        $this->travel(1)->minutes();
        $this->as($this->alice);
        $this->post("/api/mobile/v1/chat/collab-cards/{$card}/contribution", ['text' => 'From all of us', 'photo' => UploadedFile::fake()->image('a.jpg', 300, 300)], $this->headers() + ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/mobile/v1/chat/collab-cards/{$card}/send", [], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.type', 'moment_card')
            ->assertJsonPath('data.meta.card.collab', true)
            ->assertJsonCount(2, 'data.meta.card.contributions')
            ->assertJsonPath('data.meta.card.contributions.0.text', 'Eid Mubarak Bob!')
            ->assertJsonPath('data.meta.card.contributions.0.files.0.kind', 'voice')
            ->assertJsonPath('data.meta.card.contributions.1.files.0.attachment', 1)
            ->assertJsonCount(2, 'data.attachments');

        // Sent once — afterwards it's closed.
        $this->postJson("/api/mobile/v1/chat/collab-cards/{$card}/send", [], $this->headers())->assertStatus(409);
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/collab-cards/{$card}/contribution", ['text' => 'late'], $this->headers())->assertStatus(409);
        $this->assertSame(1, ChatMessage::query()->where('type', 'moment_card')->count());
    }

    public function test_a_capsule_keeps_a_copy_of_what_i_choose_and_only_mine(): void
    {
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->assertOk()->json('data.id');

        $this->as($this->bob);
        $photo = $this->post("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'image', 'body' => 'Eid morning', 'files' => [UploadedFile::fake()->image('eid.jpg', 300, 300)]], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->as($this->alice);
        $capsule = $this->postJson('/api/mobile/v1/chat/capsules', ['title' => 'Eid 2026', 'emoji' => '🌙'], $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/mobile/v1/chat/capsules/{$capsule}/items", ['message_id' => $photo, 'note' => 'the best one'], $this->headers())
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.text', 'Eid morning')
            ->assertJsonPath('data.items.0.sender_name', 'Bob')
            ->assertJsonPath('data.items.0.note', 'the best one')
            ->assertJsonCount(1, 'data.items.0.files');
        // Adding the same message twice keeps one copy.
        $this->postJson("/api/mobile/v1/chat/capsules/{$capsule}/items", ['message_id' => $photo], $this->headers())->assertJsonCount(1, 'data.items');

        // The copy stays after the chat's message is deleted for everyone.
        $this->as($this->bob);
        $this->deleteJson("/api/mobile/v1/chat/messages/{$photo}", ['for_everyone' => true], $this->headers());
        $this->as($this->alice);
        $item = $this->getJson("/api/mobile/v1/chat/capsules/{$capsule}", $this->headers())->assertOk()->assertJsonCount(1, 'data.items.0.files')->json('data.items.0.id');
        $this->getJson('/api/mobile/v1/chat/capsules', $this->headers())->assertJsonPath('data.0.items_count', 1);

        // Someone else's capsule, or a message I can't see, are off limits.
        $this->as($this->dave);
        $this->getJson("/api/mobile/v1/chat/capsules/{$capsule}", $this->headers())->assertNotFound();
        $daves = $this->postJson('/api/mobile/v1/chat/capsules', ['title' => 'Mine'], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/capsules/{$daves}/items", ['message_id' => ChatMessage::query()->value('uuid')], $this->headers())->assertForbidden();

        $this->as($this->alice);
        $this->deleteJson("/api/mobile/v1/chat/capsules/{$capsule}/items/{$item}", [], $this->headers())->assertOk()->assertJsonCount(0, 'data.items');
        $this->deleteJson("/api/mobile/v1/chat/capsules/{$capsule}", [], $this->headers())->assertOk();
        $this->getJson('/api/mobile/v1/chat/capsules', $this->headers())->assertJsonCount(0, 'data');
    }

    public function test_my_own_dates_remind_me_before_and_on_the_day_in_my_morning(): void
    {
        $this->carol->forceFill(['timezone' => 'Asia/Riyadh'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 05:30', 'UTC')); // 08:30 in Riyadh
        $this->as($this->carol);
        $this->postJson('/api/mobile/v1/chat/moments/personal', ['kind' => 'birthday', 'title' => 'Alice', 'month' => 10, 'day' => 10, 'remind_days_before' => 2], $this->headers())->assertSuccessful();

        $reminders = function (): array {
            app(DeferredCallbackCollection::class)->invoke();

            return collect(Http::recorded())->map(fn ($pair) => $pair[0])
                ->filter(fn ($r) => str_contains($r->url(), 'onesignal') && ($r['data']['event'] ?? null) === 'chat.moment.reminder')->values()->all();
        };

        // Before 9 in her morning: nothing yet.
        $this->artisan('chat:moment-reminders')->assertSuccessful();
        $this->assertCount(0, $reminders());

        // 09:10 in Riyadh, two days before: once.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 06:10', 'UTC'));
        $this->artisan('chat:moment-reminders')->assertSuccessful();
        $this->artisan('chat:moment-reminders')->assertSuccessful();
        $sent = $reminders();
        $this->assertCount(1, $sent);
        $this->assertSame('2', $sent[0]['data']['days_left']);
        $this->assertContains('carol-phone', $sent[0]['include_subscription_ids'] ?? $sent[0]['include_player_ids'] ?? []);

        // On the day: once more.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 06:05', 'UTC'));
        $this->artisan('chat:moment-reminders')->assertSuccessful();
        $this->artisan('chat:moment-reminders')->assertSuccessful();
        $sent = $reminders();
        $this->assertCount(2, $sent);
        $this->assertSame('0', $sent[1]['data']['days_left']);

        // Moments off for her: no more reminders (next year's would be silent too).
        $this->putJson('/api/mobile/v1/chat/moments/preferences', ['enabled' => false], $this->headers())->assertOk();
        ChatPersonalMoment::query()->update(['reminded_day_for' => null]);
        $this->artisan('chat:moment-reminders')->assertSuccessful();
        $this->assertCount(2, $reminders());
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
