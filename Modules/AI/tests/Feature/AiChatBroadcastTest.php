<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Events\AiMessageBroadcast;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiMessage;
use Modules\AI\Services\AiChatService;
use Modules\Admin\Models\Admin;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v2.0 requirements doc S15.3. Two things are actually under test here:
 *
 * 1. AiChatService::broadcastAssistantMessage() - reached by reflection
 *    since it is an internal step of sendMessage(), not a public API,
 *    and exercising the full sendMessage() flow would need a large,
 *    unrelated fixture setup (trial control, routing policy, a live
 *    provider) that has nothing to do with what this feature adds.
 *
 * 2. The real /broadcasting/auth endpoint (routes/channels.php +
 *    BroadcastServiceProvider), which is what actually decides whether
 *    a given owner may listen to a conversation's private channel - a
 *    misconfigured channel callback here would leak one owner's AI
 *    conversation replies to another, so this is worth a real HTTP
 *    round trip rather than reasoning about the closure in isolation.
 */
class AiChatBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Real, observed gap: config/broadcasting.php deliberately defaults
     * to the 'log'/'null' driver so installing that file alone never
     * breaks anything (see its own docblock) - but
     * LogBroadcaster::auth()/NullBroadcaster::auth() are literal no-ops
     * that return nothing at all, so /broadcasting/auth answers 200 for
     * EVERY request under those drivers, valid or not, without ever
     * running routes/channels.php's authorization callback. phpunit.xml
     * sets BROADCAST_CONNECTION=reverb specifically so this suite
     * exercises the real (Pusher-protocol-compatible) enforcement path
     * instead - but that override is silently ignored whenever
     * bootstrap/cache/config.php exists (a stale `php artisan
     * config:cache` run outside the test environment), which made the
     * two "...rejects..." tests below fail while "...authorizes..."
     * passed by pure coincidence (a no-op driver always returns 200,
     * which happens to be what the authorized case expects too). Forcing
     * the driver here, in code, makes this test assert the real
     * authorization logic deterministically on every machine, cached
     * config or not.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
        ]);
    }

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'broadcast-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'Broadcast test conversation',
            'provider_key' => 'openai',
        ]);
    }

    protected function callBroadcast(AiConversation $conversation, AiMessage $message): void
    {
        $method = new ReflectionMethod(AiChatService::class, 'broadcastAssistantMessage');
        $method->setAccessible(true);
        $method->invoke(app(AiChatService::class), $conversation, $message);
    }

    public function test_nothing_is_broadcast_when_the_feature_flag_is_disabled(): void
    {
        config(['ai.chat.broadcast_enabled' => false]);
        Event::fake([AiMessageBroadcast::class]);

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $message = $conversation->messages()->create(['role' => AiMessage::ROLE_ASSISTANT, 'content' => 'Hi']);

        $this->callBroadcast($conversation, $message);

        Event::assertNotDispatched(AiMessageBroadcast::class);
    }

    public function test_the_event_is_dispatched_with_the_conversation_and_message_when_enabled(): void
    {
        config(['ai.chat.broadcast_enabled' => true]);
        Event::fake([AiMessageBroadcast::class]);

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $message = $conversation->messages()->create(['role' => AiMessage::ROLE_ASSISTANT, 'content' => 'Refunds take 5 days.']);

        $this->callBroadcast($conversation, $message);

        Event::assertDispatched(AiMessageBroadcast::class, function (AiMessageBroadcast $event) use ($conversation, $message) {
            return $event->conversation->id === $conversation->id
                && $event->message->id === $message->id;
        });
    }

    public function test_the_broadcast_payload_carries_the_message_resource_shape(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $message = $conversation->messages()->create(['role' => AiMessage::ROLE_ASSISTANT, 'content' => 'Refunds take 5 days.']);

        $event = new AiMessageBroadcast($conversation, $message);
        $payload = $event->broadcastWith();

        $this->assertSame($conversation->id, $payload['conversation_id']);
        $this->assertSame($message->id, $payload['message']['id']);
        $this->assertSame('Refunds take 5 days.', $payload['message']['content']);
        $this->assertSame('message.ready', $event->broadcastAs());
    }

    public function test_the_event_broadcasts_on_a_private_channel_named_after_the_conversation(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $message = $conversation->messages()->create(['role' => AiMessage::ROLE_ASSISTANT, 'content' => 'Hi']);

        $channels = (new AiMessageBroadcast($conversation, $message))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-ai-conversation.'.$conversation->id, $channels[0]->name);
    }

    public function test_broadcasting_auth_rejects_an_unauthenticated_request(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-ai-conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ])->assertStatus(401);
    }

    public function test_broadcasting_auth_authorizes_the_conversations_own_owner(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        Sanctum::actingAs($owner, ['*'], 'admin_api');

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-ai-conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
    }

    public function test_broadcasting_auth_rejects_a_different_owners_conversation(): void
    {
        $owner = $this->makeOwner();
        $otherOwner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        Sanctum::actingAs($otherOwner, ['*'], 'admin_api');

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-ai-conversation.'.$conversation->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_broadcasting_auth_rejects_a_nonexistent_conversation(): void
    {
        $owner = $this->makeOwner();
        Sanctum::actingAs($owner, ['*'], 'admin_api');

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-ai-conversation.999999',
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }
}
