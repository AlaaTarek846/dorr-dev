<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiPlan;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 7 doc S43 ("existing bug disclosure" - "determine whether video
 * attachments are accidentally routed through the same [unconditional]
 * behavior [as audio]... pin it with a regression test and report it
 * clearly... do NOT silently redesign the unrelated old pipeline during
 * this phase").
 *
 * The investigated reality (see this phase's Final Report, section
 * "AI Integration"): AiChatService::sendMessage()/buildDocumentText()
 * treats ANY non-image, non-audio attachment as a generic document and
 * runs it through AiDocumentTextExtractor - which does not, and never
 * did, support any video MIME type. The model still gets AN honest
 * disclosure (the existing generic `ai.attachment_note` - "attached a
 * file ... you cannot open or analyze its contents directly"), so this
 * is not as silent a gap as it first appears - but it is the WRONG
 * disclosure for video specifically: by the time this request runs,
 * VideoFileProcessor has already extracted real duration/resolution/
 * has-audio metadata into ai_files, and none of it reaches the model -
 * a plain "مدتها كام؟" gets the same "I can't analyze this" non-answer
 * a genuinely un-processable file would. This is a pre-existing gap in
 * AiChatService, not something this phase's new VideoFileProcessor
 * causes or is able to fix from inside the File Engine
 * (AiChatService does not call AiFileEngine::getContext() at all for
 * this - same root cause already disclosed for audio/images in prior
 * phases' reports) - this test only PINS today's real behavior so a
 * future phase that changes it does so deliberately, not by accident.
 */
class AiChatSendMessageVideoAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Video Attachment Test Owner',
            'email' => 'video-attachment-test-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeConversation(User $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Video attachment test conversation',
        ]);
    }

    protected function makeTrialPlan(): AiPlan
    {
        return AiPlan::query()->create([
            'name' => 'Trial Plan (video attachment test)',
            'code' => 'trial-video-attachment-test-'.uniqid(),
            'is_trial' => true,
            'is_active' => true,
            'sort_order' => 1,
            'usage_minutes' => 60,
            'cooldown_minutes' => 5,
        ]);
    }

    protected function registerChatModel(): void
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
    }

    public function test_a_metadata_only_question_about_an_attached_video_reaches_the_model_with_no_real_content_today(): void
    {
        Config::set('ai.chat.verification.enabled', false);
        $this->registerChatModel();
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
                    'content' => 'معنديش معلومات حقيقية عن الفيديو ده دلوقتي.',
                ]);
        });

        $attachment = UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4');

        $response = app(AiChatService::class)->sendMessage($owner, $conversation->id, 'الفيديو مدته كام؟', $attachment);

        $payload = $response->getData(true);
        $this->assertTrue($payload['success'], json_encode($payload));
        $this->assertNotNull($capturedMessages);

        $lastMessage = end($capturedMessages);

        // Today's real, pinned behavior: no real duration/dimensions/
        // metadata of any kind reaches the model - only the generic
        // attachment note every non-image/non-audio/non-extractable
        // attachment gets. If a future phase wires AiChatService to
        // answer this from VideoFileProcessor's real metadata instead,
        // THIS assertion is the one that must change, deliberately.
        $this->assertStringNotContainsString('duration_seconds', $lastMessage['content']);

        // Today's real, pinned behavior: buildDocumentText() always
        // returns null for a video MIME (AiDocumentTextExtractor never
        // supported any video type), so the model only ever gets the
        // GENERIC "attached a file ... cannot open or analyze its
        // contents directly" note - never VideoFileProcessor's real,
        // already-extracted duration/resolution/has-audio metadata,
        // even though that metadata genuinely exists in ai_files by the
        // time this request runs. If a future phase wires AiChatService
        // to pull that real metadata in instead, THIS assertion is the
        // one that must change, deliberately.
        // The MIME type is read from the bytes now (finfo), so a fake mp4 may report application/mp4.
        $this->assertStringContainsString('mp4', $lastMessage['content']);
        $this->assertStringContainsString('cannot open or analyze its contents directly', $lastMessage['content']);
    }
}
