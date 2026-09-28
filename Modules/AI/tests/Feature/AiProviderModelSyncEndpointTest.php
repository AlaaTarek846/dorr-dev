<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiModelSyncLog;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * Dynamic Model Registry, section 12: the admin-facing "Sync Models"
 * button (POST {provider}/models/sync) - independent of both "test
 * connection" and the scheduled/CLI ai:sync-models command, but doing
 * the exact same real reconciliation and leaving the exact same
 * ai_model_sync_logs trail.
 */
class AiProviderModelSyncEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Sync Endpoint Admin',
            'email' => 'sync-endpoint-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_the_sync_endpoint_registers_models_and_returns_a_summary(): void
    {
        $this->actingAsAdmin();
        app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-4o'], ['id' => 'o1-preview']],
            ], 200),
        ]);

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models/sync');

        $response->assertOk();
        $response->assertJsonPath('data.summary.created', 2);
        $this->assertCount(2, $response->json('data.models'));

        $provider = app(AiProviderRepository::class)->findByKey('openai');
        $this->assertSame(1, AiModelSyncLog::query()->where('provider_id', $provider->id)->count());
    }

    public function test_a_failed_connection_returns_an_error_and_still_logs_the_failure(): void
    {
        $this->actingAsAdmin();
        $provider = app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        Http::fake([
            'api.openai.com/v1/models' => Http::response(['error' => ['message' => 'invalid_api_key']], 401),
        ]);

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models/sync');

        $response->assertStatus(502);

        $log = AiModelSyncLog::query()->where('provider_id', $provider->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(AiModelSyncLog::STATUS_FAILED, $log->status);
    }
}
