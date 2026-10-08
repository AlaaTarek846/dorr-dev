<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AI\Models\AiModelSyncLog;
use Modules\AI\Repositories\AiProviderRepository;
use Tests\TestCase;

/**
 * Dynamic Model Registry, section 10/17: `php artisan ai:sync-models`
 * must be a real, unattended-runnable trigger for the same sync a
 * "test connection" click does, and every run - success or failure -
 * must leave a real ai_model_sync_logs row behind so a scheduled run
 * nobody watched is still auditable afterwards.
 */
class SyncAiModelsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncing_a_single_named_provider_registers_its_models_and_logs_the_run(): void
    {
        app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-4o'], ['id' => 'gpt-4o-mini']],
            ], 200),
        ]);

        $this->artisan('ai:sync-models', ['provider' => 'openai'])->assertSuccessful();

        $provider = app(AiProviderRepository::class)->findByKey('openai');
        $this->assertSame(2, $provider->models()->count());

        $log = AiModelSyncLog::query()->where('provider_id', $provider->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(AiModelSyncLog::STATUS_SUCCEEDED, $log->status);
        $this->assertSame(2, $log->models_found);
        $this->assertSame(2, $log->models_created);
    }

    public function test_an_unknown_provider_key_fails_cleanly_without_a_stray_log_row(): void
    {
        $this->artisan('ai:sync-models', ['provider' => 'not-a-real-provider'])->assertFailed();

        $this->assertSame(0, AiModelSyncLog::query()->count());
    }

    public function test_a_failed_connection_is_recorded_as_a_failed_sync_log_not_silently_dropped(): void
    {
        $provider = app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        Http::fake([
            'api.openai.com/v1/models' => Http::response(['error' => ['message' => 'invalid_api_key']], 401),
        ]);

        $this->artisan('ai:sync-models', ['provider' => 'openai'])->assertFailed();

        $log = AiModelSyncLog::query()->where('provider_id', $provider->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(AiModelSyncLog::STATUS_FAILED, $log->status);
        $this->assertNotNull($log->error_message);
    }

    public function test_running_with_no_provider_argument_syncs_every_usable_provider(): void
    {
        // isUsableForChat() (AiProvider::isUsableForChat()) requires both
        // a real api_key AND is_enabled=true - every provider seeds with
        // is_enabled=false by default (AiProviderRepository::ensureDefaults()),
        // exactly like the routing/gateway layer already requires
        // everywhere else. A provider named explicitly via {provider}
        // bypasses this (an admin's deliberate, single-provider action),
        // but the unattended "sync everything" path must only ever touch
        // providers the admin has actually turned on.
        app(AiProviderRepository::class)->updateByKey('openai', [
            'api_key' => 'sk-test-not-real',
            'is_enabled' => true,
        ]);

        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4o']]], 200),
        ]);

        $this->artisan('ai:sync-models')->assertSuccessful();

        $this->assertSame(1, AiModelSyncLog::query()->count());
    }

    public function test_running_with_no_provider_argument_skips_a_provider_with_a_key_but_not_yet_enabled(): void
    {
        // Matches the real behavior an admin sees: an api_key alone is
        // not enough for the unattended sync to touch a provider - it
        // must also be switched on, same as it must be to actually serve
        // chat traffic.
        app(AiProviderRepository::class)->updateByKey('openai', ['api_key' => 'sk-test-not-real']);

        $this->artisan('ai:sync-models')->assertSuccessful();

        $this->assertSame(0, AiModelSyncLog::query()->count());
    }
}
