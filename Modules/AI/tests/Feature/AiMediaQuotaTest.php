<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiRequest;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiMediaQuotaService;
use ReflectionMethod;
use Tests\TestCase;

/** Plan-linked daily image/video limits (ai_plans.image_daily_limit, video_daily_limit, video_max_seconds). */
class AiMediaQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Quota Owner',
            'email' => 'quota-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }

    protected function plan(array $attrs = []): AiPlan
    {
        return AiPlan::query()->create($attrs + [
            'name' => 'Plan '.uniqid(),
            'code' => 'plan-'.uniqid(),
            'usage_minutes' => 60,
            'cooldown_minutes' => 0,
            'duration_days' => 30,
            'price' => 0,
            'is_active' => true,
        ]);
    }

    public function test_null_image_limit_means_unlimited_and_zero_means_blocked(): void
    {
        $owner = $this->owner();
        $svc = app(AiMediaQuotaService::class);

        $unlimited = $svc->check($owner, $this->plan(['image_daily_limit' => null]), 'image');
        $this->assertTrue($unlimited['allowed']);
        $this->assertNull($unlimited['remaining']);

        $blocked = $svc->check($owner, $this->plan(['image_daily_limit' => 0]), 'image');
        $this->assertFalse($blocked['allowed']);
        $this->assertSame(AiMediaQuotaService::REASON_NOT_IN_PLAN, $blocked['reason']);

        $this->assertTrue($svc->check($owner, null, 'image')['allowed'], 'no plan keeps the old behaviour');
    }

    public function test_daily_image_limit_is_enforced_and_failed_generations_do_not_count(): void
    {
        $owner = $this->owner();
        $plan = $this->plan(['image_daily_limit' => 2]);
        $svc = app(AiMediaQuotaService::class);

        $first = $svc->reserve($owner, $plan, 'image');
        $second = $svc->reserve($owner, $plan, 'image');
        $this->assertNotNull($first['generation']);
        $this->assertNotNull($second['generation']);

        $third = $svc->reserve($owner, $plan, 'image');
        $this->assertNull($third['generation']);
        $this->assertSame(AiMediaQuotaService::REASON_DAILY_LIMIT, $third['decision']['reason']);

        $svc->markFailed($second['generation'], 'provider_failed', 'boom');
        $this->assertSame(1, $svc->usedToday($owner, 'image'));
        $this->assertNotNull($svc->reserve($owner, $plan, 'image')['generation']);
    }

    public function test_yesterdays_generations_do_not_count_today(): void
    {
        $owner = $this->owner();
        $plan = $this->plan(['image_daily_limit' => 1]);
        $svc = app(AiMediaQuotaService::class);

        $old = $svc->reserve($owner, $plan, 'image')['generation'];
        $old->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertTrue($svc->check($owner, $plan, 'image')['allowed']);
    }

    public function test_video_needs_both_a_daily_count_and_a_max_duration_in_the_plan(): void
    {
        $owner = $this->owner();
        $svc = app(AiMediaQuotaService::class);

        $this->assertFalse($svc->check($owner, $this->plan(), 'video')['allowed'], 'default plan has no video');
        $this->assertFalse($svc->check($owner, $this->plan(['video_daily_limit' => 3, 'video_max_seconds' => 0]), 'video')['allowed']);

        $ok = $svc->check($owner, $this->plan(['video_daily_limit' => 3, 'video_max_seconds' => 8]), 'video');
        $this->assertTrue($ok['allowed']);
        $this->assertSame(8, $ok['max_seconds']);
        $this->assertSame(3, $ok['remaining']);
    }

    public function test_quotas_are_per_owner_and_per_kind(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $plan = $this->plan(['image_daily_limit' => 1, 'video_daily_limit' => 1, 'video_max_seconds' => 4]);
        $svc = app(AiMediaQuotaService::class);

        $svc->reserve($a, $plan, 'image');
        $this->assertFalse($svc->check($a, $plan, 'image')['allowed']);
        $this->assertTrue($svc->check($b, $plan, 'image')['allowed']);
        $this->assertTrue($svc->check($a, $plan, 'video')['allowed']);
    }

    public function test_chat_image_generation_is_refused_without_calling_the_provider_when_over_the_limit(): void
    {
        $owner = $this->owner();
        $plan = $this->plan(['image_daily_limit' => 0]);
        $provider = app(AiProviderRepository::class)->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test-not-real', 'model' => 'gpt-4o-mini']);
        Http::fake();

        $conversation = AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getAuthIdentifier(),
            'title' => 'q', 'provider_key' => 'openai',
        ]);
        $userMessage = $conversation->messages()->create(['sequence_number' => 1, 'role' => AiMessage::ROLE_USER, 'content' => 'اعمل صورة قطة']);
        $aiRequest = AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
            'status' => AiRequest::STATUS_PROCESSING, 'correlation_id' => 'trace-'.uniqid(),
        ]);

        $usage = ['allowed' => true, 'reason' => null, 'plan' => $plan, 'trial_status' => null, 'remaining_seconds' => null, 'cooldown_seconds_left' => null, 'session' => null];
        $m = new ReflectionMethod(AiChatService::class, 'tryHandleImageGeneration');
        $m->setAccessible(true);
        $response = $m->invoke(app(AiChatService::class), $owner, $conversation, $userMessage, $aiRequest, 'اعمل صورة قطة', ['provider' => $provider, 'model_key' => 'gpt-image-1'], $usage);

        Http::assertNothingSent();
        $this->assertSame(__('ai.image_quota_not_in_plan'), $response->getData(true)['data']['assistant_message']['content']);
        $this->assertSame(0, AiMediaGeneration::query()->count());
    }

    public function test_chat_image_generation_consumes_a_slot_on_success_and_gives_it_back_on_failure(): void
    {
        $owner = $this->owner();
        $plan = $this->plan(['image_daily_limit' => 1]);
        $provider = app(AiProviderRepository::class)->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test-not-real', 'model' => 'gpt-4o-mini']);
        $svc = app(AiMediaQuotaService::class);

        $run = function () use ($owner, $plan, $provider) {
            $conversation = AiConversation::query()->create([
                'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getAuthIdentifier(),
                'title' => 'q', 'provider_key' => 'openai',
            ]);
            $userMessage = $conversation->messages()->create(['sequence_number' => 1, 'role' => AiMessage::ROLE_USER, 'content' => 'اعمل صورة قطة']);
            $aiRequest = AiRequest::query()->create([
                'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
                'status' => AiRequest::STATUS_PROCESSING, 'correlation_id' => 'trace-'.uniqid(),
            ]);
            $usage = ['allowed' => true, 'reason' => null, 'plan' => $plan, 'trial_status' => null, 'remaining_seconds' => null, 'cooldown_seconds_left' => null, 'session' => null];
            $m = new ReflectionMethod(AiChatService::class, 'tryHandleImageGeneration');
            $m->setAccessible(true);

            return $m->invoke(app(AiChatService::class), $owner, $conversation, $userMessage, $aiRequest, 'اعمل صورة قطة', ['provider' => $provider, 'model_key' => 'gpt-image-1'], $usage);
        };

        // 1st run: both the call and its single retry fail; 2nd run succeeds.
        Http::fake(['api.openai.com/v1/images/generations' => Http::sequence()
            ->push(['error' => ['message' => 'x']], 500)
            ->push(['error' => ['message' => 'x']], 500)
            ->push(['data' => [['b64_json' => base64_encode('png')]]], 200)]);

        $run();
        $this->assertSame(0, $svc->usedToday($owner, 'image'), 'failed call gives the slot back');

        $run();
        $this->assertSame(1, $svc->usedToday($owner, 'image'));

        Http::assertSentCount(3);
        $blocked = $run();
        Http::assertSentCount(3); // limit reached: the provider was not called again
        $this->assertStringContainsString('1', $blocked->getData(true)['data']['assistant_message']['content']);
    }
}
