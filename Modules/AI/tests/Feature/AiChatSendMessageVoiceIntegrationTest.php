<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiPlan;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Real, full end-to-end exercise of AiChatService::sendMessage() for the
 * two voice scenarios the user explicitly asked for: a customer's
 * incoming voice message getting transcribed, and an explicit request
 * for a spoken voice reply - same rigor as
 * AiChatSendMessageImageIntegrationTest (no reflection shortcuts, the
 * real routing/safety/domain/attachment pipeline runs exactly as it
 * would for a real request). Speech-to-text and text-to-speech are
 * resolved via AiChatService::resolveCandidateFor() - deliberately
 * independent of the main per-turn routing candidate (see that method's
 * docblock) - so AiGateway::chat() is mocked here (it is not what is
 * under test), while transcribeAudio()/synthesizeSpeech() are left to
 * run for real against a faked HTTP response (AiVoiceGatewayTest already
 * covers those in isolation in more detail).
 */
class AiChatSendMessageVoiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Voice Integration Owner',
            'email' => 'voice-integration-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeConversation(User $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Voice integration test conversation',
        ]);
    }

    /**
     * Same real, observed test gap as
     * AiChatSendMessageImageIntegrationTest::makeTrialPlan() - see that
     * method's docblock.
     */
    protected function makeTrialPlan(): AiPlan
    {
        return AiPlan::query()->create([
            'name' => 'Trial Plan (voice integration test)',
            'code' => 'trial-voice-integration-test-'.uniqid(),
            'is_trial' => true,
            'is_active' => true,
            'sort_order' => 1,
            'usage_minutes' => 60,
            'cooldown_minutes' => 5,
        ]);
    }

    protected function registerProviderWithModels(array $capabilities): void
    {
        $repository = app(AiProviderRepository::class);
        $provider = $repository->updateByKey('openai', [
            'is_enabled' => true,
            'api_key' => 'sk-test-not-real',
            'model' => 'gpt-4o-mini',
        ]);
        $repository->setDefault('openai');

        $provider->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat'],
            'is_default' => true,
            'is_active' => true,
        ]);

        if (in_array('speech_to_text', $capabilities, true)) {
            $provider->models()->create([
                'model_key' => 'whisper-1',
                'display_name' => 'Whisper',
                'capabilities' => ['speech_to_text'],
                'is_active' => true,
            ]);
        }

        if (in_array('text_to_speech', $capabilities, true)) {
            $provider->models()->create([
                'model_key' => 'tts-1',
                'display_name' => 'TTS',
                'capabilities' => ['text_to_speech'],
                'is_active' => true,
            ]);
        }
    }

    public function test_an_incoming_voice_message_is_transcribed_and_the_transcript_drives_the_whole_turn(): void
    {
        Config::set('ai.chat.verification.enabled', false);
        $this->registerProviderWithModels(['speech_to_text']);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'عايز اعرف اسعار خدمة غسيل السيارات',
            ], 200),
        ]);

        $this->makeTrialPlan();

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $capturedMessages = null;

        $this->partialMock(AiGateway::class, function ($mock) use (&$capturedMessages) {
            $mock->shouldReceive('transcribeAudio')->passthru();
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($provider, $messages) use (&$capturedMessages) {
                    $capturedMessages = $messages;

                    return true;
                })
                ->andReturn([
                    'success' => true,
                    'message' => '',
                    'content' => 'أسعار غسيل السيارات بتبدأ من 50 جنيه للغسيل الخارجي.',
                ]);
        });

        $attachment = UploadedFile::fake()->create('voice-note.mp3', 50, 'audio/mpeg');

        $response = app(AiChatService::class)->sendMessage($owner, $conversation->id, '', $attachment);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success'], json_encode($payload));

        // The decisive assertion: the transcript - not a blank string,
        // not a placeholder - is what actually reached the chat model.
        $this->assertNotNull($capturedMessages);
        $lastMessage = end($capturedMessages);
        // The user's turn also carries a "[a file was attached]" note
        // appended after the transcript (same pattern buildHistory()
        // already uses for extracted document text) - the decisive check
        // is that the real transcript text is IN there, not blank.
        $this->assertStringContainsString('عايز اعرف اسعار خدمة غسيل السيارات', $lastMessage['content']);

        // The stored user message itself must also show the real
        // transcript, not the empty string the request came in with.
        $userMessage = $payload['data']['user_message']['content'] ?? null;
        $this->assertSame('عايز اعرف اسعار خدمة غسيل السيارات', $userMessage);
    }

    public function test_a_voice_message_that_cannot_be_transcribed_gets_an_honest_reply_not_a_hallucinated_one(): void
    {
        Config::set('ai.chat.verification.enabled', false);
        // Deliberately NOT registering any speech_to_text-capable model.
        $this->registerProviderWithModels([]);

        $this->makeTrialPlan();

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $capturedMessages = null;

        $this->mock(AiGateway::class, function ($mock) use (&$capturedMessages) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($provider, $messages) use (&$capturedMessages) {
                    $capturedMessages = $messages;

                    return true;
                })
                ->andReturn([
                    'success' => true,
                    'message' => '',
                    'content' => 'معنديش إمكانية إني أسمع أو أفهم الرسالة الصوتية دلوقتي.',
                ]);
        });

        $attachment = UploadedFile::fake()->create('voice-note.mp3', 50, 'audio/mpeg');

        $response = app(AiChatService::class)->sendMessage($owner, $conversation->id, '', $attachment);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success'], json_encode($payload));

        // The honesty system message must actually have reached the
        // model - proving the "cannot guess the voice message" guard is
        // really wired in, not just present as dead code.
        $this->assertNotNull($capturedMessages);
        $systemTexts = collect($capturedMessages)->where('role', 'system')->pluck('content')->implode(' | ');
        $this->assertStringContainsString('could NOT be transcribed', $systemTexts);
    }

    public function test_an_explicit_voice_reply_request_attaches_real_synthesized_audio_to_the_assistant_message(): void
    {
        Storage::fake('public');
        Config::set('ai.chat.verification.enabled', false);
        $this->registerProviderWithModels(['text_to_speech']);

        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->makeTrialPlan();

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $this->partialMock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('synthesizeSpeech')->passthru();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => '',
                    'content' => 'أسعار غسيل السيارات بتبدأ من 50 جنيه.',
                ]);
        });

        $response = app(AiChatService::class)->sendMessage($owner, $conversation->id, 'قوللي الاسعار برد صوتي', null);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertNotEmpty($payload['data']['assistant_message']['attachments'] ?? []);

        $storedAttachment = AiConversationAttachment::query()
            ->where('mime_type', 'audio/mpeg')
            ->latest('id')
            ->first();

        $this->assertNotNull($storedAttachment, 'expected a real ai_conversation_attachments row for the synthesized voice reply');
        $this->assertTrue(Storage::disk('public')->exists($storedAttachment->file_path));
        $this->assertSame('fake-mp3-bytes', Storage::disk('public')->get($storedAttachment->file_path));
    }

    public function test_a_voice_reply_request_with_no_tts_model_configured_is_honest_instead_of_silently_failing(): void
    {
        Config::set('ai.chat.verification.enabled', false);
        // Deliberately NOT registering any text_to_speech-capable model.
        $this->registerProviderWithModels([]);

        $this->makeTrialPlan();

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        $capturedMessages = null;

        $this->mock(AiGateway::class, function ($mock) use (&$capturedMessages) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($provider, $messages) use (&$capturedMessages) {
                    $capturedMessages = $messages;

                    return true;
                })
                ->andReturn([
                    'success' => true,
                    'message' => '',
                    'content' => 'الاسعار بتبدأ من 50 جنيه.',
                ]);
        });

        $response = app(AiChatService::class)->sendMessage($owner, $conversation->id, 'قوللي الاسعار برد صوتي', null);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertEmpty($payload['data']['assistant_message']['attachments'] ?? []);

        $this->assertNotNull($capturedMessages);
        $systemTexts = collect($capturedMessages)->where('role', 'system')->pluck('content')->implode(' | ');
        $this->assertStringContainsString('audio file will be attached', $systemTexts);
    }
}
