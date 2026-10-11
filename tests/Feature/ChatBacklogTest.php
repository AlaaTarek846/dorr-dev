<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatConversation;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * The simple items from the WhatsApp list in the spec's backlog, done on 2026-10-11.
 */
class ChatBacklogTest extends TestCase
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
        // Alice and Bob saved each other; Alice and Carol too; Carol saved Bob, Bob never saved Carol.
        foreach ([[$this->alice, $this->bob], [$this->bob, $this->alice], [$this->alice, $this->carol], [$this->carol, $this->alice], [$this->carol, $this->bob]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
        config(['chat.livekit.url' => 'wss://live.example.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => 'secret-that-is-long-enough']);
        config(['services.onesignal.app_id' => 'app', 'services.onesignal.rest_api_key' => 'rest']);
        Http::fake(['*' => Http::response(['id' => 'n1'])]);
    }

    public function test_the_chats_i_start_take_my_default_timer(): void
    {
        $this->as($this->alice);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['default_disappearing_seconds' => 604800], $this->headers())
            ->assertOk()->assertJsonPath('data.default_disappearing_seconds', 604800);

        $direct = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->assertSame(604800, ChatConversation::query()->where('uuid', $direct)->value('disappearing_seconds'));
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Trip', 'members' => [$this->bob->id]], $this->headers())->json('data.conversation.id');
        $this->assertSame(604800, ChatConversation::query()->where('uuid', $group)->value('disappearing_seconds'));

        // A chat someone else starts keeps theirs.
        $this->as($this->carol);
        $other = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->alice->id], $this->headers())->json('data.id');
        $this->assertNull(ChatConversation::query()->where('uuid', $other)->value('disappearing_seconds'));
    }

    public function test_calls_from_people_i_never_saved_do_not_ring(): void
    {
        $this->as($this->bob);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['silence_unknown_callers' => true], $this->headers())->assertOk()->assertJsonPath('data.silence_unknown_callers', true);

        // Carol (not in Bob's contacts): the call is placed, but it doesn't ring for Bob.
        $this->as($this->carol);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'hi'], $this->headers());
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'hello'], $this->headers());
        $this->as($this->carol);
        $call = $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/calls", ['type' => 'audio'], $this->headers())->assertCreated()->json('data.call.id');
        $this->assertTrue((bool) DB::table('chat_call_participants')->where('call_id', DB::table('chat_calls')->where('uuid', $call)->value('id'))->where('silenced', true)->exists());
        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/calls', $this->headers())->assertJsonPath('data.0.silenced', true);

        // Alice (saved): it rings.
        $this->as($this->alice);
        $direct = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/calls", ['type' => 'audio'], $this->headers())->assertCreated();
        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/calls', $this->headers())->assertJsonPath('data.0.silenced', false);
    }

    public function test_my_status_can_be_hidden_from_some_people(): void
    {
        $this->as($this->alice);
        $this->putJson('/api/mobile/v1/chat/status', ['emoji' => '🏖️', 'text' => 'On holiday', 'audience' => 'contacts', 'except' => ['user:'.$this->bob->id]], $this->headers())
            ->assertOk()->assertJsonPath('data.status.except', ['user:'.$this->bob->id]);

        $seen = function (User $viewer) {
            $this->as($viewer);
            $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->alice->id], $this->headers())->json('data.id');

            return $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->json('data.peer.status');
        };
        $this->assertNull($seen($this->bob));
        $this->assertSame('On holiday', $seen($this->carol)['text'] ?? null);
    }

    public function test_a_chat_is_exported_as_text_or_a_zip_with_its_files(): void
    {
        $this->as($this->alice);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'See you at 6'], $this->headers())->assertCreated();
        $this->post("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'image', 'body' => 'The view', 'files' => [UploadedFile::fake()->image('view.jpg', 50, 50)]], $this->headers())->assertCreated();
        $this->post("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'image', 'view_once' => 1, 'files' => [UploadedFile::fake()->image('secret.jpg', 50, 50)]], $this->headers())->assertCreated();

        $export = $this->get("/api/mobile/v1/chat/conversations/{$chat}/export", $this->headers())->assertOk();
        $text = file_get_contents($export->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Alice: See you at 6', $text);
        $this->assertStringContainsString('The view', $text);
        $this->assertStringContainsString(__('chat.preview.view_once'), $text);

        $zip = $this->get("/api/mobile/v1/chat/conversations/{$chat}/export?media=1", $this->headers())->assertOk();
        $this->assertStringContainsString('.zip', (string) $zip->headers->get('content-disposition'));

        // Someone outside the chat can't.
        $this->as($this->carol);
        $this->get("/api/mobile/v1/chat/conversations/{$chat}/export", $this->headers())->assertForbidden();
    }

    public function test_storage_shows_the_biggest_files_and_deletes_them_for_me_only(): void
    {
        $this->as($this->alice);
        $bobChat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $carolChat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->carol->id], $this->headers())->json('data.id');
        $big = $this->post("/api/mobile/v1/chat/conversations/{$bobChat}/messages", ['type' => 'document', 'files' => [UploadedFile::fake()->createWithContent('big.pdf', str_repeat('a', 6 * 1024 * 1024))]], $this->headers())->json('data.id');
        $this->post("/api/mobile/v1/chat/conversations/{$carolChat}/messages", ['type' => 'document', 'files' => [UploadedFile::fake()->create('small.pdf', 50, 'application/pdf')]], $this->headers());

        $this->getJson('/api/mobile/v1/chat/storage', $this->headers())->assertOk()
            ->assertJsonPath('data.files', 2)->assertJsonPath('data.large', 1)
            ->assertJsonPath('data.chats.0.conversation_id', $bobChat)->assertJsonPath('data.chats.0.title', 'Bob');
        $this->getJson('/api/mobile/v1/chat/storage/files?kind=large', $this->headers())->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.message_id', $big);
        $this->getJson("/api/mobile/v1/chat/storage/files?conversation_id={$carolChat}", $this->headers())->assertJsonCount(1, 'data.items');

        $this->postJson('/api/mobile/v1/chat/storage/delete', ['messages' => [$big]], $this->headers())->assertOk()->assertJsonPath('data.deleted', 1);
        $this->getJson('/api/mobile/v1/chat/storage', $this->headers())->assertJsonPath('data.files', 1);
        // Bob still has it.
        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/storage', $this->headers())->assertJsonPath('data.files', 1)->assertJsonPath('data.large', 1);
    }

    public function test_a_channel_shows_its_numbers_and_chooses_its_reactions(): void
    {
        $this->as($this->alice);
        $channel = $this->postJson('/api/mobile/v1/chat/channels', ['name' => 'News', 'is_public' => true], $this->headers())->assertCreated()->json('data.id');
        $post = $this->postJson("/api/mobile/v1/chat/conversations/{$channel}/messages", ['type' => 'text', 'body' => 'Big news today'], $this->headers())->json('data.id');
        foreach ([$this->bob, $this->carol] as $follower) {
            $this->as($follower);
            $this->postJson("/api/mobile/v1/chat/channels/{$channel}/follow", [], $this->headers())->assertOk();
        }
        // Bob saw the post.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/conversations/{$channel}/read", ['up_to' => $post], $this->headers());
        $this->getJson("/api/mobile/v1/chat/channels/{$channel}/stats", $this->headers())->assertForbidden();

        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/channels/{$channel}/stats?days=7", $this->headers())->assertOk()
            ->assertJsonPath('data.followers', 3)->assertJsonPath('data.joined', 3)->assertJsonPath('data.posts', 1)
            ->assertJsonPath('data.top_posts.0.id', $post);

        // Only 👍 and ❤️ from now on.
        $this->patchJson("/api/mobile/v1/chat/channels/{$channel}/reactions", ['reactions' => ['👍', '❤️']], $this->headers())->assertOk()
            ->assertJsonPath('data.group.allowed_reactions', ['👍', '❤️']);
        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/messages/{$post}/reaction", ['emoji' => '😂'], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_reaction_not_allowed');
        $this->putJson("/api/mobile/v1/chat/messages/{$post}/reaction", ['emoji' => '👍'], $this->headers())->assertOk();
    }

    public function test_a_business_card_its_numbers_a_list_from_a_label_and_a_click_to_chat_link(): void
    {
        $this->as($this->alice);
        $this->patchJson('/api/mobile/v1/chat/business', ['about' => 'Fresh bread daily', 'address' => 'King Fahd Rd', 'email' => 'shop@example.com', 'website' => 'https://shop.example.com'], $this->headers())
            ->assertOk()->assertJsonPath('data.website', 'https://shop.example.com');
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'Welcome!'], $this->headers());

        // Bob sees the card on the chat, even without opening hours.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}", $this->headers())->assertJsonPath('data.business.address', 'King Fahd Rd');
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat}/messages", ['type' => 'text', 'body' => 'Do you deliver?'], $this->headers());

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/business/stats?days=7', $this->headers())->assertOk()
            ->assertJsonPath('data.sent', 1)->assertJsonPath('data.received', 1)->assertJsonPath('data.chats', 1);

        // A label (folder) of customers becomes a broadcast list.
        $folder = $this->postJson('/api/mobile/v1/chat/folders', ['name' => 'New customers'], $this->headers())->json('data.id');
        $this->putJson("/api/mobile/v1/chat/folders/{$folder}/conversations", ['conversations' => [$chat]], $this->headers());
        $this->postJson('/api/mobile/v1/chat/broadcasts/from-folder', ['folder_id' => $folder], $this->headers())->assertCreated()
            ->assertJsonPath('data.name', 'New customers')->assertJsonCount(1, 'data.members');

        // Click to chat: a link with a message ready, that resolves back to me.
        $link = $this->getJson('/api/mobile/v1/chat/contacts/chat-link?text='.rawurlencode('Hi, I want to order'), $this->headers())->assertOk()->json('data');
        $this->assertStringContainsString('?text=Hi%2C%20I%20want%20to%20order', $link['payload']);
        $this->get(parse_url($link['link'], PHP_URL_PATH).'?text=Hi')->assertOk()->assertSee('dorr://chat/'.$link['token'].'?text=Hi', false);
        $this->as($this->bob);
        $this->postJson('/api/mobile/v1/chat/contacts/qr/resolve', ['payload' => $link['payload']], $this->headers())->assertOk();
    }

    public function test_a_voice_status_that_mentions_someone_who_shares_it_again_without_its_poster(): void
    {
        $this->as($this->alice);
        $story = $this->post('/api/mobile/v1/chat/stories', [
            'type' => 'voice', 'duration_ms' => 8000, 'style' => ['background' => '#7C3AED', 'waveform' => [10, 40, 80, 30]],
            'mentions' => [$this->bob->id],
            'file' => UploadedFile::fake()->create('voice.m4a', 20, 'audio/mp4'),
        ], $this->headers())->assertCreated()->json('data.id');

        // Bob is told in our chat, and sees it mentions him.
        $this->as($this->bob);
        $chat = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->alice->id], $this->headers())->json('data.id');
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat}/messages", $this->headers())
            ->assertJsonPath('data.messages.0.type', 'story_mention')->assertJsonPath('data.messages.0.meta.story_id', $story);
        $alice = collect($this->getJson('/api/mobile/v1/chat/stories', $this->headers())->json('data.recent'))->firstWhere('owner.name', 'Alice');
        $this->assertSame('voice', $alice['stories'][0]['type']);
        $this->assertTrue($alice['stories'][0]['mentioned_me']);
        $this->assertSame([10, 40, 80, 30], $alice['stories'][0]['style']['waveform']);

        // He shares it in his own status: it's his, and says nothing about Alice.
        $copy = $this->postJson("/api/mobile/v1/chat/stories/{$story}/reshare", [], $this->headers())->assertCreated()->json('data.id');
        $this->as($this->alice);
        $bob = collect($this->getJson('/api/mobile/v1/chat/stories', $this->headers())->json('data.recent'))->firstWhere('owner.name', 'Bob');
        $this->assertSame($copy, $bob['stories'][0]['id']);
        $this->assertTrue($bob['stories'][0]['reshared']);
        $this->assertStringNotContainsString('Alice', json_encode($bob['stories'][0]));

        // Someone it doesn't mention can't.
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/stories/{$story}/reshare", [], $this->headers())->assertForbidden()->assertJsonPath('error_code', 'chat_story_not_mentioned');
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA', 'Accept' => 'application/json'];
    }
}
