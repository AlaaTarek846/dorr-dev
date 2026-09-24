<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiFeatureFlag;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * v2.0 requirements doc S4.4: admin CRUD over ai_feature_flags itself -
 * as opposed to AiFeatureFlagGateTest, which already covers the runtime
 * matching logic that reads these rows. This is the HTTP/authorization/
 * validation layer around them.
 */
class AiFeatureFlagCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'flag-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/v1/ai-feature-flags')->assertStatus(401);
    }

    public function test_an_admin_can_list_feature_flags(): void
    {
        $this->actingAsAdmin();

        AiFeatureFlag::query()->create([
            'key' => 'openai-disabled-eg',
            'target_type' => AiFeatureFlag::TARGET_PROVIDER,
            'country_code' => 'EG',
            'is_enabled' => false,
        ]);

        $response = $this->getJson('/api/admin/v1/ai-feature-flags');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_an_admin_can_create_a_feature_flag(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-feature-flags', [
            'key' => 'sandbox-disabled-globally',
            'target_type' => AiFeatureFlag::TARGET_TOOL,
            'tool_key' => 'code_sandbox',
            'is_enabled' => false,
            'description' => 'Temporarily disable the code sandbox tool.',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ai_feature_flags', [
            'key' => 'sandbox-disabled-globally',
            'tool_key' => 'code_sandbox',
            'is_enabled' => false,
        ]);
    }

    public function test_creating_a_flag_without_a_required_target_type_fails_validation(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-feature-flags', [
            'key' => 'missing-target-type',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['target_type']);
    }

    public function test_creating_a_flag_with_an_invalid_target_type_fails_validation(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/v1/ai-feature-flags', [
            'key' => 'invalid-target-type',
            'target_type' => 'not-a-real-target',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['target_type']);
    }

    public function test_an_admin_can_update_a_feature_flag(): void
    {
        $this->actingAsAdmin();

        $flag = AiFeatureFlag::query()->create([
            'key' => 'groq-enabled',
            'target_type' => AiFeatureFlag::TARGET_PROVIDER,
            'is_enabled' => true,
        ]);

        $response = $this->putJson("/api/admin/v1/ai-feature-flags/{$flag->id}", [
            'is_enabled' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ai_feature_flags', [
            'id' => $flag->id,
            'is_enabled' => false,
        ]);
    }

    public function test_an_admin_can_delete_a_feature_flag(): void
    {
        $this->actingAsAdmin();

        $flag = AiFeatureFlag::query()->create([
            'key' => 'to-be-deleted',
            'target_type' => AiFeatureFlag::TARGET_MODEL,
            'model_key' => 'gpt-4o-mini',
            'is_enabled' => true,
        ]);

        $response = $this->deleteJson("/api/admin/v1/ai-feature-flags/{$flag->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('ai_feature_flags', ['id' => $flag->id]);
    }

    public function test_show_returns_404_for_a_nonexistent_flag(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/v1/ai-feature-flags/999999')->assertStatus(404);
    }
}
