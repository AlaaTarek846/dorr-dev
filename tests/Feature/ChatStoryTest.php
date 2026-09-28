<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\ChatStory;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Stories: who sees them (contacts / except / only, blocks), views and read receipts, reactions,
 * replies into the chat, expiry and the admin switch.
 */
class ChatStoryTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->alice = $this->makeUser('Alice', '+966500000001');
        $this->bob = $this->makeUser('Bob', '+966500000002');
        $this->carol = $this->makeUser('Carol', '+966500000003');

        // Alice saved Bob and Carol.
        $this->saveContact($this->alice, $this->bob);
        $this->saveContact($this->alice, $this->carol);

        Storage::fake('public');
    }

    public function test_my_contacts_see_my_story_and_strangers_do_not(): void
    {
        $this->postStory($this->alice, 'Hello world')->assertCreated();

        $this->as($this->bob);
        $feed = $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->assertOk();
        $this->assertCount(1, $feed->json('data.recent'));
        $this->assertSame('Hello world', $feed->json('data.recent.0.stories.0.body'));
        $this->assertFalse($feed->json('data.recent.0.all_seen'));

        $stranger = $this->makeUser('Dan', '+966500000004');
        $this->as($stranger);
        $this->assertCount(0, $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->json('data.recent'));

        $this->as($this->alice);
        $this->assertCount(1, $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->json('data.mine.stories'));
    }

    public function test_except_and_only_lists(): void
    {
        $this->as($this->alice);
        $this->putJson('/api/mobile/v1/chat/stories/privacy', ['audience' => 'except', 'except' => [$this->carol->id]], $this->headers())
            ->assertOk()->assertJsonPath('data.audience', 'except')->assertJsonPath('data.except.0.id', $this->carol->id);
        $this->postStory($this->alice, 'Not for Carol')->assertCreated();

        $this->assertCount(1, $this->feedOf($this->bob)['recent']);
        $this->assertCount(0, $this->feedOf($this->carol)['recent']);

        // The audience is frozen when posting: switching to "only Carol" doesn't expose the old story to her.
        $this->as($this->alice);
        $this->putJson('/api/mobile/v1/chat/stories/privacy', ['audience' => 'only', 'only' => [$this->carol->id]], $this->headers())->assertOk();
        $this->assertCount(0, $this->feedOf($this->carol)['recent']);

        $this->postStory($this->alice, 'Only Carol')->assertCreated();
        $this->assertCount(1, $this->feedOf($this->carol)['recent']);
        $this->assertCount(1, $this->feedOf($this->bob)['recent'][0]['stories']);
    }

    public function test_views_are_counted_and_hidden_when_receipts_are_off(): void
    {
        $id = $this->postStory($this->alice, 'Seen?')->json('data.id');

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/stories/{$id}/view", [], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/stories/{$id}/view", [], $this->headers())->assertOk(); // idempotent
        $this->assertTrue($this->feedOf($this->bob)['recent'][0]['all_seen']);

        $this->as($this->carol);
        $this->patchJson('/api/mobile/v1/chat/privacy', ['read_receipts' => false], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/stories/{$id}/view", [], $this->headers())->assertOk();

        $this->as($this->alice);
        $viewers = $this->getJson("/api/mobile/v1/chat/stories/{$id}/viewers", $this->headers())->assertOk()->json('data');
        $this->assertCount(1, $viewers);
        $this->assertSame($this->bob->id, $viewers[0]['viewer']['id']);
        $this->assertSame(1, $this->feedOf($this->alice)['mine']['stories'][0]['views']);

        // Someone else's viewers list is not mine to see.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/stories/{$id}/viewers", $this->headers())->assertNotFound();
    }

    public function test_reaction_and_reply_land_with_the_owner(): void
    {
        $id = $this->postStory($this->alice, 'React to me')->json('data.id');

        $this->as($this->bob);
        $this->putJson("/api/mobile/v1/chat/stories/{$id}/reaction", ['emoji' => '🔥'], $this->headers())->assertOk();
        $reply = $this->postJson("/api/mobile/v1/chat/stories/{$id}/reply", ['body' => 'Nice!'], $this->headers())->assertCreated();
        $this->assertSame('story_reply', $reply->json('data.type'));
        $this->assertSame($id, $reply->json('data.meta.story_id'));
        $this->assertSame('React to me', $reply->json('data.meta.text'));

        $this->as($this->alice);
        $viewers = $this->getJson("/api/mobile/v1/chat/stories/{$id}/viewers", $this->headers())->json('data');
        $this->assertSame('🔥', $viewers[0]['reaction']);
        $this->assertSame(1, ChatMessage::query()->where('type', 'story_reply')->count());
    }

    public function test_replies_can_be_turned_off(): void
    {
        $id = $this->postStory($this->alice, 'No replies', ['allow_replies' => false])->json('data.id');

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/stories/{$id}/reply", ['body' => 'Hi'], $this->headers())
            ->assertStatus(403)->assertJsonPath('error_code', 'chat_story_replies_off');
    }

    public function test_blocked_people_and_strangers_cannot_open_a_story(): void
    {
        $id = $this->postStory($this->alice, 'Private')->json('data.id');

        $this->as($this->alice);
        $this->postJson('/api/mobile/v1/chat/blocks', ['participant_id' => $this->bob->id], $this->headers())->assertOk();

        $this->as($this->bob);
        $this->assertCount(0, $this->feedOf($this->bob)['recent']);
        $this->postJson("/api/mobile/v1/chat/stories/{$id}/view", [], $this->headers())->assertNotFound();
    }

    public function test_media_stories_and_video_length_limit(): void
    {
        $this->as($this->alice);
        $this->post('/api/mobile/v1/chat/stories', ['type' => 'image', 'body' => 'caption', 'file' => UploadedFile::fake()->image('s.jpg')], $this->headers() + ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertNotNull($this->feedOf($this->bob)['recent'][0]['stories'][0]['media']['url']);

        $this->as($this->alice);
        $this->post('/api/mobile/v1/chat/stories', [
            'type' => 'video', 'duration_ms' => 61000,
            'file' => UploadedFile::fake()->create('v.mp4', 500, 'video/mp4'),
        ], $this->headers() + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error_code', 'chat_story_video_too_long');
    }

    public function test_stories_expire_and_are_purged(): void
    {
        $this->postStory($this->alice, 'Tomorrow gone')->assertCreated();

        $this->travel(25)->hours();
        $this->assertCount(0, $this->feedOf($this->bob)['recent']);

        $this->artisan('chat:purge')->assertSuccessful();
        $this->assertSame(0, ChatStory::query()->count());
    }

    public function test_muted_people_move_to_their_own_list_and_admin_can_switch_stories_off(): void
    {
        $this->postStory($this->alice, 'Muted?')->assertCreated();

        $this->as($this->bob);
        $feed = $this->postJson('/api/mobile/v1/chat/stories/mute', ['participant_id' => $this->alice->id, 'muted' => true], $this->headers())->assertOk()->json('data');
        $this->assertCount(0, $feed['recent']);
        $this->assertCount(1, $feed['muted']);

        ChatSetting::current()->update(['stories_enabled' => false]);
        $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->assertStatus(403)->assertJsonPath('error_code', 'chat_stories_disabled');
    }

    // ================================================================ helpers

    private function makeUser(string $name, string $phone): User
    {
        return User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function saveContact(User $owner, User $contact): void
    {
        ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
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

    private function postStory(User $owner, string $text, array $extra = []): TestResponse
    {
        $this->as($owner);

        return $this->postJson('/api/mobile/v1/chat/stories', ['type' => 'text', 'body' => $text, 'style' => ['background' => 'sunset', 'font' => 'bold']] + $extra, $this->headers());
    }

    /**
     * @return array<string, mixed>
     */
    private function feedOf(User $viewer): array
    {
        $this->as($viewer);

        return $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->assertOk()->json('data');
    }
}
