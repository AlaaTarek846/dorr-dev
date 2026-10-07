<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiMessage;
use Modules\AI\Services\AiChatService;
use Modules\Admin\Models\Admin;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v2.0 requirements doc S16.1/S20.3: sequence_number existed as a column
 * (fillable, cast) but nothing in the codebase ever actually set it -
 * every message ever created had sequence_number = null. This is the
 * same "schema exists, nothing populates it" shape as the
 * idempotency_key bug fixed earlier this session, just for message
 * ordering instead of duplicate-request protection.
 *
 * AiChatService::createSequencedMessage() is reached by reflection for
 * the same reason as the rest of this suite: it is an internal helper,
 * and a real concurrent race (two true OS threads/processes) can't be
 * produced inside a single PHPUnit process anyway - what this test CAN
 * prove without faking concurrency is: (1) sequence numbers are
 * assigned correctly and increment per conversation, (2) they are
 * scoped per conversation (two conversations don't share a counter),
 * and (3) the conversation's messages() relation now actually orders by
 * them. The transaction+lockForUpdate mechanism itself is what closes
 * the real race window in production (MySQL); that mechanism is not
 * something a single-process test can observe directly, which is
 * disclosed here rather than pretended away.
 */
class AiChatSequencedMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Sequence Owner',
            'email' => 'seq-owner-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Sequence test conversation',
        ]);
    }

    protected function createSequenced(AiConversation $conversation, array $attributes): AiMessage
    {
        $method = new ReflectionMethod(AiChatService::class, 'createSequencedMessage');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $conversation, $attributes);
    }

    public function test_sequence_numbers_increment_per_message_within_a_conversation(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $first = $this->createSequenced($conversation, ['role' => AiMessage::ROLE_USER, 'content' => 'one']);
        $second = $this->createSequenced($conversation, ['role' => AiMessage::ROLE_ASSISTANT, 'content' => 'two']);
        $third = $this->createSequenced($conversation, ['role' => AiMessage::ROLE_USER, 'content' => 'three']);

        $this->assertSame(1, $first->sequence_number);
        $this->assertSame(2, $second->sequence_number);
        $this->assertSame(3, $third->sequence_number);
    }

    public function test_sequence_numbers_are_scoped_per_conversation_not_shared(): void
    {
        $owner = $this->makeOwner();
        $conversationA = $this->makeConversation($owner);
        $conversationB = $this->makeConversation($owner);

        $this->createSequenced($conversationA, ['role' => AiMessage::ROLE_USER, 'content' => 'a1']);
        $this->createSequenced($conversationA, ['role' => AiMessage::ROLE_USER, 'content' => 'a2']);

        // Conversation B's very first message must start back at 1, not
        // continue from wherever conversation A's counter is.
        $bFirst = $this->createSequenced($conversationB, ['role' => AiMessage::ROLE_USER, 'content' => 'b1']);

        $this->assertSame(1, $bFirst->sequence_number);
    }

    public function test_the_conversation_messages_relation_is_ordered_by_sequence_number(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        // Created out of natural chronological order on purpose (forced
        // explicit sequence_number + a manipulated created_at) so that a
        // relation still ordering by created_at alone would return them
        // in the wrong order, while one ordering by sequence_number
        // would not.
        $later = $conversation->messages()->create(['role' => AiMessage::ROLE_USER, 'content' => 'later by sequence', 'sequence_number' => 2]);
        $later->created_at = now()->subMinute();
        $later->save();

        $earlier = $conversation->messages()->create(['role' => AiMessage::ROLE_USER, 'content' => 'earlier by sequence', 'sequence_number' => 1]);
        $earlier->created_at = now();
        $earlier->save();

        $ordered = $conversation->refresh()->messages()->get();

        $this->assertSame('earlier by sequence', $ordered->first()->content);
        $this->assertSame('later by sequence', $ordered->last()->content);
    }
}
