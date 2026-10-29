<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Educational / work groups: the hand-over waits for the member's yes (GR-005), archive and a
 * delete that can be undone for a while (GR-006), and terms / projects as chats of their own
 * inside the group (GR-007).
 */
class ChatGroupLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $amal;

    private User $badr;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->owner = $make('Owner', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
        $this->sara = $make('Sara', '+966500000004');
    }

    public function test_the_hand_over_waits_for_the_members_yes_and_the_owner_cannot_just_leave(): void
    {
        $group = $this->group('work');
        $amal = $this->participant($group, 'Amal');

        $this->as($this->owner);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/leave", [], $this->headers())->assertStatus(409)->assertJsonPath('error_code', 'chat_owner_must_transfer');

        // Offered: nothing changes until Amal answers.
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/owner", ['participant_id' => $amal], $this->headers())->assertOk()
            ->assertJsonPath('data.my_role', 'owner')->assertJsonPath('data.group.ownership_offer.to_participant_id', $amal);

        // Badr sees no offer, and can't answer it.
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertJsonPath('data.group.ownership_offer', null);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/owner/accept", [], $this->headers())->assertNotFound();

        // Amal declines; offered again, Amal accepts.
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertJsonPath('data.group.ownership_offer.for_me', true);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/owner/decline", [], $this->headers())->assertOk()->assertJsonPath('data.group.ownership_offer', null);
        $this->as($this->owner);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/owner", ['participant_id' => $amal], $this->headers())->assertOk();
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/owner/accept", [], $this->headers())->assertOk()->assertJsonPath('data.my_role', 'owner');

        // The old owner stays as an admin and may leave now.
        $this->as($this->owner);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertJsonPath('data.my_role', 'admin');
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/leave", [], $this->headers())->assertOk();

        $this->assertSame(['ownership_offered', 'ownership_declined', 'ownership_offered', 'ownership_accepted'],
            ChatGroupAuditLog::query()->where('action', 'like', 'ownership_%')->orderBy('id')->pluck('action')->all());
    }

    public function test_an_archived_group_is_read_only_and_a_deleted_one_comes_back_until_it_is_purged(): void
    {
        $group = $this->group('education');

        $this->as($this->owner);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/archive", ['archived' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.group.read_only', true)->assertJsonPath('data.can_send', false);
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/conversations/{$group}/messages", ['type' => 'text', 'body' => 'Hi'], $this->headers())
            ->assertStatus(423)->assertJsonPath('error_code', 'chat_group_read_only');
        $this->as($this->owner);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/archive", ['archived' => false], $this->headers())->assertOk()->assertJsonPath('data.can_send', true);

        // Deleted: gone for the members, back for the owner within the days.
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}", [], $this->headers())->assertOk();
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertNotFound();
        $this->as($this->owner);
        $this->getJson('/api/mobile/v1/chat/groups/deleted', $this->headers())->assertOk()->assertJsonPath('data.0.id', $group);
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/restore", [], $this->headers())->assertForbidden();
        $this->as($this->owner);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/restore", [], $this->headers())->assertOk();
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/conversations/{$group}", $this->headers())->assertOk();

        // Deleted again and the days run out: gone for good.
        $this->as($this->owner);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}", [], $this->headers())->assertOk();
        $this->travel(31)->days();
        $this->artisan('chat:purge-deleted-groups')->assertSuccessful();
        $this->assertNull(ChatConversation::withTrashed()->where('uuid', $group)->first());
    }

    public function test_terms_are_chats_of_their_own_and_leaving_the_group_leaves_them_all(): void
    {
        $group = $this->group('education');
        $amal = $this->participant($group, 'Amal');

        // Work groups have projects, educational groups terms and subgroups.
        $this->as($this->owner);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/spaces", ['type' => 'project', 'name' => 'X'], $this->headers())->assertStatus(422);
        $term = $this->postJson("/api/mobile/v1/chat/groups/{$group}/spaces", [
            'type' => 'term', 'name' => 'Term 1', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31', 'members' => [$amal],
        ], $this->headers())->assertCreated()->assertJsonPath('data.members_count', 2)->json('data');

        // Amal is in it; Badr (in the group) is not, and can't see it.
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/spaces", $this->headers())->assertJsonCount(1, 'data');
        $this->getJson("/api/mobile/v1/chat/conversations/{$term['conversation_id']}", $this->headers())->assertOk()
            ->assertJsonPath('data.group.space.type', 'term')->assertJsonPath('data.group.space.parent_id', $group);
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$group}/spaces", $this->headers())->assertJsonCount(0, 'data');
        $this->getJson("/api/mobile/v1/chat/conversations/{$term['conversation_id']}", $this->headers())->assertForbidden();

        // No way in from outside: no invite link, no adding someone who isn't in the group.
        $this->as($this->owner);
        $this->getJson("/api/mobile/v1/chat/groups/{$term['conversation_id']}/invite", $this->headers())->assertStatus(422);
        $this->postJson("/api/mobile/v1/chat/groups/{$term['conversation_id']}/members", ['members' => [$this->sara->id]], $this->headers())->assertStatus(422);
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/spaces/{$term['id']}/members", ['members' => [999999]], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'chat_space_members_from_group');

        // Closed: read only.
        $this->postJson("/api/mobile/v1/chat/groups/{$group}/spaces/{$term['id']}/close", ['closed' => true], $this->headers())->assertOk()->assertJsonPath('data.is_closed', true);
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/conversations/{$term['conversation_id']}/messages", ['type' => 'text', 'body' => 'Hi'], $this->headers())->assertStatus(423);

        // Removed from the main group = out of the term at once.
        $this->as($this->owner);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$group}/members/{$amal}", [], $this->headers())->assertOk();
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/conversations/{$term['conversation_id']}", $this->headers())->assertJsonPath('data.is_member', false);
    }

    private function group(string $kind): string
    {
        $this->as($this->owner);

        return $this->postJson('/api/mobile/v1/chat/groups', [
            'name' => 'Team', 'kind' => $kind, 'members' => [$this->amal->id, $this->badr->id],
        ], $this->headers())->assertCreated()->json('data.conversation.id');
    }

    private function participant(string $group, string $name): int
    {
        $this->as($this->owner);

        return collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->firstWhere('profile.account_name', $name)['participant_id'];
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
