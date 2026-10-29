<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatGroup;
use Modules\Chat\Services\CommunityService;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Communities (WhatsApp list 93–98): groups under one roof with an announcements group only
 * admins write in; members hop between its groups; admins (up to 20) manage groups and people;
 * the creator stays an admin until the community is deleted.
 */
class ChatCommunityTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private User $dan;

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
        $this->dan = $make('Dan', '+966500000004');
        // Everyone saved Alice (so she may add them to groups).
        foreach ([$this->bob, $this->carol, $this->dan] as $other) {
            ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $other->id, 'name' => 'Alice', 'phone' => $this->alice->phone, 'contact_type' => 'user', 'contact_id' => $this->alice->id, 'source' => 'manual']);
        }
    }

    public function test_a_community_gathers_groups_under_an_announcements_group_only_admins_write_in(): void
    {
        $parents = $this->group($this->alice, 'Parents', [$this->bob]);
        $teachers = $this->group($this->alice, 'Teachers', [$this->carol]);

        $this->as($this->alice);
        $made = $this->postJson('/api/mobile/v1/chat/communities', ['name' => 'School', 'description' => 'Our school', 'groups' => [$parents, $teachers]], $this->headers())
            ->assertCreated()->assertJsonPath('data.name', 'School')->assertJsonPath('data.is_creator', true)
            ->assertJsonCount(2, 'data.groups')->assertJsonPath('data.members_count', 3);
        $community = $made->json('data.id');
        $announcements = $made->json('data.announcement_id');

        // Bob (in Parents) is in the community: he reads announcements but can't write there.
        $this->as($this->bob);
        $this->getJson('/api/mobile/v1/chat/communities', $this->headers())->assertOk()->assertJsonPath('data.0.id', $community);
        $this->send($announcements, 'Hi all')->assertForbidden();
        $this->as($this->alice);
        $this->send($announcements, 'School trip on Monday')->assertCreated();

        // The group knows its community.
        $this->getJson("/api/mobile/v1/chat/conversations/{$parents}", $this->headers())->assertOk()
            ->assertJsonPath('data.group.community.id', $community)->assertJsonPath('data.group.community.is_announcement', false);

        // Dan, added to Teachers later, lands in the community too.
        $this->postJson("/api/mobile/v1/chat/groups/{$teachers}/members", ['members' => [$this->dan->id]], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/communities/{$community}", $this->headers())->assertJsonPath('data.members_count', 4);

        // Bob sees Teachers among its groups and hops in (95).
        $this->as($this->bob);
        $page = $this->getJson("/api/mobile/v1/chat/communities/{$community}", $this->headers())->assertOk()->json('data.groups');
        $this->assertSame([true, false], collect($page)->sortBy('name')->pluck('is_member')->values()->all());
        $this->postJson("/api/mobile/v1/chat/communities/{$community}/groups/{$teachers}/join", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'joined');
        // A group that approves new members: he asks instead.
        $this->as($this->alice);
        $club = $this->postJson("/api/mobile/v1/chat/communities/{$community}/groups", ['name' => 'Club'], $this->headers())->assertOk()->json('data.groups');
        $clubId = collect($club)->firstWhere('name', 'Club')['id'];
        $this->patchJson("/api/mobile/v1/chat/groups/{$clubId}/settings", ['approve_joins' => true], $this->headers())->assertOk();
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/communities/{$community}/groups/{$clubId}/join", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_admins_manage_people_and_groups_and_the_creator_stays(): void
    {
        $parents = $this->group($this->alice, 'Parents', [$this->bob, $this->carol]);
        $this->as($this->alice);
        $community = $this->postJson('/api/mobile/v1/chat/communities', ['name' => 'School', 'groups' => [$parents]], $this->headers())->assertCreated()->json('data.id');
        $members = collect($this->getJson("/api/mobile/v1/chat/communities/{$community}/members", $this->headers())->assertOk()->json('data'));
        $bob = $members->firstWhere('profile.name', 'Bob')['participant_id'];
        $carol = $members->firstWhere('profile.name', 'Carol')['participant_id'];
        $aliceRow = $members->firstWhere('profile.name', 'Alice')['participant_id'];

        // Members don't manage it.
        $this->as($this->bob);
        $this->deleteJson("/api/mobile/v1/chat/communities/{$community}/members/{$carol}", [], $this->headers())->assertForbidden();

        // Alice makes Bob an admin; he can't touch the creator.
        $this->as($this->alice);
        $this->patchJson("/api/mobile/v1/chat/communities/{$community}/members/{$bob}/role", ['role' => 'admin'], $this->headers())->assertOk();
        $this->as($this->bob);
        $this->deleteJson("/api/mobile/v1/chat/communities/{$community}/members/{$aliceRow}", [], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_community_creator_stays');
        $this->patchJson("/api/mobile/v1/chat/communities/{$community}/members/{$aliceRow}/role", ['role' => 'member'], $this->headers())->assertForbidden();

        // Bob removes Carol: out of the community and of Parents at once (96).
        $this->deleteJson("/api/mobile/v1/chat/communities/{$community}/members/{$carol}", [], $this->headers())->assertOk();
        $this->as($this->carol);
        $this->getJson("/api/mobile/v1/chat/communities/{$community}", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/conversations/{$parents}", $this->headers())->assertJsonPath('data.is_member', false);

        // The creator can't leave or hand it over; she can only delete it (98).
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/communities/{$community}/leave", [], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_community_creator_stays');
        $announcement = $this->getJson("/api/mobile/v1/chat/communities/{$community}", $this->headers())->json('data.announcement_id');
        $this->postJson("/api/mobile/v1/chat/groups/{$announcement}/owner", ['participant_id' => $bob], $this->headers())->assertStatus(422);

        // Bob (not the creator) can't delete it; Alice does — Parents goes on as a plain group.
        $this->as($this->bob);
        $this->deleteJson("/api/mobile/v1/chat/communities/{$community}", [], $this->headers())->assertForbidden();
        $this->as($this->alice);
        $this->deleteJson("/api/mobile/v1/chat/communities/{$community}", [], $this->headers())->assertOk();
        $this->assertNull(ChatGroup::query()->where('name', 'Parents')->value('community_id'));
        $this->getJson("/api/mobile/v1/chat/communities/{$community}", $this->headers())->assertNotFound();
    }

    public function test_communities_page_fifteen_and_search(): void
    {
        $this->as($this->alice);
        $communities = app(CommunityService::class);
        for ($i = 0; $i < 16; $i++) {
            $communities->create($this->alice, 'Place '.$i, $i === 3 ? 'needle park' : null, [], null);
        }
        $this->getJson('/api/mobile/v1/chat/communities?per_page=15', $this->headers())->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('pagination.per_page', 15)
            ->assertJsonPath('pagination.total', 16)
            ->assertJsonPath('pagination.has_more_pages', true);
        $this->getJson('/api/mobile/v1/chat/communities?page=2&per_page=15', $this->headers())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.has_more_pages', false);
        $this->getJson('/api/mobile/v1/chat/communities?q=needle', $this->headers())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Place 3');
    }

    public function test_up_to_20_admins_and_a_group_joins_only_one_community(): void
    {
        $parents = $this->group($this->alice, 'Parents', [$this->bob]);
        $this->as($this->alice);
        $community = $this->postJson('/api/mobile/v1/chat/communities', ['name' => 'School', 'groups' => [$parents]], $this->headers())->assertCreated()->json('data.id');
        $this->postJson('/api/mobile/v1/chat/communities', ['name' => 'Other', 'groups' => [$parents]], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_community_group_taken');

        // 19 more admins fill it; the 21st is refused.
        $announcement = $this->getJson("/api/mobile/v1/chat/communities/{$community}", $this->headers())->json('data.announcement_id');
        $conversation = \Modules\Chat\Models\ChatConversation::query()->where('uuid', $announcement)->first();
        $country = $this->alice->country_id;
        for ($i = 0; $i < 19; $i++) {
            $u = User::create(['name' => "Admin {$i}", 'phone' => '+96650100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'country_id' => $country, 'status' => 'active', 'phone_verified_at' => now()]);
            app(\Modules\Chat\Services\ConversationService::class)->addParticipant($conversation, $u, \Modules\Chat\Enums\ParticipantRole::Admin);
        }
        $bob = collect($this->getJson("/api/mobile/v1/chat/communities/{$community}/members", $this->headers())->json('data'))->firstWhere('profile.name', 'Bob')['participant_id'];
        $this->patchJson("/api/mobile/v1/chat/communities/{$community}/members/{$bob}/role", ['role' => 'admin'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_community_admins_full');
    }

    /**
     * @param  list<User>  $members
     */
    private function group(User $owner, string $name, array $members): string
    {
        $this->as($owner);

        return $this->postJson('/api/mobile/v1/chat/groups', ['name' => $name, 'members' => array_map(fn (User $u) => $u->id, $members)], $this->headers())
            ->assertCreated()->json('data.conversation.id');
    }

    private function send(string $conversation, string $text): TestResponse
    {
        return $this->postJson("/api/mobile/v1/chat/conversations/{$conversation}/messages", ['type' => 'text', 'body' => $text], $this->headers());
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
