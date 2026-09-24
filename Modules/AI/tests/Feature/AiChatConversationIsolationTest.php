<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiConversation;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * v2.0 requirements doc S17.2 (RBAC/ABAC + user data isolation). Unlike
 * the rest of this suite's reflection/repository-level tests, this one
 * deliberately goes through the real HTTP routes with real Sanctum auth
 * - that is the actual attack surface an IDOR (Insecure Direct Object
 * Reference) would be exploited through, so it is worth testing at that
 * layer rather than only at AiConversationRepository::findForOwner(),
 * which this exercises indirectly anyway.
 *
 * This is the one piece of a broader RBAC/ABAC audit of the AI module
 * that was actually missing a test - every other user/provider-facing
 * endpoint (export, erase) already had owner-isolation coverage. Admin
 * endpoints are out of scope here: DORR's Admin model has no
 * role/permission system at all yet (tracked separately, not an AI
 * module concern to build on its own).
 */
class AiChatConversationIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Isolation Test User',
            'email' => 'iso-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => true,
        ]);
    }

    protected function makeConversationFor(User $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Owner-only conversation',
        ]);
    }

    public function test_a_user_cannot_view_another_users_conversation_by_guessing_its_id(): void
    {
        $victim = $this->makeUser();
        $attacker = $this->makeUser();
        $conversation = $this->makeConversationFor($victim);

        Sanctum::actingAs($attacker, ['*'], 'user_api');

        $response = $this->getJson("/api/user/v1/ai-chat/conversations/{$conversation->id}");

        // 404, not 403 - the endpoint must not even confirm the
        // conversation exists to someone who doesn't own it.
        $response->assertNotFound();
    }

    public function test_a_user_cannot_delete_another_users_conversation_by_guessing_its_id(): void
    {
        $victim = $this->makeUser();
        $attacker = $this->makeUser();
        $conversation = $this->makeConversationFor($victim);

        Sanctum::actingAs($attacker, ['*'], 'user_api');

        $response = $this->deleteJson("/api/user/v1/ai-chat/conversations/{$conversation->id}");

        $response->assertNotFound();
        $this->assertNotNull($conversation->fresh(), 'the victim\'s conversation must still exist.');
    }

    public function test_a_user_cannot_send_a_message_into_another_users_conversation(): void
    {
        $victim = $this->makeUser();
        $attacker = $this->makeUser();
        $conversation = $this->makeConversationFor($victim);

        Sanctum::actingAs($attacker, ['*'], 'user_api');

        $response = $this->postJson("/api/user/v1/ai-chat/conversations/{$conversation->id}/messages", [
            'message' => 'trying to write into someone else\'s conversation',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, $conversation->fresh()->messages()->count());
    }

    public function test_a_user_only_sees_their_own_conversations_in_the_list(): void
    {
        $owner = $this->makeUser();
        $otherUser = $this->makeUser();

        $mine = $this->makeConversationFor($owner);
        $this->makeConversationFor($otherUser);

        Sanctum::actingAs($owner, ['*'], 'user_api');

        $response = $this->getJson('/api/user/v1/ai-chat/conversations');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
    }
}
