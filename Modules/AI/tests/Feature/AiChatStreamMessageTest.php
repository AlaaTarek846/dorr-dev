<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Support\Api\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Services\AiChatService;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * v2.0 requirements doc S18.2 (streaming). AiChatService::streamMessage()
 * deliberately delegates the actual work to sendMessage() - the same,
 * already fully tested pipeline - rather than re-implementing routing/
 * verification/safety a second time, then trickles the verified result
 * out as SSE chunks. That division of responsibility is proven here by
 * partial-mocking only sendMessage() (never chunkForStreaming/
 * emitSseEvent, which run for real): the SSE framing, the chunk/done
 * event sequence, and the byte-for-byte reconstruction of the original
 * content from concatenated chunks are all exercised against the real
 * implementation, not asserted against a description of it.
 *
 * The StreamedResponse callback only actually runs when the response is
 * sent (Symfony defers it) - this captures that real output via output
 * buffering around sendContent(), which is the same mechanism a real
 * HTTP server would use, rather than trying to introspect an unsent
 * response.
 */
class AiChatStreamMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Stream Test User',
            'email' => 'stream-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function captureStreamedOutput(StreamedResponse $response): string
    {
        // AiChatService::emitSseEvent() deliberately calls ob_flush() after
        // every event - correct for a real request (it pushes each SSE
        // event to the client as soon as it is ready), but with only one
        // buffer open that flush empties straight out to real output
        // instead of into what ob_get_clean() below reads back, so the
        // capture comes back empty. A second, inner buffer gives
        // ob_flush() somewhere of ours to land in: it flushes level 2 down
        // into level 1, and only level 1 is ever read back here.
        ob_start();
        ob_start();
        $response->sendContent();
        ob_end_flush();

        return ob_get_clean();
    }

    public function test_a_successful_answer_streams_as_chunk_events_followed_by_a_done_event_matching_the_original_content(): void
    {
        $owner = $this->makeUser();
        $content = 'This is the verified assistant answer that should stream back word by word.';

        $this->partialMock(AiChatService::class, function ($mock) use ($content) {
            $mock->shouldReceive('sendMessage')->once()->andReturn(ApiResponse::success([
                'trace_id' => 'trace-stream-test',
                'assistant_message' => ['id' => 1, 'content' => $content],
            ], 'OK'));
        });

        $response = app(AiChatService::class)->streamMessage($owner, 1, 'hello');
        $output = $this->captureStreamedOutput($response);

        $this->assertStringContainsString('event: chunk', $output);
        $this->assertStringContainsString('event: done', $output);
        $this->assertStringContainsString('trace-stream-test', $output);

        // Reconstruct the streamed text from every "delta" payload and
        // prove it is byte-for-byte identical to the original answer -
        // the whole point of chunking is that nothing gets lost, merged
        // wrong, or reordered along the way.
        preg_match_all('/event: chunk\ndata: (.+)\n\n/', $output, $matches);
        $this->assertNotEmpty($matches[1], 'expected at least one chunk event');

        $reassembled = '';
        foreach ($matches[1] as $json) {
            $reassembled .= json_decode($json, true)['delta'];
        }

        $this->assertSame($content, $reassembled);
    }

    public function test_a_failed_answer_streams_only_an_error_and_done_event_with_no_chunk_events(): void
    {
        $owner = $this->makeUser();

        $this->partialMock(AiChatService::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->once()->andReturn(
                ApiResponse::error('provider unavailable', 502, null, ['trace_id' => 'trace-fail'])
            );
        });

        $response = app(AiChatService::class)->streamMessage($owner, 1, 'hello');
        $output = $this->captureStreamedOutput($response);

        $this->assertStringNotContainsString('event: chunk', $output);
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('event: done', $output);
    }

    public function test_the_stream_response_declares_sse_headers(): void
    {
        $owner = $this->makeUser();

        // The headers below are set synchronously when response()->stream()
        // is constructed - streamMessage()'s callback (the only place that
        // calls sendMessage()) only runs on sendContent(), which this test
        // never invokes, so sendMessage() must not be mocked with a call
        // count expectation here - it is never going to be called.
        $response = app(AiChatService::class)->streamMessage($owner, 1, 'hello');

        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }
}
