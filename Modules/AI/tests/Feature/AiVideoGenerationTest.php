<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\Admin;
use Modules\AI\Jobs\AdvanceAiVideoJob;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiRequest;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiMediaQuotaService;
use Modules\AI\Services\AiVideoGenerationService;
use ReflectionMethod;
use Tests\TestCase;

class AiVideoGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Video Owner', 'email' => 'video-'.uniqid().'@example.test', 'password' => 'password', 'status' => true,
        ]);
    }

    protected function plan(array $attrs = []): AiPlan
    {
        return AiPlan::query()->create($attrs + [
            'name' => 'Plan '.uniqid(), 'code' => 'plan-'.uniqid(), 'usage_minutes' => 60, 'cooldown_minutes' => 0,
            'duration_days' => 30, 'price' => 0, 'is_active' => true,
            'video_daily_limit' => 2, 'video_max_seconds' => 8,
        ]);
    }

    protected function provider()
    {
        return app(AiProviderRepository::class)->updateByKey('google', ['is_enabled' => true, 'api_key' => 'g-test-not-real', 'model' => 'veo-3.1-generate-preview']);
    }

    protected function candidates(): array
    {
        return [['provider' => $this->provider(), 'model_key' => 'veo-3.1-generate-preview', 'capability_matched' => true]];
    }

    /** @return array{0: AiConversation, 1: AiMessage, 2: AiRequest} */
    protected function chat(Admin $owner, string $text = 'اعمللي فيديو لقطة بتجري'): array
    {
        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getAuthIdentifier(), 'title' => 'v', 'provider_key' => 'openai',
        ]);
        $message = $conversation->messages()->create(['sequence_number' => 1, 'role' => AiMessage::ROLE_USER, 'content' => $text]);
        $request = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'status' => AiRequest::STATUS_PROCESSING, 'correlation_id' => 'trace-'.uniqid(),
        ]);

        return [$conversation, $message, $request];
    }

    protected function handle(Admin $owner, ?AiPlan $plan, array $candidates, string $text = 'اعمللي فيديو لقطة بتجري')
    {
        [$conversation, $message, $request] = $this->chat($owner, $text);
        $usage = ['allowed' => true, 'reason' => null, 'plan' => $plan, 'trial_status' => null, 'remaining_seconds' => null, 'cooldown_seconds_left' => null, 'session' => null];
        $m = new ReflectionMethod(AiChatService::class, 'handleVideoRequest');
        $m->setAccessible(true);

        return $m->invoke(app(AiChatService::class), $owner, $conversation, $message, $request, $text, $candidates, $usage);
    }

    public function test_pick_seconds_respects_plan_max_provider_options_and_request(): void
    {
        $svc = app(AiVideoGenerationService::class);
        $plan = $this->plan(['video_max_seconds' => 8]);

        $this->assertSame(4, $svc->pickSeconds([4, 8, 12], $plan, null), 'default is the cheapest clip');
        $this->assertSame(8, $svc->pickSeconds([4, 8, 12], $plan, 7));
        $this->assertSame(8, $svc->pickSeconds([4, 8, 12], $plan, 12), 'never above the plan max');
        $this->assertNull($svc->pickSeconds([4, 8, 12], $this->plan(['video_max_seconds' => 3]), null));
        $this->assertNull($svc->pickSeconds([], $plan, null), 'provider without video');
        $this->assertNull($svc->pickSeconds([4], $this->plan(['video_max_seconds' => 0]), null));
    }

    public function test_requested_seconds_are_read_from_arabic_and_english_text(): void
    {
        $svc = app(AiVideoGenerationService::class);

        $this->assertSame(8, $svc->requestedSeconds('اعمل فيديو 8 ثواني'));
        $this->assertSame(10, $svc->requestedSeconds('اعمل فيديو ١٠ ثانية'));
        $this->assertSame(12, $svc->requestedSeconds('make a 12 seconds video'));
        $this->assertNull($svc->requestedSeconds('اعمل فيديو لقطة'));
    }

    public function test_chat_starts_a_job_and_answers_with_a_placeholder(): void
    {
        Queue::fake();
        $owner = $this->owner();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'operations/op1'], 200)]);

        $response = $this->handle($owner, $this->plan(), $this->candidates(), 'اعمللي فيديو 8 ثواني لقطة');

        $data = $response->getData(true)['data'];
        $this->assertStringContainsString('8', $data['assistant_message']['content']);
        $generation = AiMediaGeneration::query()->firstOrFail();
        $this->assertSame('video', $generation->kind);
        $this->assertSame('processing', $generation->status);
        $this->assertSame('operations/op1', $generation->provider_job_id);
        $this->assertSame(8, $generation->requested_seconds);
        $this->assertSame($data['assistant_message']['id'], $generation->message_id);
        Queue::assertPushed(AdvanceAiVideoJob::class);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'veo-3.1-generate-preview:predictLongRunning')
            && $r['parameters']['durationSeconds'] === '8'
            && $r->header('x-goog-api-key')[0] === 'g-test-not-real');
    }

    public function test_plan_without_video_never_reaches_the_provider(): void
    {
        Queue::fake();
        Http::fake();
        $owner = $this->owner();

        $response = $this->handle($owner, $this->plan(['video_daily_limit' => 0, 'video_max_seconds' => 0]), $this->candidates());

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(__('ai.video_quota_not_in_plan'), $response->getData(true)['data']['assistant_message']['content']);
        $this->assertSame(0, AiMediaGeneration::query()->count());
    }

    public function test_daily_video_limit_blocks_the_next_request(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $plan = $this->plan(['video_daily_limit' => 1]);
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'operations/op1'], 200)]);
        $candidate = $this->candidates();

        $this->handle($owner, $plan, $candidate);
        $second = $this->handle($owner, $plan, $candidate);

        Http::assertSentCount(1);
        $this->assertStringContainsString('1', $second->getData(true)['data']['assistant_message']['content']);
        $this->assertSame(1, AiMediaGeneration::query()->count());
    }

    public function test_no_capable_model_gets_an_honest_message(): void
    {
        Http::fake();
        $response = $this->handle($this->owner(), $this->plan(), []);

        Http::assertNothingSent();
        $this->assertSame(__('ai.video_generation_unavailable'), $response->getData(true)['data']['assistant_message']['content']);
    }

    public function test_provider_start_failure_gives_the_slot_back(): void
    {
        Queue::fake();
        $owner = $this->owner();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['error' => ['message' => 'billing required']], 403)]);

        $response = $this->handle($owner, $this->plan(), $this->candidates());

        $this->assertSame(__('ai.video_generation_failed'), $response->getData(true)['data']['assistant_message']['content']);
        $this->assertSame('failed', AiMediaGeneration::query()->firstOrFail()->status);
        $this->assertSame(0, app(AiMediaQuotaService::class)->usedToday($owner, 'video'));
        Queue::assertNothingPushed();
    }

    protected function startedGeneration(Admin $owner): AiMediaGeneration
    {
        Queue::fake();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'operations/op1'], 200)]);
        $this->handle($owner, $this->plan(), $this->candidates());

        return AiMediaGeneration::query()->firstOrFail();
    }

    public function test_advance_keeps_polling_then_attaches_the_finished_video(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $generation = $this->startedGeneration($owner);

        $done = ['done' => true, 'response' => ['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => 'https://files.example.test/v/abc']]]]]];
        Http::fake([
            'files.example.test/*' => Http::response('FAKE-MP4-BYTES', 200),
            'generativelanguage.googleapis.com/v1beta/operations/op1' => Http::sequence()
                ->push(['done' => false], 200)
                ->push($done, 200)
                ->push($done, 200), // download re-reads the operation for the file uri
        ]);

        $svc = app(AiVideoGenerationService::class);
        $this->assertTrue($svc->advance($generation->fresh()), 'still running');
        $this->assertSame('processing', $generation->fresh()->status);

        $this->assertFalse($svc->advance($generation->fresh()), 'done');
        $generation = $generation->fresh();
        $this->assertSame('completed', $generation->status);

        $message = $generation->message->fresh('attachments');
        $this->assertSame(__('ai.video_generation_success'), $message->content);
        $attachment = $message->attachments->first();
        $this->assertSame('video/mp4', $attachment->mime_type);
        Storage::disk('public')->assertExists($attachment->file_path);
        $this->assertSame(1, app(AiMediaQuotaService::class)->usedToday($owner, 'video'));
    }

    public function test_provider_failure_marks_failed_and_frees_the_quota(): void
    {
        $owner = $this->owner();
        $generation = $this->startedGeneration($owner);
        Http::fake(['generativelanguage.googleapis.com/v1beta/operations/op1' => Http::response(['done' => true, 'error' => ['message' => 'moderation']], 200)]);

        $this->assertFalse(app(AiVideoGenerationService::class)->advance($generation->fresh()));

        $generation = $generation->fresh();
        $this->assertSame('failed', $generation->status);
        $this->assertTrue((bool) $generation->message->is_error);
        $this->assertSame(__('ai.video_generation_failed'), $generation->message->content);
        $this->assertSame(0, app(AiMediaQuotaService::class)->usedToday($owner, 'video'));
    }

    public function test_a_job_that_runs_too_long_times_out_without_calling_the_provider(): void
    {
        $owner = $this->owner();
        $generation = $this->startedGeneration($owner);
        $generation->forceFill(['started_at' => now()->subSeconds((int) config('ai.video.timeout') + 60)])->save();
        Http::fake();

        $this->assertFalse(app(AiVideoGenerationService::class)->advance($generation->fresh()));

        Http::assertNothingSent();
        $this->assertSame('timeout', $generation->fresh()->error_code);
        $this->assertSame(__('ai.video_generation_timeout'), $generation->fresh()->message->content);
    }

    public function test_a_retired_openai_sora_model_is_skipped_in_favour_of_a_working_one(): void
    {
        Queue::fake();
        $openai = app(AiProviderRepository::class)->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test-not-real', 'model' => 'sora-2']);
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'operations/op1'], 200)]);

        $candidates = [['provider' => $openai, 'model_key' => 'sora-2', 'capability_matched' => true]] + [1 => $this->candidates()[0]];
        $this->handle($this->owner(), $this->plan(), array_values($candidates));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage.googleapis.com'));
        $this->assertSame('operations/op1', AiMediaGeneration::query()->firstOrFail()->provider_job_id);
    }

    public function test_only_a_retired_sora_model_means_video_is_unavailable_not_a_plan_problem(): void
    {
        Http::fake();
        $openai = app(AiProviderRepository::class)->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test-not-real', 'model' => 'sora-2']);

        $response = $this->handle($this->owner(), $this->plan(), [['provider' => $openai, 'model_key' => 'sora-2', 'capability_matched' => true]]);

        Http::assertNothingSent();
        $this->assertSame(__('ai.video_generation_unavailable'), $response->getData(true)['data']['assistant_message']['content']);
    }
}
