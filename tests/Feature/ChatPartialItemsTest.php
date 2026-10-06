<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * The partial chat items, closed: folder colours (1), favourites folders (24), searching what was
 * said in voice notes (20) and by kind (21), photos by date and files by kind / size (17, 18), a
 * PIN for one chat (25), @usernames (81), the priority inbox (114), "what I missed" (123), money
 * in a chat (66), the decision room (153) and the privacy center (127).
 */
class ChatPartialItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

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
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice], [$this->alice, $this->carol], [$this->carol, $this->alice], [$this->bob, $this->carol], [$this->carol, $this->bob]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }

        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Meet me at the blue door'])]);
    }

    public function test_folders_have_a_colour_and_starred_messages_go_into_my_favourites_folders(): void
    {
        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/folders', ['name' => 'Work', 'color' => '#2563EB', 'emoji' => '💼'], $this->headers())
            ->assertCreated()->assertJsonPath('data.color', '#2563EB')->assertJsonPath('data.emoji', '💼');

        $chat = $this->direct($this->alice, $this->bob);
        $recipe = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Grandma\'s recipe'])->json('data.id');
        $plain = $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'Nice one'])->json('data.id');

        $this->as($this->alice);
        $folder = $this->postJson('/api/mobile/v1/chat/star-folders', ['name' => 'Recipes', 'emoji' => '🍲'], $this->headers())->assertCreated()->json('data.id');
        $this->putJson("/api/mobile/v1/chat/messages/{$recipe}/star", ['starred' => true, 'folder_id' => $folder], $this->headers())->assertOk();
        $this->putJson("/api/mobile/v1/chat/messages/{$plain}/star", ['starred' => true], $this->headers())->assertOk();

        $this->getJson("/api/mobile/v1/chat/messages/starred?folder={$folder}", $this->headers())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recipe);
        $this->getJson('/api/mobile/v1/chat/messages/starred?folder=none', $this->headers())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plain);
        $this->getJson('/api/mobile/v1/chat/star-folders', $this->headers())->assertJsonPath('data.folders.0.count', 1)->assertJsonPath('data.unsorted_count', 1);

        // Someone else's folder can't be used.
        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$recipe}/star", ['starred' => true, 'folder_id' => $folder], $this->headers())->assertNotFound();
    }

    public function test_search_finds_what_was_said_in_voice_notes_i_transcribed_and_filters_by_kind(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $voice = $this->send($this->bob, $chat, ['type' => 'voice', 'duration_ms' => 3000, 'files' => [UploadedFile::fake()->create('note.m4a', 40, 'audio/mp4')]])->json('data.id');
        $this->send($this->bob, $chat, ['type' => 'image', 'files' => [UploadedFile::fake()->image('p.jpg')]])->assertCreated();
        $this->send($this->bob, $chat, ['type' => 'text', 'body' => 'see https://dorr.app'])->assertCreated();

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages/search?q=blue door", $this->headers())->assertJsonCount(0, 'data');
        $this->postJson("/api/mobile/v1/chat/messages/{$voice}/transcribe", [], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages/search?q=blue door", $this->headers())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $voice);
        // Bob never transcribed it: his search doesn't see it.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages/search?q=blue door", $this->headers())->assertJsonCount(0, 'data');

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages/search?types[]=image", $this->headers())->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'image');
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages/search?types[]=link&types[]=voice", $this->headers())->assertJsonCount(2, 'data');
    }

    public function test_photos_by_date_and_files_by_kind_and_size(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->travelTo(Carbon::parse('2026-09-01 10:00'));
        $old = $this->send($this->bob, $chat, ['type' => 'image', 'files' => [UploadedFile::fake()->image('old.jpg')]])->json('data.id');
        $pdf = $this->send($this->bob, $chat, ['type' => 'document', 'files' => [UploadedFile::fake()->createWithContent('big.pdf', str_repeat('x', 1200000))]])->json('data.id');
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $this->send($this->bob, $chat, ['type' => 'image', 'files' => [UploadedFile::fake()->image('new.jpg')]])->assertCreated();
        $zip = $this->send($this->bob, $chat, ['type' => 'document', 'files' => [UploadedFile::fake()->create('small.zip', 10, 'application/zip')]])->json('data.id');

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/gallery?kind=media&date=2026-09-15", $this->headers())->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.id', $old);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/gallery?kind=documents&file_kind=pdf", $this->headers())->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.id', $pdf);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/gallery?kind=documents&file_kind=archive", $this->headers())->assertJsonPath('data.messages.0.id', $zip);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/gallery?kind=documents&min_size=1000000", $this->headers())->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.id', $pdf);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/gallery?kind=documents&sort=size", $this->headers())->assertJsonPath('data.messages.0.id', $pdf)->assertJsonPath('data.has_more', false);
    }

    public function test_a_chat_can_have_its_own_pin_with_a_lockout(): void
    {
        $chat = $this->direct($this->alice, $this->bob);
        $this->as($this->alice);
        $this->putJson("/api/mobile/v1/chat/conversations/{$chat}/lock-pin", ['pin' => '2468'], $this->headers())->assertOk()->assertJsonPath('data.is_locked', true);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertJsonPath('data.has_lock_pin', true);

        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/unlock", ['pin' => '2468'], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/unlock", ['pin' => '0000'], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_lock_pin_wrong');
        foreach (range(1, 3) as $_) {
            $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/unlock", ['pin' => '0000'], $this->headers());
        }
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/unlock", ['pin' => '0000'], $this->headers())->assertStatus(429);
        // Locked out: even the right PIN waits.
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/unlock", ['pin' => '2468'], $this->headers())->assertStatus(429);
        $this->travel(6)->minutes();
        $this->deleteJson("/api/mobile/v1/chat/conversations/{$chat}/lock-pin", ['pin' => '2468'], $this->headers())->assertOk()->assertJsonPath('data.has_lock_pin', false);
    }

    public function test_people_find_me_by_my_username_without_my_number(): void
    {
        $this->as($this->alice);
        $this->putJson('/api/mobile/v1/chat/username', ['username' => '@Alice_Dorr'], $this->headers())->assertOk()->assertJsonPath('data.username', 'alice_dorr');
        $this->putJson('/api/mobile/v1/chat/username', ['username' => '1bad'], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_username_invalid');

        $this->as($this->bob);
        $this->putJson('/api/mobile/v1/chat/username', ['username' => 'alice_dorr'], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_username_taken');
        $this->getJson('/api/mobile/v1/chat/users/by-username?u=@alice_dorr', $this->headers())
            ->assertOk()->assertJsonPath('data.id', $this->alice->id)->assertJsonPath('data.username', 'alice_dorr')->assertJsonPath('data.phone', null);
        $this->getJson('/api/mobile/v1/chat/users/by-username?u=nobody_here', $this->headers())->assertNotFound();
    }

    public function test_priority_inbox_what_i_missed_and_money_in_a_chat(): void
    {
        $withBob = $this->direct($this->alice, $this->bob);
        $withCarol = $this->direct($this->alice, $this->carol);
        $this->send($this->carol, $withCarol, ['type' => 'text', 'body' => 'hey'])->assertCreated();
        $mine = $this->send($this->alice, $withBob, ['type' => 'text', 'body' => 'Did you get the keys?'])->json('data.id');
        $this->send($this->bob, $withBob, ['type' => 'text', 'body' => 'Yes, here', 'reply_to' => $mine])->assertCreated();
        $this->send($this->bob, $withBob, ['type' => 'money_request', 'amount_minor' => 5000, 'body' => 'Dinner'])->assertCreated();

        $this->as($this->alice);
        $rows = collect($this->getJson('/api/mobile/v1/chat/conversations?filter=priority', $this->headers())->assertOk()->json('data'));
        $this->assertSame(2, $rows->count());
        $this->assertContains('direct', $rows->first()['priority']);

        $this->getJson('/api/mobile/v1/chat/catch-up', $this->headers())->assertOk()
            ->assertJsonPath('data.replies.0.chat', 'Bob')
            ->assertJsonPath('data.replies.0.excerpt', 'Yes, here');

        $this->getJson("/api/mobile/v1/chat/conversations/{$withBob}/money", $this->headers())->assertOk()
            ->assertJsonPath('data.items.0.type', 'money_request')
            ->assertJsonPath('data.totals.i_owe_minor', 5000);
    }

    public function test_the_decision_room_and_the_privacy_center(): void
    {
        $this->as($this->alice);
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Trip', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
        $source = $this->send($this->bob, $group, ['type' => 'text', 'body' => 'Where do we go this summer?'])->json('data.id');
        $this->as($this->alice);
        $decision = $this->postJson("/api/mobile/v1/chat/messages/{$source}/decision", [
            'title' => 'Summer trip', 'options' => ['Alexandria', 'Dahab'], 'description' => 'Budget is 3000 each', 'deadline_at' => now()->addDays(3)->toIso8601String(),
        ], $this->headers())->assertCreated()->assertJsonPath('data.description', 'Budget is 3000 each')->json('data');

        $this->as($this->bob);
        $option = $decision['options'][1]['id'];
        $this->postJson("/api/mobile/v1/chat/decisions/{$decision['id']}/arguments", ['stance' => 'pro', 'text' => 'Diving!', 'option_id' => (string) $option], $this->headers())
            ->assertCreated()->assertJsonPath('data.arguments.0.text', 'Diving!')->assertJsonPath('data.arguments.0.is_mine', true)->assertJsonPath('data.can_decide', false);
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/decisions/{$decision['id']}", $this->headers())->assertJsonPath('data.can_decide', true)->assertJsonPath('data.arguments_count', 1);

        // After the deadline: closed.
        $this->travel(4)->days();
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/decisions/{$decision['id']}/arguments", ['stance' => 'con', 'text' => 'Too late'], $this->headers())->assertStatus(409);

        $this->getJson('/api/mobile/v1/chat/privacy/center', $this->headers())->assertOk()
            ->assertJsonPath('data.who_can.message', 'everyone')
            ->assertJsonPath('data.locked_chats', 0)
            ->assertJsonStructure(['data' => ['who_sees', 'notifications', 'circles', 'ai' => ['reads']]]);
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

    private function send(User $sender, string $conversation, array $data): \Illuminate\Testing\TestResponse
    {
        $this->as($sender);

        return $this->post("/api/mobile/v1/chat/conversations/{$conversation}/messages", $data, $this->headers() + ['Accept' => 'application/json']);
    }
}
