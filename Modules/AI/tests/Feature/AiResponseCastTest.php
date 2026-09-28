<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiResponse;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Regression guard for a real bug found 2026-09-24 (manual chat testing,
 * right after the ai_provider_health/ai_usage table-name bug):
 * AiChatService::sendMessage() has always written an array
 * (['content' => ..., 'message' => ...]) into AiResponse::response, but
 * the model never cast that column - a plain longText, not json - so
 * every single chat reply (success OR failure) crashed at that
 * AiResponse::create() call with "Array to string conversion" the
 * moment a real database connection was involved, invisible to `php -l`.
 *
 * This writes the exact same shape AiChatService actually writes and
 * proves it round-trips correctly - not just "doesn't throw", but that
 * what comes back out is what went in.
 */
class AiResponseCastTest extends TestCase
{
    use RefreshDatabase;

    protected function makeRequest(): AiRequest
    {
        $owner = User::query()->create([
            'name' => 'Response Cast Test User',
            'email' => 'response-cast-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        return AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING,
        ]);
    }

    public function test_a_successful_response_array_round_trips_through_the_response_column(): void
    {
        $request = $this->makeRequest();

        $response = AiResponse::query()->create([
            'request_id' => $request->id,
            'response' => [
                'content' => 'This is the assistant reply text.',
                'message' => null,
            ],
            'finish_reason' => AiResponse::FINISH_COMPLETED,
        ]);

        $fresh = AiResponse::query()->find($response->id);

        $this->assertIsArray($fresh->response);
        $this->assertSame('This is the assistant reply text.', $fresh->response['content']);
        $this->assertNull($fresh->response['message']);
    }

    public function test_a_failed_response_array_with_a_null_content_round_trips_too(): void
    {
        $request = $this->makeRequest();

        $response = AiResponse::query()->create([
            'request_id' => $request->id,
            'response' => [
                'content' => null,
                'message' => 'provider unavailable',
            ],
            'finish_reason' => AiResponse::FINISH_ERROR,
        ]);

        $fresh = AiResponse::query()->find($response->id);

        $this->assertIsArray($fresh->response);
        $this->assertNull($fresh->response['content']);
        $this->assertSame('provider unavailable', $fresh->response['message']);
    }
}
