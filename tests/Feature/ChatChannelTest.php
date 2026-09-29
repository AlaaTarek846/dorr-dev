<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatMessage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Channels: admins post, followers read / react; public ones are discovered and followed, private
 * ones only through their link; followers never see each other; posts count views.
 */
class ChatChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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
        $this->owner = $make('Dorr Offers', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
    }

    public function test_a_public_channel_is_discovered_and_followed(): void
    {
        $channel = $this->create(['name' => 'Dorr Offers', 'handle' => '@Dorr_Offers', 'is_public' => true])
            ->assertCreated()->assertJsonPath('data.type', 'channel')->assertJsonPath('data.group.handle', 'dorr_offers')
            ->assertJsonPath('data.can_send', true)->json('data.id');

        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/channels/discover?search=offers', $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $channel)->assertJsonPath('data.0.is_following', false)->assertJsonPath('data.0.followers_count', 1);
        $this->getJson('/api/mobile/v1/chat/channels/@dorr_offers', $this->headers())->assertOk()->assertJsonPath('data.name', 'Dorr Offers');

        $this->postJson("/api/mobile/v1/chat/channels/{$channel}/follow", [], $this->headers())->assertOk()
            ->assertJsonPath('data.can_send', false)->assertJsonPath('data.group.members_count', 2);
        $this->getJson('/api/mobile/v1/chat/conversations?filter=channels', $this->headers())->assertJsonPath('data.0.id', $channel);

        // Followers can't post, can't list other followers, can't be added, can't call.
        $this->post("/api/mobile/v1/chat/conversations/{$channel}/messages", ['type' => 'text', 'body' => 'hi'], $this->headers() + ['Accept' => 'application/json'])->assertStatus(403);
        $this->getJson("/api/mobile/v1/chat/groups/{$channel}/members", $this->headers())->assertStatus(403);
        $this->postJson("/api/mobile/v1/chat/conversations/{$channel}/calls", ['type' => 'audio'], $this->headers())->assertStatus(422);

        $this->as($this->owner);
        $this->postJson("/api/mobile/v1/chat/groups/{$channel}/members", ['members' => [$this->carol->id]], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_channel_follow_only');
    }

    public function test_posts_count_views_and_following_is_silent(): void
    {
        $channel = $this->create(['name' => 'News', 'is_public' => true])->json('data.id');

        foreach ([$this->bob, $this->carol] as $user) {
            $this->as($user);
            $this->postJson("/api/mobile/v1/chat/channels/{$channel}/follow", [], $this->headers())->assertOk();
        }

        $this->as($this->owner);
        $post = $this->postJson("/api/mobile/v1/chat/conversations/{$channel}/messages", ['type' => 'text', 'body' => '50% off today 🎉'], $this->headers())
            ->assertCreated()->assertJsonPath('data.views', 0)->assertJsonPath('data.status', null)->json('data.id');

        // No "X joined" lines: only the creation line and the post.
        $this->assertSame(2, ChatMessage::query()->count());

        foreach ([$this->bob, $this->carol] as $user) {
            $this->as($user);
            $this->getJson("/api/mobile/v1/chat/conversations/{$channel}/messages", $this->headers())->assertOk()->assertJsonPath('data.messages.1.id', $post);
            $this->postJson("/api/mobile/v1/chat/conversations/{$channel}/read", [], $this->headers())->assertOk();
            // Followers react.
            $this->putJson("/api/mobile/v1/chat/messages/{$post}/reaction", ['emoji' => '🔥'], $this->headers())->assertOk();
        }

        $this->as($this->owner);
        $this->getJson("/api/mobile/v1/chat/conversations/{$channel}/messages", $this->headers())
            ->assertJsonPath('data.messages.1.views', 2)->assertJsonPath('data.messages.1.reactions.total', 2);

        // Unfollowing is quiet too.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/channels/{$channel}/unfollow", [], $this->headers())->assertOk();
        $this->assertSame(2, ChatMessage::query()->count());
    }

    public function test_a_private_channel_is_followed_only_by_its_link(): void
    {
        $channel = $this->create(['name' => 'VIP', 'is_public' => false, 'handle' => 'vip_room'])->json('data.id');

        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/channels/discover?search=vip', $this->headers())->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/v1/chat/channels/@vip_room', $this->headers())->assertNotFound();
        $this->postJson("/api/mobile/v1/chat/channels/{$channel}/follow", [], $this->headers())->assertNotFound();

        $this->as($this->owner);
        $token = $this->getJson("/api/mobile/v1/chat/groups/{$channel}/invite", $this->headers())->json('data.token');

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/invites/{$token}/join", [], $this->headers())->assertOk()->assertJsonPath('data.type', 'channel');
        $this->assertSame(1, ChatMessage::query()->count()); // still no "joined" line
    }

    public function test_handles_are_unique_and_well_formed(): void
    {
        $this->create(['name' => 'A', 'handle' => 'shop'])->assertCreated();
        $this->create(['name' => 'B', 'handle' => 'SHOP'])->assertStatus(422)->assertJsonPath('error_code', 'chat_channel_handle_taken');
        $this->create(['name' => 'C', 'handle' => 'no spaces!'])->assertStatus(422)->assertJsonPath('error_code', 'chat_channel_handle_invalid');
    }

    // ================================================================ helpers

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(array $data): TestResponse
    {
        $this->as($this->owner);

        return $this->post('/api/mobile/v1/chat/channels', $data, $this->headers() + ['Accept' => 'application/json']);
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
