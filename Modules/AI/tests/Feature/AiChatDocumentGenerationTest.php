<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiDocumentGeneration;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiGateway;
use Modules\User\Models\User;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Feature: AI chat "give me this as a file" now produces a real PDF/DOCX/
 * XLSX (via dompdf/PhpWord/PhpSpreadsheet) instead of always dumping the
 * raw chat reply into a .md file. The model that answered is asked to
 * restructure its own reply into a strict markup subset first
 * (AiDocumentContentParser), which is then rendered by format
 * (AiDocumentRenderer). generateDownloadableFile() falls back to the old
 * raw-.md-dump behaviour on any failure so the user is never left without
 * a file, and always leaves an honest AiDocumentGeneration log row behind.
 */
class AiChatDocumentGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Document Owner',
            'email' => 'doc-owner-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeConversation(User $owner): AiConversation
    {
        return AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Document generation test conversation',
        ]);
    }

    protected function makeProvider(): AiProvider
    {
        return AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'openai (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function invokeDetectFormat(string $content): string
    {
        $method = new ReflectionMethod(AiChatService::class, 'detectRequestedFileFormat');
        $method->setAccessible(true);

        return $method->invoke(app(AiChatService::class), $content);
    }

    protected function invokeGenerate(
        User $owner,
        AiConversation $conversation,
        string $prompt,
        string $replyContent,
        ?AiProvider $provider,
        ?string $model,
        ?AiRequest $aiRequest,
    ): array {
        $method = new ReflectionMethod(AiChatService::class, 'generateDownloadableFile');
        $method->setAccessible(true);

        return $method->invoke(
            app(AiChatService::class),
            $owner,
            $conversation,
            $prompt,
            $replyContent,
            $provider,
            $model,
            $aiRequest,
        );
    }

    public function test_format_detection_picks_the_keyword_the_user_actually_used(): void
    {
        $this->assertSame('xlsx', $this->invokeDetectFormat('ممكن تديهولي اكسيل؟'));
        $this->assertSame('docx', $this->invokeDetectFormat('send it as a word document'));
        $this->assertSame('pdf', $this->invokeDetectFormat('عايزها PDF'));
    }

    public function test_format_detection_defaults_to_pdf_when_no_format_is_named(): void
    {
        $this->assertSame('pdf', $this->invokeDetectFormat('حمله ملف'));
        $this->assertSame('pdf', $this->invokeDetectFormat('send it as a file'));
    }

    public function test_generating_a_pdf_asks_the_model_to_restructure_its_own_reply_and_completes_the_log_row(): void
    {
        Storage::fake('public');

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);
        $provider = $this->makeProvider();

        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => '',
                    'content' => "# Egyptian Agriculture\n\nEgypt relies heavily on the Nile for irrigation.\n\n## Key Crops\n\n- Cotton\n- Wheat\n- Rice",
                ]);
        });

        $result = $this->invokeGenerate(
            $owner,
            $conversation,
            'اعملى بحث علمى عن الزراعه فى مصر PDF',
            'Egypt relies heavily on the Nile for irrigation. Key crops include cotton, wheat and rice.',
            $provider,
            'gpt-4o-mini',
            $aiRequest,
        );

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertStringEndsWith('.pdf', $result['name']);

        $generation = AiDocumentGeneration::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($generation);
        $this->assertSame(AiDocumentGeneration::STATUS_COMPLETED, $generation->status);
        $this->assertSame('pdf', $generation->output_format);
        $this->assertSame($result['name'], $generation->output_file_name);
        $this->assertNotNull($generation->output_file_path);

        $bytes = Storage::disk('public')->get($generation->output_file_path);
        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_generating_without_a_usable_provider_still_returns_a_file_and_falls_back_cleanly(): void
    {
        Storage::fake('public');

        $owner = $this->makeOwner();
        $conversation = $this->makeConversation($owner);

        // No provider/model/context passed in -> structureContentForDocument()
        // is skipped entirely (its guard is `$usedProvider && $usedModel`),
        // so this exercises the parser running directly on the raw reply.
        $result = $this->invokeGenerate(
            $owner,
            $conversation,
            'حمله ملف',
            "# Fallback Report\n\nThis is the raw reply content.",
            null,
            null,
            null,
        );

        $this->assertArrayHasKey('name', $result);
        $this->assertStringEndsWith('.pdf', $result['name']);

        $generation = AiDocumentGeneration::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($generation);
        $this->assertSame(AiDocumentGeneration::STATUS_COMPLETED, $generation->status);
    }
}
