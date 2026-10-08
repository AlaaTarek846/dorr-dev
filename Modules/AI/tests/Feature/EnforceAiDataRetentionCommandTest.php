<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AI\Models\AiDataPolicy;
use Modules\AI\Models\AiProviderLog;
use Modules\AI\Models\AiRequest;
use Tests\TestCase;

/**
 * v2.0 requirements doc §17.3/§20.1: the `ai:enforce-retention` command
 * must purge only audit/operational rows older than the configured
 * policy's retention window, and must never touch anything when no
 * active "personal" data policy is configured.
 */
class EnforceAiDataRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function makeRequest(string $createdAt): AiRequest
    {
        $request = AiRequest::query()->create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'status' => AiRequest::STATUS_COMPLETED,
        ]);

        DB::table('ai_requests')->where('id', $request->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $request->fresh();
    }

    public function test_does_nothing_when_no_active_personal_policy_is_configured(): void
    {
        $old = $this->makeRequest(now()->subYears(3)->toDateTimeString());

        $this->artisan('ai:enforce-retention')->assertSuccessful();

        $this->assertDatabaseHas('ai_requests', ['id' => $old->id]);
    }

    public function test_purges_requests_older_than_the_retention_window(): void
    {
        AiDataPolicy::query()->create([
            'name' => 'test personal policy',
            'data_classification' => AiDataPolicy::CLASSIFICATION_PERSONAL,
            'retention_days' => 30,
            'is_active' => true,
        ]);

        $old = $this->makeRequest(now()->subDays(60)->toDateTimeString());
        $recent = $this->makeRequest(now()->subDays(5)->toDateTimeString());

        $this->artisan('ai:enforce-retention')->assertSuccessful();

        $this->assertDatabaseMissing('ai_requests', ['id' => $old->id]);
        $this->assertDatabaseHas('ai_requests', ['id' => $recent->id]);
    }

    public function test_dry_run_reports_but_does_not_delete_anything(): void
    {
        AiDataPolicy::query()->create([
            'name' => 'test personal policy',
            'data_classification' => AiDataPolicy::CLASSIFICATION_PERSONAL,
            'retention_days' => 30,
            'is_active' => true,
        ]);

        $old = $this->makeRequest(now()->subDays(60)->toDateTimeString());

        $this->artisan('ai:enforce-retention', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('ai_requests', ['id' => $old->id]);
    }

    public function test_purges_stale_provider_logs_independently_of_their_request(): void
    {
        AiDataPolicy::query()->create([
            'name' => 'test personal policy',
            'data_classification' => AiDataPolicy::CLASSIFICATION_PERSONAL,
            'retention_days' => 30,
            'is_active' => true,
        ]);

        $log = AiProviderLog::query()->create(['correlation_id' => 'test-corr']);
        DB::table('ai_provider_logs')->where('id', $log->id)->update([
            'created_at' => now()->subDays(90)->toDateTimeString(),
        ]);

        $this->artisan('ai:enforce-retention')->assertSuccessful();

        $this->assertDatabaseMissing('ai_provider_logs', ['id' => $log->id]);
    }
}
