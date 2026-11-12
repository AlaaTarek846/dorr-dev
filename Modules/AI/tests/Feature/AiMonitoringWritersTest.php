<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Http\Resources\AiConversationAttachmentResource;
use Modules\AI\Models\AiAuditEvent;
use Modules\AI\Models\AiBenchmarkCase;
use Modules\AI\Models\AiBenchmarkRun;
use Modules\AI\Models\AiCodeExecution;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiDataPolicy;
use Modules\AI\Models\AiProject;
use Modules\AI\Models\AiProjectContext;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiProviderHealth;
use Modules\AI\Models\AiProviderLog;
use Modules\AI\Models\AiReliabilityMetric;
use Modules\AI\Models\AiRequest;
use Modules\AI\Models\AiSafetyEvent;
use Modules\AI\Models\AiSafetyPolicy;
use Modules\AI\Models\AiSafetyRule;
use Modules\AI\Models\AiSafetyScan;
use Modules\AI\Models\AiSecurityEvent;
use Modules\AI\Services\AiBenchmarkRunner;
use Modules\AI\Services\AiChatService;
use Modules\AI\Services\AiCircuitBreaker;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiTaskRunner;
use Modules\AI\Services\AiUserProjectService;
use Modules\AI\Services\Monitoring\AiSafetyScanRecorder;
use Modules\AI\Services\Monitoring\AiSecurityEventRecorder;
use Modules\User\Models\User;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The AI log / monitoring tables that used to stay empty or grow for ever
 * (2026-11-11): reliability metrics, safety scans, security events,
 * project context, provider-log http_status, provider-health state
 * changes, retention of code executions / audit events, request
 * error_code / retry_count, and the privacy redaction of safety events.
 */
class AiMonitoringWritersTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProvider(string $key = 'openai'): AiProvider
    {
        return AiProvider::query()->create([
            'key' => $key,
            'name' => $key.' (test)',
            'is_enabled' => true,
            'is_default' => $key === 'openai',
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function makeUser(?string $phone = null): User
    {
        return User::query()->create([
            'name' => 'U',
            'email' => 'u-'.uniqid().'@example.test',
            'phone' => $phone,
            'password' => bcrypt('x-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function makeRequest(User $owner): AiRequest
    {
        return AiRequest::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'correlation_id' => 'trace-'.uniqid(),
            'status' => AiRequest::STATUS_PROCESSING,
        ]);
    }

    // ---- ai_provider_logs.http_status -------------------------------------------------------

    public function test_provider_log_records_the_http_status_of_the_provider_answer(): void
    {
        $provider = $this->makeProvider();
        $request = $this->makeRequest($this->makeUser());

        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'rate limited']], 429)
            ->push(['choices' => [['message' => ['content' => 'hello']]]], 200)]);

        $result = app(AiGateway::class)->chat($provider, [['role' => 'user', 'content' => 'hi']], $request);
        $this->assertFalse($result['success']);
        app(AiGateway::class)->chat($provider, [['role' => 'user', 'content' => 'hi']], $request);

        $statuses = AiProviderLog::query()->where('request_id', $request->id)->orderBy('id')->pluck('http_status')->all();
        $this->assertSame([429, 200], array_map('intval', $statuses));
    }

    // ---- ai_provider_health: state changes only + recovering ----------------------------------

    public function test_provider_health_is_written_only_when_the_state_changes(): void
    {
        config(['ai.circuit_breaker.failure_threshold' => 3, 'ai.circuit_breaker.open_seconds' => 60]);
        $provider = $this->makeProvider();
        $breaker = app(AiCircuitBreaker::class);

        $breaker->recordSuccess($provider);
        $breaker->recordSuccess($provider);
        $this->assertSame(0, AiProviderHealth::query()->count(), 'a healthy provider writes nothing per call');

        $breaker->recordFailure($provider);
        $breaker->recordFailure($provider);
        $this->assertSame(1, AiProviderHealth::query()->count(), 'two failures = one degraded row updated in place');
        $this->assertSame(2, AiProviderHealth::query()->latest('id')->first()->details['consecutive_failures']);

        $breaker->recordFailure($provider);
        $this->assertTrue($breaker->isOpen($provider));
        $this->assertSame(2, AiProviderHealth::query()->count());

        $breaker->recordSuccess($provider);
        $breaker->recordSuccess($provider);
        $this->assertSame(3, AiProviderHealth::query()->count(), 'back to healthy = one row');
        $this->assertSame(AiProviderHealth::STATUS_HEALTHY, AiProviderHealth::query()->latest('id')->first()->status);
    }

    public function test_an_elapsed_cooldown_goes_through_recovering_and_a_failed_probe_reopens(): void
    {
        config(['ai.circuit_breaker.failure_threshold' => 1, 'ai.circuit_breaker.open_seconds' => 60]);
        $provider = $this->makeProvider();
        $breaker = app(AiCircuitBreaker::class);

        $breaker->recordFailure($provider);
        $this->assertTrue($breaker->isOpen($provider));

        $this->travel(2)->minutes();

        $this->assertFalse($breaker->isOpen($provider), 'cooldown over: half-open probe let through');
        $this->assertSame(AiProviderHealth::STATUS_RECOVERING, AiProviderHealth::query()->latest('id')->first()->status);

        $this->assertFalse($breaker->isOpen($provider), 'still recovering: probes keep going through');
        $this->assertSame(2, AiProviderHealth::query()->count(), 'recovering is written once');

        $breaker->recordFailure($provider);
        $this->assertTrue($breaker->isOpen($provider), 'a failed probe re-opens the circuit');
        $this->assertSame(AiProviderHealth::STATUS_UNHEALTHY, AiProviderHealth::query()->latest('id')->first()->status);
    }

    // ---- ai:enforce-retention -----------------------------------------------------------------

    public function test_retention_purges_code_executions_audit_events_scans_and_old_health_but_keeps_the_current_state(): void
    {
        AiDataPolicy::query()->create([
            'name' => 'personal',
            'data_classification' => AiDataPolicy::CLASSIFICATION_PERSONAL,
            'retention_days' => 30,
            'is_active' => true,
        ]);
        $provider = $this->makeProvider();
        $old = now()->subDays(60);

        $exec = AiCodeExecution::query()->create(['language' => 'php', 'driver' => 'docker', 'code' => '<?php echo 1;', 'status' => AiCodeExecution::STATUS_COMPLETED]);
        $recentExec = AiCodeExecution::query()->create(['language' => 'php', 'driver' => 'docker', 'code' => '<?php echo 2;', 'status' => AiCodeExecution::STATUS_COMPLETED]);
        DB::table('ai_code_executions')->where('id', $exec->id)->update(['created_at' => $old]);

        $auditId = DB::table('ai_audit_events')->insertGetId(['event_type' => 'test', 'severity' => 'low', 'created_at' => $old]);
        $scan = AiSafetyScan::query()->create(['target_type' => 'input', 'scan_type' => 'harm', 'decision' => 'passed']);
        DB::table('ai_safety_scans')->where('id', $scan->id)->update(['created_at' => $old]);

        $h1 = AiProviderHealth::query()->create(['provider_id' => $provider->id, 'check_type' => 'functional', 'status' => 'degraded']);
        $h2 = AiProviderHealth::query()->create(['provider_id' => $provider->id, 'check_type' => 'functional', 'status' => 'unhealthy']);
        DB::table('ai_provider_health')->whereIn('id', [$h1->id, $h2->id])->update(['created_at' => $old]);

        $this->artisan('ai:enforce-retention')->assertSuccessful();

        $this->assertDatabaseMissing('ai_code_executions', ['id' => $exec->id]);
        $this->assertDatabaseHas('ai_code_executions', ['id' => $recentExec->id]);
        $this->assertSame(0, AiAuditEvent::query()->whereKey($auditId)->count());
        $this->assertDatabaseMissing('ai_safety_scans', ['id' => $scan->id]);
        $this->assertDatabaseMissing('ai_provider_health', ['id' => $h1->id]);
        $this->assertDatabaseHas('ai_provider_health', ['id' => $h2->id]);
    }

    public function test_code_executions_can_have_their_own_retention_window(): void
    {
        AiDataPolicy::query()->create([
            'name' => 'personal',
            'data_classification' => AiDataPolicy::CLASSIFICATION_PERSONAL,
            'retention_days' => 365,
            'is_active' => true,
        ]);
        config(['ai.retention.code_executions_days' => 7]);

        $exec = AiCodeExecution::query()->create(['language' => 'php', 'driver' => 'docker', 'code' => 'x', 'status' => AiCodeExecution::STATUS_COMPLETED]);
        DB::table('ai_code_executions')->where('id', $exec->id)->update(['created_at' => now()->subDays(10)]);

        $this->artisan('ai:enforce-retention')->assertSuccessful();

        $this->assertDatabaseMissing('ai_code_executions', ['id' => $exec->id]);
    }

    // ---- ai_reliability_metrics -----------------------------------------------------------------

    public function test_reliability_metrics_are_aggregated_from_the_provider_logs(): void
    {
        $provider = $this->makeProvider();
        $lastHour = now()->startOfHour()->subHour()->addMinutes(10);

        foreach ([100, 200, 300, 400] as $i => $ms) {
            $log = AiProviderLog::query()->create([
                'provider_id' => $provider->id,
                'response_time_ms' => $ms,
                'error_code' => $i === 3 ? 'provider_error' : null,
            ]);
            DB::table('ai_provider_logs')->where('id', $log->id)->update(['created_at' => $lastHour]);
        }

        $this->artisan('ai:aggregate-reliability')->assertSuccessful();
        $this->artisan('ai:aggregate-reliability')->assertSuccessful();

        $metrics = AiReliabilityMetric::query()->where('provider_id', $provider->id)->get();
        $this->assertCount(1, $metrics, 're-running an hour replaces its row');
        $metric = $metrics->first();
        $this->assertNull($metric->intent_id);
        $this->assertEquals(75, (float) $metric->success_rate);
        $this->assertEquals(25, (float) $metric->error_rate);
        $this->assertSame(200, (int) $metric->latency_p50_ms);
        $this->assertSame(400, (int) $metric->latency_p95_ms);
    }

    // ---- ai_safety_scans + ai_safety_events privacy ---------------------------------------------

    public function test_every_safety_check_is_recorded_without_the_text(): void
    {
        $user = $this->makeUser();
        $recorder = app(AiSafetyScanRecorder::class);

        $recorder->record(['action' => AiSafetyRule::ACTION_ALLOW, 'rule' => null, 'sanitized' => null], $user);

        $policy = AiSafetyPolicy::query()->create(['name' => 'Weapons', 'risk_level' => 'high', 'is_active' => true]);
        $rule = AiSafetyRule::query()->create([
            'safety_policy_id' => $policy->id, 'name' => 'No explosives', 'action' => AiSafetyRule::ACTION_BLOCK,
            'condition' => ['type' => 'keyword_match', 'keywords' => ['explosive']], 'is_active' => true,
        ]);
        $recorder->record(['action' => AiSafetyRule::ACTION_BLOCK, 'rule' => $rule, 'sanitized' => null], $user);

        $scans = AiSafetyScan::query()->orderBy('id')->get();
        $this->assertSame(['passed', 'blocked'], $scans->pluck('decision')->all());
        $this->assertSame('input', $scans[1]->target_type);
        $this->assertSame($rule->id, $scans[1]->findings['rule_id']);
        $this->assertStringNotContainsString('explosive', json_encode($scans[1]->findings));
    }

    public function test_a_blocked_tool_message_is_scanned_and_its_safety_event_is_redacted(): void
    {
        $user = $this->makeUser();
        $policy = AiSafetyPolicy::query()->create(['name' => 'Weapons', 'risk_level' => 'high', 'is_active' => true]);
        AiSafetyRule::query()->create([
            'safety_policy_id' => $policy->id, 'name' => 'No explosives', 'action' => AiSafetyRule::ACTION_BLOCK, 'priority' => 10,
            'condition' => ['type' => 'keyword_match', 'keywords' => ['explosive']], 'is_active' => true,
        ]);

        $result = app(AiTaskRunner::class)->chat(
            [['role' => 'user', 'content' => 'explosive help, mail me at someone@example.com']],
            'test.tool',
            $user,
            true,
        );

        $this->assertFalse($result['success']);
        $event = AiSafetyEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('someone@example.com', (string) $event->reason);
        $this->assertStringContainsString('[email]', (string) $event->reason);
        $this->assertSame(AiSafetyScan::DECISION_BLOCKED, AiSafetyScan::query()->latest('id')->value('decision'));
    }

    // ---- ai_security_events -----------------------------------------------------------------------

    public function test_a_refused_chat_request_is_a_rate_limit_security_event_once_a_minute(): void
    {
        $user = $this->makeUser();
        $request = Request::create('/api/user/v1/ai-chat/messages', 'POST');
        $request->setUserResolver(fn () => $user);

        $limit = RateLimiter::limiter('ai-chat-send')($request);

        foreach ([1, 2] as $attempt) {
            try {
                ($limit->responseCallback)($request, ['Retry-After' => 60]);
                $this->fail('the limiter must still answer with ThrottleRequestsException');
            } catch (ThrottleRequestsException $e) {
                $this->assertSame(429, $e->getStatusCode());
            }
        }

        $events = AiSecurityEvent::query()->get();
        $this->assertCount(1, $events);
        $this->assertSame(AiSecurityEventRecorder::EVENT_RATE_LIMIT_EXCEEDED, $events[0]->event_type);
        $this->assertSame($user->id, (int) $events[0]->owner_id);
    }

    public function test_a_blocked_owner_using_an_ai_tool_is_a_security_event(): void
    {
        $user = $this->makeUser();
        \Modules\AI\Models\AiTrialControl::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id,
            'trial_status' => \Modules\AI\Models\AiTrialControl::TRIAL_ELIGIBLE,
            'abuse_status' => \Modules\AI\Models\AiTrialControl::ABUSE_BLOCKED,
        ]);

        app(AiTaskRunner::class)->chat([['role' => 'user', 'content' => 'hello']], 'test.tool', $user, true);

        $this->assertSame(AiSecurityEventRecorder::EVENT_BLOCKED_OWNER_ATTEMPT, AiSecurityEvent::query()->value('event_type'));
    }

    // ---- ai_project_context -------------------------------------------------------------------------

    public function test_a_capsule_from_a_project_conversation_becomes_the_projects_context(): void
    {
        $user = $this->makeUser();
        $conversation = AiConversation::query()->create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'title' => 'Plan']);
        $project = AiProject::query()->create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'name' => 'Shop', 'status' => 'active']);
        $project->projectConversations()->create(['conversation_id' => $conversation->id]);

        $service = app(AiUserProjectService::class);
        $capsule = ['goal' => 'Open an online shop', 'decisions' => ['Use Laravel', 'Ship in May'], 'done' => ['Logo']];
        $service->rememberCapsule($user, collect([$conversation]), $capsule);
        $service->rememberCapsule($user, collect([$conversation]), ['goal' => 'Open an online shop in Cairo', 'decisions' => ['Use Laravel']]);

        $items = AiProjectContext::query()->where('project_id', $project->id)->get();
        $this->assertCount(1, $items->where('context_type', AiProjectContext::TYPE_SUMMARY));
        $this->assertSame('Open an online shop in Cairo', $items->firstWhere('context_type', AiProjectContext::TYPE_SUMMARY)->content);
        $this->assertSame(['Use Laravel', 'Ship in May'], $items->where('context_type', AiProjectContext::TYPE_DECISION)->pluck('content')->values()->all());

        $message = (string) $service->systemMessage($conversation);
        $this->assertStringContainsString('Ship in May', $message);
        $this->assertStringContainsString('Open an online shop in Cairo', $message);
    }

    // ---- ai_requests.error_code / retry_count ----------------------------------------------------------

    public function test_a_failover_counts_a_retry_and_a_failed_request_gets_an_error_code(): void
    {
        $primary = $this->makeProvider('openai');
        $fallback = $this->makeProvider('anthropic');
        $aiRequest = $this->makeRequest($this->makeUser());

        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->twice()->andReturn(['success' => false, 'message' => 'boom', 'content' => null]);
        });

        $method = new ReflectionMethod(AiChatService::class, 'dispatchWithFallback');
        $method->setAccessible(true);
        [$result] = $method->invoke(app(AiChatService::class), [
            ['provider' => $primary, 'model_key' => null],
            ['provider' => $fallback, 'model_key' => null],
        ], true, [], $aiRequest);

        $this->assertFalse($result['success']);
        $this->assertSame(1, (int) $aiRequest->retry_count);
        $this->assertSame(AiRequest::ERROR_PROVIDER, AiRequest::failureCode('boom'));
        $this->assertSame(AiRequest::ERROR_IMAGE_QUOTA, AiRequest::failureCode('image_quota:limit'));
        $this->assertSame(AiRequest::ERROR_CIRCUIT_OPEN, AiRequest::failureCode(__('ai.circuit_breaker_open')));
    }

    // ---- admin resources: owner phone; attachment without conversation ------------------------------------

    public function test_admin_request_log_sends_the_owner_phone(): void
    {
        Sanctum::actingAs(\Tests\Support\AdminPermissions::grant(Admin::query()->create(['name' => 'A', 'email' => 'ad-'.uniqid().'@example.test', 'password' => 'password', 'status' => true])), ['*'], 'admin_api');
        $user = $this->makeUser('01000000001');
        $this->makeRequest($user);

        $response = $this->getJson('/api/admin/v1/ai-requests')->assertOk();

        $this->assertSame('01000000001', $response->json('data.data.0.owner.phone') ?? $response->json('data.0.owner.phone'));
    }

    public function test_owner_phone_is_never_sent_outside_the_admin_api(): void
    {
        $user = $this->makeUser('01000000002');
        $request = $this->makeRequest($user)->load('owner');

        $admin = (new \Modules\AI\Http\Resources\AiRequestResource($request))->resolve(Request::create('/api/admin/v1/ai-requests'));
        $app = (new \Modules\AI\Http\Resources\AiRequestResource($request))->resolve(Request::create('/api/user/v1/whatever'));

        $this->assertSame('01000000002', $admin['owner']['phone']);
        $this->assertArrayNotHasKey('phone', $app['owner']);
    }

    public function test_attachment_resource_survives_a_missing_conversation(): void
    {
        $attachment = new AiConversationAttachment(['file_name' => 'a.png', 'mime_type' => 'image/png']);
        $attachment->setRelation('conversation', null);

        $data = (new AiConversationAttachmentResource($attachment))->resolve(Request::create('/'));

        $this->assertNull($data['conversation']);
    }

    // ---- benchmark run never stays "running" -------------------------------------------------------------

    public function test_a_benchmark_run_that_throws_is_marked_failed(): void
    {
        AiBenchmarkCase::query()->create(['domain_key' => 'general', 'prompt' => 'p', 'expected_behavior' => 'answer', 'status' => true]);
        $admin = Admin::query()->create(['name' => 'A', 'email' => 'ad-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]);

        $runner = new class(app(\Modules\AI\Services\AiRoutingEngine::class), app(\Modules\AI\Repositories\AiProviderRepository::class), app(AiGateway::class)) extends AiBenchmarkRunner
        {
            protected function runCase(\Illuminate\Contracts\Auth\Authenticatable $triggeredBy, AiBenchmarkCase $case, ?AiProvider $forcedProvider): array
            {
                throw new \RuntimeException('provider SDK crashed');
            }
        };

        try {
            $runner->run($admin);
            $this->fail('the error is still raised');
        } catch (\RuntimeException) {
        }

        $run = AiBenchmarkRun::query()->latest('id')->first();
        $this->assertSame(AiBenchmarkRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
    }
}
