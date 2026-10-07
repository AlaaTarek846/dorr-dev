<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiGateway;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * Gateway-level coverage for the two new real voice capabilities the
 * user explicitly asked for ("محتاج توليد فيديو أو صوت - تسجيل صوتي من
 * العميل، أو رد صوتي"). Video generation was investigated first and
 * deliberately NOT built: OpenAI's own current API reference
 * (developers.openai.com, checked 2026-09-28) states the Sora 2 models
 * and Videos API "were shut down on September 24, 2026 ... No
 * one-to-one replacement API is available" - building against a
 * confirmed-dead endpoint would have repeated the exact mistake this
 * whole debugging effort was trying to stop (shipping code against an
 * API that was never actually verified). Audio (speech-to-text for an
 * incoming customer voice message, text-to-speech for a spoken reply)
 * WAS verified live against OpenAI's current API reference before any
 * of this was written, so it is implemented for real here - same
 * Http::fake()-against-the-real-connector pattern already used by
 * AiChatImageGenerationTest for /images/generations, so this actually
 * exercises OpenAiConnector::transcribeAudio()/synthesizeSpeech(), not
 * just a mock standing in for them.
 */
class AiVoiceGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProvider(): \Modules\AI\Models\AiProvider
    {
        $repository = app(AiProviderRepository::class);

        return $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    // --- transcribeAudio() -----------------------------------------------

    public function test_transcribe_audio_sends_a_real_multipart_request_and_parses_the_returned_text(): void
    {
        $provider = $this->makeProvider();

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'ابعتلي عرض سعر لخدمة تنظيف المنزل',
            ], 200),
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'whisper-1');

        $result = app(AiGateway::class)->transcribeAudio($callProvider, 'fake-audio-bytes', 'audio/mpeg');

        $this->assertTrue($result['success']);
        $this->assertSame('ابعتلي عرض سعر لخدمة تنظيف المنزل', $result['text']);

        // Real, observed gap: Illuminate\Http\Client\Request's ArrayAccess
        // (json_decode-based) only works for a JSON request body - this
        // call is multipart/form-data (audio file upload), so $request['model']
        // throws "Undefined array key" instead of reading the field. The
        // raw multipart body still contains the field name/value as
        // plain text, which is what the assertion checks instead.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
                && str_contains($request->body(), 'whisper-1')
                && str_contains($request->body(), 'name="file"');
        });
    }

    public function test_transcribe_audio_surfaces_a_failure_instead_of_pretending_to_succeed(): void
    {
        $provider = $this->makeProvider();

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'error' => ['message' => 'The audio file could not be decoded'],
            ], 400),
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'whisper-1');

        $result = app(AiGateway::class)->transcribeAudio($callProvider, 'not-really-audio', 'audio/mpeg');

        $this->assertFalse($result['success']);
        $this->assertNull($result['text']);
    }

    public function test_transcribe_audio_reports_a_real_network_failure_as_a_connection_error_not_a_generic_one(): void
    {
        $provider = $this->makeProvider();

        Http::fake(function () {
            throw new ConnectionException('Connection refused for URI https://api.openai.com/v1/audio/transcriptions');
        });

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'whisper-1');

        $result = app(AiGateway::class)->transcribeAudio($callProvider, 'fake-audio-bytes', 'audio/mpeg');

        $this->assertFalse($result['success']);
        $this->assertNull($result['text']);
        $this->assertStringContainsString(__('ai.connection_failed', ['message' => '']), $result['message']);
    }

    // --- synthesizeSpeech() ------------------------------------------------

    public function test_synthesize_speech_sends_a_real_json_request_and_returns_the_raw_audio_bytes(): void
    {
        $provider = $this->makeProvider();

        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'tts-1');

        $result = app(AiGateway::class)->synthesizeSpeech($callProvider, 'أهلاً بيك في دور');

        $this->assertTrue($result['success']);
        $this->assertSame('fake-mp3-bytes', base64_decode($result['audio']['base64']));
        $this->assertSame('audio/mpeg', $result['audio']['mime']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/audio/speech'
                && $request['model'] === 'tts-1'
                && $request['input'] === 'أهلاً بيك في دور';
        });
    }

    public function test_synthesize_speech_surfaces_a_failure_instead_of_pretending_to_succeed(): void
    {
        $provider = $this->makeProvider();

        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response([
                'error' => ['message' => 'No available capacity was found for the model'],
            ], 429),
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'tts-1');

        $result = app(AiGateway::class)->synthesizeSpeech($callProvider, 'أهلاً بيك في دور');

        $this->assertFalse($result['success']);
        $this->assertNull($result['audio']);
    }

    public function test_gateway_synthesize_speech_never_leaks_the_raw_provider_error_message_as_a_success(): void
    {
        // Guards the same class of bug already root-caused for image
        // edit/generation: a non-2xx response must never be reported as
        // success=true just because the HTTP call itself completed.
        $provider = $this->makeProvider();

        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response([
                'error' => ['message' => 'content_policy_violation'],
            ], 400),
        ]);

        $callProvider = tap(clone $provider, fn ($p) => $p->model = 'tts-1');

        $result = app(AiGateway::class)->synthesizeSpeech($callProvider, 'test');

        $this->assertFalse($result['success']);
    }
}
