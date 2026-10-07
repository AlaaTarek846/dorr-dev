<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiProvider;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * Business gap fix: ai_providers used to only ever have ONE $model at a
 * time. This covers the new ai_provider_models registry that lets a
 * connected provider actually be "connected to every model I chose",
 * each independently tagged with capabilities (vision/coding/research/...)
 * - what AiRoutingEngine reads to auto-pick the best model for a message.
 */
class AiProviderModelAdminScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Models Screen Admin',
            'email' => 'models-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/v1/ai-providers/openai/models')->assertStatus(401);
    }

    public function test_an_admin_can_register_a_model_with_capabilities(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'gpt-4o',
            'display_name' => 'GPT-4o',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.model_key', 'gpt-4o');
        $response->assertJsonPath('data.capabilities', ['chat', 'vision']);
        $response->assertJsonPath('data.is_default', true);

        $provider = AiProvider::query()->where('key', 'openai')->firstOrFail();
        $this->assertCount(1, $provider->models);
    }

    public function test_registering_the_same_model_key_twice_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'gpt-4o',
            'capabilities' => ['chat'],
        ])->assertStatus(201);

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'gpt-4o',
            'capabilities' => ['vision'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('model_key');
    }

    public function test_an_unknown_capability_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'gpt-4o',
            'capabilities' => ['telepathy'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('capabilities.0');
    }

    public function test_setting_a_new_default_clears_the_previous_one(): void
    {
        $this->actingAsAdmin();

        $first = $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'gpt-4o-mini',
            'capabilities' => ['chat'],
            'is_default' => true,
        ])->json('data');

        $second = $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'gpt-4o',
            'capabilities' => ['chat', 'vision'],
            'is_default' => true,
        ])->json('data');

        $this->assertTrue($second['is_default']);

        $provider = AiProvider::query()->where('key', 'openai')->with('models')->firstOrFail();
        $stillDefault = $provider->models->firstWhere('id', $first['id']);
        $this->assertFalse($stillDefault->is_default);
    }

    /**
     * Real gap fixed directly for the admin: the table used to show a
     * Temperature input for every single model, including ones whose
     * real API rejects the parameter outright (o-series reasoning
     * models). Proves the field round-trips through the update endpoint
     * and the resource exposes it, with a NULL (not yet computed)
     * treated as "supported" so nothing regresses for a row registered
     * before this field existed.
     */
    public function test_temperature_supported_and_limits_round_trip_through_update(): void
    {
        $this->actingAsAdmin();

        $model = $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'o3-mini',
            'capabilities' => ['chat', 'reasoning'],
        ])->json('data');

        // Registered with no explicit temperature_supported/limits given
        // at all - resource must default the unknown boolean to true.
        $this->assertTrue($model['temperature_supported']);
        $this->assertNull($model['context_window']);
        $this->assertNull($model['max_output_tokens']);

        $response = $this->putJson("/api/admin/v1/ai-providers/openai/models/{$model['id']}", [
            'temperature_supported' => false,
            'context_window' => 200000,
            'max_output_tokens' => 100000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.temperature_supported', false);
        $response->assertJsonPath('data.context_window', 200000);
        $response->assertJsonPath('data.max_output_tokens', 100000);

        // An update call that mentions none of the three fields must
        // leave them exactly as they are, never silently reset them.
        $this->putJson("/api/admin/v1/ai-providers/openai/models/{$model['id']}", [
            'display_name' => 'O3 Mini (renamed)',
        ])->assertJsonPath('data.temperature_supported', false)
            ->assertJsonPath('data.context_window', 200000);
    }

    /**
     * The broader capability vocabulary requested directly by the admin
     * (embeddings, realtime, function_calling, etc.) must actually be
     * assignable through the same admin screen, not just exist in the
     * enum unreachably.
     */
    public function test_the_newly_added_capability_tags_are_accepted(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-providers/openai/models', [
            'model_key' => 'text-embedding-3-small',
            'capabilities' => ['embeddings'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.capabilities', ['embeddings']);
    }

    public function test_an_admin_can_deactivate_and_delete_a_model(): void
    {
        $this->actingAsAdmin();

        $model = $this->postJson('/api/admin/v1/ai-providers/groq/models', [
            'model_key' => 'llama-3.3-70b',
            'capabilities' => ['chat', 'coding'],
        ])->json('data');

        $this->putJson("/api/admin/v1/ai-providers/groq/models/{$model['id']}", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/admin/v1/ai-providers/groq/models/{$model['id']}")
            ->assertStatus(200);

        $provider = AiProvider::query()->where('key', 'groq')->firstOrFail();
        $this->assertCount(0, $provider->models);
    }
}
