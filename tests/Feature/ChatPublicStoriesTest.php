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
use Modules\Admin\Models\Admin;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatDorrStory;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Public stories on the home page (docs/remaining_chat.md ج.1): one free each, everyone sees them
 * (unseen and most watched first), Dorr's own stories as a fixed circle, and a stranger's number
 * stays hidden until a message request is accepted.
 */
class ChatPublicStoriesTest extends TestCase
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
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        $this->dave = $make('Dave', '+966500000004');

        // Carol has Alice saved (and the other way round); Bob and Dave know nobody.
        foreach ([[$this->alice, $this->carol], [$this->carol, $this->alice]] as [$owner, $contact]) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
        }
    }

    public function test_one_free_public_story_and_the_admin_sets_how_many(): void
    {
        $this->post_($this->alice, ['type' => 'text', 'body' => 'Grand opening today!', 'public' => true])->assertCreated();
        $this->post_($this->alice, ['type' => 'text', 'body' => 'And another', 'public' => true])
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_public_story_limit')->assertJsonPath('data.max', 1);

        // A normal story isn't counted.
        $this->post_($this->alice, ['type' => 'text', 'body' => 'just for my contacts'])->assertCreated();

        ChatSetting::current()->update(['public_stories_free' => 2]);
        $this->post_($this->alice, ['type' => 'text', 'body' => 'And another', 'public' => true])->assertCreated();

        $this->as($this->alice);
        $this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->assertOk()
            ->assertJsonPath('data.quota.free', 2)->assertJsonPath('data.quota.used', 2)
            ->assertJsonCount(2, 'data.mine.stories')->assertJsonPath('data.mine.stories.0.is_public', true);

        // Switched off by the admin: nobody posts public ones.
        ChatSetting::current()->update(['public_stories_enabled' => false]);
        $this->post_($this->bob, ['type' => 'text', 'body' => 'hi', 'public' => true])->assertForbidden()->assertJsonPath('error_code', 'chat_public_stories_disabled');
    }

    public function test_everyone_sees_them_without_the_number_unless_we_already_talk(): void
    {
        $this->post_($this->alice, ['type' => 'text', 'body' => 'Grand opening!', 'public' => true])->assertCreated();

        // Bob (a stranger): her name and story, no number. It isn't in his chat's stories.
        $this->as($this->bob);
        $feed = $this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->assertOk();
        $feed->assertJsonPath('data.people.0.owner.name', 'Alice')->assertJsonPath('data.people.0.owner.phone', null);
        $this->getJson('/api/mobile/v1/chat/stories', $this->headers())->assertOk()->assertJsonCount(0, 'data.recent');

        // He can watch it (a view counts) and react.
        $story = $feed->json('data.people.0.stories.0.id');
        $this->postJson("/api/mobile/v1/chat/stories/{$story}/view", [], $this->headers())->assertOk();

        // Carol (saved her): the number shows.
        $this->as($this->carol);
        $this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->assertJsonPath('data.people.0.owner.phone', '+966500000001');
    }

    public function test_a_message_from_a_public_story_is_a_request_and_the_number_shows_once_accepted(): void
    {
        $this->post_($this->alice, ['type' => 'text', 'body' => 'Grand opening!', 'public' => true])->assertCreated();

        $this->as($this->bob);
        $story = $this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->json('data.people.0.stories.0.id');
        $this->postJson("/api/mobile/v1/chat/stories/{$story}/reply", ['body' => 'Where is the shop?'], $this->headers())->assertCreated();

        $chat = collect($this->getJson('/api/mobile/v1/chat/conversations', $this->headers())->json('data'))->first();
        $this->assertSame('pending', $chat['status']);
        $this->assertNull($chat['peer']['phone']);

        // Alice gets it in her message requests and accepts it: now Bob sees her number.
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/conversations/{$chat['id']}/accept", [], $this->headers())->assertOk();

        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/conversations/{$chat['id']}", $this->headers())->assertOk()
            ->assertJsonPath('data.peer.phone', '+966500000001');
        $this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->assertJsonPath('data.people.0.owner.phone', '+966500000001');
    }

    public function test_unseen_and_most_watched_first_seen_last_muted_and_blocked_left_out(): void
    {
        $quiet = $this->post_($this->alice, ['type' => 'text', 'body' => 'Alice', 'public' => true])->json('data.id');
        $popular = $this->post_($this->bob, ['type' => 'text', 'body' => 'Bob', 'public' => true])->json('data.id');
        $this->post_($this->carol, ['type' => 'text', 'body' => 'Carol', 'public' => true])->assertCreated();

        // Bob's story was watched (and liked) by two people.
        foreach ([$this->alice, $this->carol] as $viewer) {
            $this->as($viewer);
            $this->putJson("/api/mobile/v1/chat/stories/{$popular}/reaction", ['emoji' => '❤️'], $this->headers())->assertOk();
        }

        $this->as($this->dave);
        $names = fn () => collect($this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->json('data.people'))->pluck('owner.name')->all();
        $this->assertSame('Bob', $names()[0]);

        // Seen goes to the back.
        $this->postJson("/api/mobile/v1/chat/stories/{$popular}/view", [], $this->headers())->assertOk();
        $this->assertSame('Bob', last($names()));

        // Hiding Alice's stories (like WhatsApp) takes her off; so does a block.
        $this->postJson('/api/mobile/v1/chat/stories/mute', ['participant_id' => $this->alice->id, 'muted' => true], $this->headers())->assertOk();
        $this->postJson('/api/mobile/v1/chat/blocks', ['participant_id' => $this->carol->id], $this->headers())->assertOk();
        $this->assertSame(['Bob'], $names());
        $this->postJson("/api/mobile/v1/chat/stories/{$quiet}/view", [], $this->headers())->assertOk(); // muted ≠ forbidden
    }

    public function test_dorrs_own_stories_are_one_circle_and_count_their_views(): void
    {
        $this->asAdmin(['chat-dorr-stories.create', 'chat-dorr-stories.view']);
        $id = $this->post('/api/admin/v1/chat-dorr-stories', [
            'type' => 'image', 'body' => 'Free delivery this week', 'link_url' => 'https://dorr-app.com/offers', 'link_label' => 'See offers',
            'file' => UploadedFile::fake()->image('ad.jpg', 400, 700),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        ChatDorrStory::query()->create(['uuid' => 'later', 'type' => 'text', 'body' => 'soon', 'starts_at' => now()->addDay()]);

        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->assertOk()
            ->assertJsonPath('data.dorr.owner.type', 'dorr')
            ->assertJsonCount(1, 'data.dorr.stories')
            ->assertJsonPath('data.dorr.stories.0.link_url', 'https://dorr-app.com/offers')
            ->assertJsonPath('data.dorr.all_seen', false);

        $this->postJson("/api/mobile/v1/chat/stories/dorr/{$id}/view", [], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/stories/dorr/{$id}/view", [], $this->headers())->assertOk();
        $this->assertSame(1, ChatDorrStory::query()->where('uuid', $id)->value('views_count'));
        $this->getJson('/api/mobile/v1/chat/stories/public', $this->headers())->assertJsonPath('data.dorr.all_seen', true);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $data
     */
    private function post_(User $user, array $data): TestResponse
    {
        $this->as($user);

        return $this->postJson('/api/mobile/v1/chat/stories', $data, $this->headers());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function asAdmin(array $permissions): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
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
