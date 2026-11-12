<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiBenchmarkCase;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiBenchmarkRunner;
use Modules\AI\Services\AiGateway;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use Tests\TestCase;

/**
 * AI benchmark cases as a catalog list like countries (no translations: test data), status toggle and counts,
 * trash / restore, force delete blocked while past runs have results for it, ai-benchmark-cases.* permissions - and
 * a switched-off or trashed case is never run (AiBenchmarkRunner).
 */
class AiBenchmarkCaseCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-benchmark-cases';

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'prompt' => 'What services does DORR offer?',
            'domain_key' => 'general_info',
            'difficulty' => 'easy',
            'risk_level' => 'low',
            'language' => 'en',
            'task_type' => 'qa',
            'expected_behavior' => 'answer',
            'expected_answer_keywords' => ['delivery'],
            'notes' => 'Simple platform question',
            'status' => true,
        ];
    }

    private function runBenchmark(): \Modules\AI\Models\AiBenchmarkRun
    {
        $provider = AiProvider::query()->create([
            'key' => 'openai', 'name' => 'OpenAI (test)', 'is_enabled' => true, 'is_default' => true, 'api_key' => 'test-key-not-real', 'model' => 'gpt-4o-mini',
        ]);
        $this->mock(AiGateway::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn(['success' => true, 'message' => 'ok', 'content' => 'We do delivery.']));
        $admin = Admin::query()->create(['name' => 'Runner', 'email' => 'runner-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]);

        return app(AiBenchmarkRunner::class)->run($admin, $provider->id);
    }

    public function test_admin_creates_a_case_and_old_keys_are_kept(): void
    {
        $this->actingAsScreenAdmin('ai-benchmark-cases');

        $response = $this->postJson(self::BASE, $this->payload())->assertCreated();

        $response->assertJsonPath('data.prompt', 'What services does DORR offer?')
            ->assertJsonPath('data.status', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.expected_answer_keywords', ['delivery'])
            ->assertJsonPath('data.results_count', 0);

        // Older callers still send is_active.
        $id = $response->json('data.id');
        $this->putJson(self::BASE."/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->postJson(self::BASE, $this->payload(['difficulty' => 'impossible']))->assertUnprocessable()->assertJsonValidationErrors('difficulty');
    }

    public function test_status_toggle_counts_and_search(): void
    {
        $this->actingAsScreenAdmin('ai-benchmark-cases');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $this->postJson(self::BASE, $this->payload(['prompt' => 'Write a palindrome function', 'domain_key' => 'code']))->assertCreated();

        $this->patchJson(self::BASE."/{$id}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);

        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $search = json_encode(['searchKey' => 'palindrome', 'columns' => ['prompt', 'notes', 'domain_key']]);
        $this->assertSame(['code'], collect($this->getJson(self::BASE.'?search='.urlencode($search))->json('data'))->pluck('domain_key')->all());
    }

    public function test_trash_restore_bulk_delete_and_force_delete_blocked_while_it_has_results(): void
    {
        $this->actingAsScreenAdmin('ai-benchmark-cases');
        $id = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $run = $this->runBenchmark();
        $result = $run->results->first();

        $this->deleteJson(self::BASE."/{$id}")->assertSuccessful();
        $this->assertSoftDeleted('ai_benchmark_cases', ['id' => $id]);
        $this->assertSame($id, $result->fresh()->case?->id, 'a past result still names its trashed case');

        $this->deleteJson(self::BASE."/{$id}/force")->assertStatus(409);
        $result->delete();

        $this->postJson(self::BASE."/{$id}/restore")->assertSuccessful();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$id]])->assertSuccessful();
        $this->deleteJson(self::BASE."/{$id}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_benchmark_cases', ['id' => $id]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $case = AiBenchmarkCase::query()->create($this->payload(['is_active' => true]));

        $this->actingAsScreenAdmin('ai-benchmark-cases', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$case->id}", $this->payload())->assertForbidden();
        $this->patchJson(self::BASE."/{$case->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$case->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$case->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-benchmark-cases', ['view', 'change-status', 'delete']);
        $this->patchJson(self::BASE."/{$case->id}/status", ['status' => false])->assertOk();
        $this->deleteJson(self::BASE."/{$case->id}")->assertSuccessful();
        $this->postJson(self::BASE."/{$case->id}/restore")->assertForbidden(); // restore = update

        $this->actingAsScreenAdmin('ai-benchmark-cases', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_case_is_never_run(): void
    {
        $live = AiBenchmarkCase::query()->create($this->payload());
        $off = AiBenchmarkCase::query()->create($this->payload(['prompt' => 'Paused', 'status' => false]));
        $trashed = AiBenchmarkCase::query()->create($this->payload(['prompt' => 'Trashed']));
        $trashed->delete();

        $run = $this->runBenchmark();

        $this->assertSame(1, $run->total_cases);
        $this->assertSame([$live->id], $run->results->pluck('case_id')->all());
        $this->assertNotContains($off->id, $run->results->pluck('case_id')->all());
    }
}
