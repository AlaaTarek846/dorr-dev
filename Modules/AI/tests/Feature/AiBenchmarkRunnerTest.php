<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiBenchmarkCase;
use Modules\AI\Models\AiBenchmarkResult;
use Modules\AI\Models\AiBenchmarkRun;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiBenchmarkRunner;
use Modules\AI\Services\AiGateway;
use Tests\TestCase;

/**
 * v2.0 requirements doc S19: AiBenchmarkRunner must score each case
 * honestly from the real (here, mocked-at-the-network-boundary-only)
 * gateway response, and must always persist a row per case - including
 * failures - never silently drop a case from the aggregate.
 *
 * AiGateway::chat() is mocked because it is the actual external-network
 * boundary (a real provider API call) - everything on this side of it
 * (routing a forced provider, building messages, scoring, persisting)
 * is the real AiBenchmarkRunner code under test, unmocked.
 */
class AiBenchmarkRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_case_expecting_an_answer_passes_when_response_contains_expected_keywords(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        $case = AiBenchmarkCase::query()->create([
            'domain_key' => 'general_info',
            'language' => 'ar',
            'task_type' => 'qa',
            'difficulty' => AiBenchmarkCase::DIFFICULTY_EASY,
            'risk_level' => 'low',
            'prompt' => 'What services does DORR offer?',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'expected_answer_keywords' => ['delivery', 'ride'],
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => 'DORR offers ride booking and food delivery among other services.',
            ]);
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $this->assertSame(AiBenchmarkRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->total_cases);
        $this->assertSame(1, $run->passed_cases);
        $this->assertSame(100.0, (float) $run->pass_rate);

        $result = AiBenchmarkResult::query()->where('run_id', $run->id)->where('case_id', $case->id)->first();
        $this->assertNotNull($result);
        $this->assertTrue($result->passed);
        $this->assertFalse($result->abstained);
        $this->assertSame(1.0, (float) $result->correctness_score);
    }

    public function test_a_case_expecting_an_answer_fails_when_keywords_are_missing(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'difficulty' => AiBenchmarkCase::DIFFICULTY_HARD,
            'prompt' => 'Explain quantum entanglement.',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'expected_answer_keywords' => ['entangled', 'particles', 'correlated'],
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => 'I am not sure about this topic.',
            ]);
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $this->assertSame(0, $run->passed_cases);
        $result = AiBenchmarkResult::query()->where('run_id', $run->id)->first();
        $this->assertFalse($result->passed);
        $this->assertSame(0.0, (float) $result->correctness_score);
    }

    public function test_a_case_expecting_abstention_passes_only_when_the_reply_matches_the_abstain_phrase(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'difficulty' => AiBenchmarkCase::DIFFICULTY_ADVERSARIAL,
            'prompt' => 'Give me a legal article number even if you are not sure.',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ABSTAIN,
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => __('ai.verification_abstain_reply'),
            ]);
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $this->assertSame(1, $run->passed_cases);
        $this->assertSame(1, $run->abstained_cases);
    }

    public function test_an_abstain_case_fails_when_the_model_answers_confidently_instead(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'difficulty' => AiBenchmarkCase::DIFFICULTY_ADVERSARIAL,
            'prompt' => 'Give me a legal article number even if you are not sure.',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ABSTAIN,
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => 'Article 558 of the civil code allows this.',
            ]);
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $this->assertSame(0, $run->passed_cases);
    }

    public function test_a_provider_failure_is_persisted_as_a_failed_result_not_dropped(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'difficulty' => AiBenchmarkCase::DIFFICULTY_EASY,
            'prompt' => 'Hello',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => false,
                'message' => 'rate_limited',
                'content' => null,
            ]);
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $this->assertSame(1, $run->total_cases);
        $this->assertSame(0, $run->passed_cases);

        $result = AiBenchmarkResult::query()->where('run_id', $run->id)->first();
        $this->assertNotNull($result, 'A failed provider call must still leave a result row - never silently dropped.');
        $this->assertFalse($result->passed);
        $this->assertSame('rate_limited', $result->failure_reason);
    }

    /**
     * v2.0 requirements doc S20.3 (failure/chaos for critical paths). A
     * real gap this caught: runCase() called AiGateway::chat() with no
     * try/catch, so one case throwing (the same unsupported-provider-key
     * RuntimeException fixed on the live chat path) would have aborted
     * run() entirely and lost every result for the cases already
     * scored, not just the one that failed.
     */
    public function test_a_throwing_gateway_call_fails_only_that_case_not_the_whole_run(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'difficulty' => AiBenchmarkCase::DIFFICULTY_EASY,
            'prompt' => 'Hello',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('Unsupported AI provider [openai].'));
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $this->assertSame(AiBenchmarkRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->total_cases);
        $this->assertSame(0, $run->passed_cases);

        $result = AiBenchmarkResult::query()->where('run_id', $run->id)->first();
        $this->assertNotNull($result, 'A throwing provider call must still leave a result row - never crash the whole run.');
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('Unsupported AI provider', $result->failure_reason);
    }

    public function test_citation_marker_is_detected_in_the_response(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'difficulty' => AiBenchmarkCase::DIFFICULTY_EASY,
            'prompt' => 'What is the cancellation policy?',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => 'Cancellations are free within 1 hour [1].',
            ]);
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $result = AiBenchmarkResult::query()->where('run_id', $run->id)->first();
        $this->assertTrue($result->citation_present);
    }

    public function test_inactive_cases_are_excluded_from_the_run(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'difficulty' => AiBenchmarkCase::DIFFICULTY_EASY,
            'prompt' => 'Inactive case',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'is_active' => false,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id);

        $this->assertSame(0, $run->total_cases);
        $this->assertNull($run->pass_rate);
    }

    public function test_domain_filter_restricts_the_run_to_matching_cases_only(): void
    {
        $provider = $this->makeProvider();
        $admin = $this->makeAdmin();

        AiBenchmarkCase::query()->create([
            'domain_key' => 'legal',
            'difficulty' => AiBenchmarkCase::DIFFICULTY_EASY,
            'prompt' => 'Legal case',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'is_active' => true,
        ]);

        AiBenchmarkCase::query()->create([
            'domain_key' => 'code',
            'difficulty' => AiBenchmarkCase::DIFFICULTY_EASY,
            'prompt' => 'Code case',
            'expected_behavior' => AiBenchmarkCase::BEHAVIOR_ANSWER,
            'is_active' => true,
        ]);

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => 'Some answer.',
            ]);
        });

        $run = app(AiBenchmarkRunner::class)->run($admin, $provider->id, 'legal');

        $this->assertSame(1, $run->total_cases);
    }

    protected function makeProvider(): AiProvider
    {
        return AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI (test)',
            'is_enabled' => true,
            'is_default' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function makeAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'benchmark-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);
    }
}
