<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiCodeExecution;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiKnowledgeSource;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiRequestCitation;
use Modules\AI\Services\AiChatService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v2.0 requirements doc S15.4/S20.3 (idempotency for costly operations).
 *
 * AiChatService::sendMessage() itself is not exercised end to end here,
 * for the same reason the rest of this suite avoids it: it needs a full
 * routing/safety/verification/provider fixture that has nothing to do
 * with idempotency specifically. Instead this reaches the three pieces
 * that were actually added - findIdempotentRequest(),
 * replayIdempotentRequest(), and isUniqueConstraintViolation() - by
 * reflection, plus one real, unmocked test of the DB-level unique
 * constraint the race-condition path depends on.
 */
class AiChatIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Idempotency Owner',
            'email' => 'idem-owner-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
        ]);
    }

    protected function makeConversation(Admin $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Idempotency test conversation',
        ]);
    }

    protected function callFindIdempotentRequest(Admin $owner, string $key): ?AiRequest
    {
        $method = new ReflectionMethod(AiChatService::class, 'findIdempotentRequest');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $owner, $key);
    }

    protected function callReplay(Admin $owner, AiRequest $existing)
    {
        $method = new ReflectionMethod(AiChatService::class, 'replayIdempotentRequest');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $owner, $existing);
    }

    public function test_the_owner_scoped_unique_constraint_rejects_a_second_request_with_the_same_key(): void
    {
        $owner = $this->makeOwner();

        AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING,
            'idempotency_key' => 'idem-race-key',
        ]);

        $this->expectException(QueryException::class);

        AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING,
            'idempotency_key' => 'idem-race-key',
        ]);
    }

    public function test_the_unique_violation_is_recognised_by_is_unique_constraint_violation(): void
    {
        $owner = $this->makeOwner();

        AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING,
            'idempotency_key' => 'idem-race-key-2',
        ]);

        $caught = null;

        try {
            AiRequest::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->id,
                'status' => AiRequest::STATUS_PROCESSING,
                'idempotency_key' => 'idem-race-key-2',
            ]);
        } catch (QueryException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'expected the duplicate insert to throw');

        $method = new ReflectionMethod(AiChatService::class, 'isUniqueConstraintViolation');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(app(AiChatService::class), $caught));
    }

    public function test_the_same_key_is_not_shared_across_two_different_owners(): void
    {
        $ownerA = $this->makeOwner();
        $ownerB = $this->makeOwner();

        AiRequest::query()->create([
            'owner_type' => $ownerA->getMorphClass(),
            'owner_id' => $ownerA->id,
            'status' => AiRequest::STATUS_COMPLETED,
            'idempotency_key' => 'shared-looking-key',
        ]);

        // Same literal key, different owner - must not collide at the DB
        // level (owner-scoped unique index) or be found as "the same"
        // request by the lookup.
        AiRequest::query()->create([
            'owner_type' => $ownerB->getMorphClass(),
            'owner_id' => $ownerB->id,
            'status' => AiRequest::STATUS_COMPLETED,
            'idempotency_key' => 'shared-looking-key',
        ]);

        $foundForA = $this->callFindIdempotentRequest($ownerA, 'shared-looking-key');
        $foundForB = $this->callFindIdempotentRequest($ownerB, 'shared-looking-key');

        $this->assertSame($ownerA->id, $foundForA->owner_id);
        $this->assertSame($ownerB->id, $foundForB->owner_id);
        $this->assertNotSame($foundForA->id, $foundForB->id);
    }

    public function test_replaying_a_still_processing_request_returns_a_409_instead_of_a_fabricated_answer(): void
    {
        $owner = $this->makeOwner();

        $existing = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING,
            'idempotency_key' => 'still-running',
            'correlation_id' => 'trace-still-running',
        ]);

        $response = $this->callReplay($owner, $existing);

        $this->assertSame(409, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertFalse($payload['success']);
        $this->assertSame('trace-still-running', $payload['data']['trace_id']);
    }

    public function test_replaying_a_completed_request_rebuilds_the_full_original_response_from_persisted_data(): void
    {
        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $existing = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_COMPLETED,
            'idempotency_key' => 'completed-request',
            'correlation_id' => 'trace-completed',
        ]);

        $userMessage = $conversation->messages()->create([
            'role' => AiMessage::ROLE_USER,
            'content' => 'What is DORR?',
            'request_id' => $existing->id,
        ]);

        $assistantMessage = $conversation->messages()->create([
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'DORR is a multi-service platform.',
            'provider_key' => 'openai',
            'model' => 'gpt-4o-mini',
            'request_id' => $existing->id,
            'generated_file' => ['name' => 'answer.pdf', 'url' => 'https://example.test/answer.pdf'],
            'confidence_score' => 0.77,
            'verification_warnings' => ['low sample size'],
        ]);

        $source = AiKnowledgeSource::query()->create([
            'owner_type' => 'system',
            'owner_id' => null,
            'name' => 'DORR handbook',
            'publisher' => 'DORR',
            'data_classification' => AiKnowledgeSource::CLASSIFICATION_PUBLIC,
            'approval_status' => AiKnowledgeSource::APPROVAL_APPROVED,
            'is_active' => true,
            'processing_status' => AiKnowledgeSource::PROCESSING_READY,
        ]);

        AiRequestCitation::query()->create([
            'request_id' => $existing->id,
            'knowledge_source_id' => $source->id,
            'position' => 2,
            'excerpt' => 'DORR connects customers with service providers.',
            'relevance_score' => 0.91,
        ]);

        AiCodeExecution::query()->create([
            'request_id' => $existing->id,
            'conversation_id' => $conversation->id,
            'language' => 'python',
            'driver' => 'docker',
            'code' => 'print(1)',
            'status' => AiCodeExecution::STATUS_COMPLETED,
            'exit_code' => 0,
        ]);

        $response = $this->callReplay($owner, $existing);
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame('trace-completed', $payload['data']['trace_id']);
        $this->assertSame(AiRequest::STATUS_COMPLETED, $payload['data']['status']);
        $this->assertTrue($payload['data']['idempotent_replay']);

        $this->assertSame($assistantMessage->id, $payload['data']['assistant_message']['id']);
        $this->assertSame('DORR is a multi-service platform.', $payload['data']['assistant_message']['content']);
        $this->assertEquals(0.77, $payload['data']['confidence']);
        $this->assertSame(['low sample size'], $payload['data']['warnings']);

        $this->assertCount(1, $payload['data']['sources']);
        $this->assertSame('DORR handbook', $payload['data']['sources'][0]['source']);
        $this->assertSame('DORR', $payload['data']['sources'][0]['publisher']);
        $this->assertSame(2, $payload['data']['sources'][0]['position']);

        $this->assertSame('completed', $payload['data']['code_execution']['status']);
        $this->assertSame('python', $payload['data']['code_execution']['language']);

        $this->assertSame($userMessage->id, $payload['data']['user_message']['id']);
    }
}
