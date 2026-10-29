<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatBlock;
use Modules\Chat\Models\ChatCallLink;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Call links (WhatsApp list 72): I make a link, anyone with it joins the same room (nobody is
 * rung), I hear when someone joins, a blocked person can't, and a cancelled link stops working.
 */
class ChatCallLinkTest extends TestCase
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
        config(['chat.livekit.url' => 'wss://live.example.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => 'secret-that-is-long-enough']);
    }

    public function test_anyone_with_the_link_joins_the_same_room_and_the_owner_hears_about_it(): void
    {
        $this->mock(ChatPushNotifier::class)->shouldReceive('toAccounts')->once()
            ->withArgs(fn ($owners, $h, $c, $data) => $owners === [['user', $this->alice->id]] && $data['event'] === 'chat.call_link.joined');

        $this->as($this->alice);
        $made = $this->postJson('/api/mobile/v1/chat/call-links', ['type' => 'video'], $this->headers())->assertCreated();
        $token = $made->json('data.token');
        $this->assertSame(url('/call/'.$token), $made->json('data.link'));
        $this->assertSame('dorr://chat/call/'.$token, $made->json('data.payload'));
        $this->assertCount(1, $this->getJson('/api/mobile/v1/chat/call-links', $this->headers())->json('data'));
        $mine = $this->postJson("/api/mobile/v1/chat/call-links/{$token}/join", [], $this->headers())->assertOk();

        // Bob opens it: who made it and what kind of call, then into the same room.
        $this->as($this->bob);
        $this->getJson("/api/mobile/v1/chat/call-links/{$token}", $this->headers())
            ->assertOk()->assertJsonPath('data.type', 'video')->assertJsonPath('data.is_mine', false)->assertJsonPath('data.owner.name', 'Alice');
        $join = $this->postJson("/api/mobile/v1/chat/call-links/{$token}/join", [], $this->headers())->assertOk();
        $this->assertSame($mine->json('data.join.room'), $join->json('data.join.room'));
        $claims = json_decode(base64_decode(strtr(explode('.', $join->json('data.join.token'))[1], '-_', '+/')), true);
        $this->assertSame('user:'.$this->bob->id, $claims['sub']);
        // Joining again soon doesn't ping Alice a second time.
        $this->postJson("/api/mobile/v1/chat/call-links/{$token}/join", [], $this->headers())->assertOk();

        // Only Alice cancels it; then it's gone for everyone, page included.
        $this->deleteJson("/api/mobile/v1/chat/call-links/{$token}", [], $this->headers())->assertForbidden();
        $this->get('/call/'.$token)->assertOk()->assertSee('intent://chat/call/'.$token, false);
        $this->as($this->alice);
        $this->deleteJson("/api/mobile/v1/chat/call-links/{$token}", [], $this->headers())->assertOk();
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/call-links/{$token}/join", [], $this->headers())->assertNotFound()->assertJsonPath('error_code', 'chat_call_link_invalid');
        $this->get('/call/'.$token)->assertNotFound();
    }

    public function test_blocked_people_cannot_join_and_links_expire(): void
    {
        $this->as($this->alice);
        $token = $this->postJson('/api/mobile/v1/chat/call-links', ['type' => 'audio'], $this->headers())->assertCreated()->json('data.token');
        ChatBlock::query()->create(['blocker_type' => 'user', 'blocker_id' => $this->alice->id, 'blocked_type' => 'user', 'blocked_id' => $this->bob->id]);

        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/call-links/{$token}/join", [], $this->headers())->assertForbidden();

        ChatCallLink::query()->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/mobile/v1/chat/call-links/{$token}", $this->headers())->assertNotFound();
    }

    public function test_call_links_page_fifteen(): void
    {
        $this->as($this->alice);
        for ($i = 0; $i < 16; $i++) {
            $this->postJson('/api/mobile/v1/chat/call-links', ['type' => $i % 2 === 0 ? 'audio' : 'video'], $this->headers())->assertCreated();
        }
        $this->getJson('/api/mobile/v1/chat/call-links?per_page=15', $this->headers())->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('pagination.per_page', 15)
            ->assertJsonPath('pagination.total', 16)
            ->assertJsonPath('pagination.has_more_pages', true);
        $this->getJson('/api/mobile/v1/chat/call-links?page=2&per_page=15', $this->headers())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.has_more_pages', false);
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
