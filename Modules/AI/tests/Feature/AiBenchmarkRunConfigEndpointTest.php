<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Tests\TestCase;

/**
 * Business gap fix (admin dashboard review, 27 Sep): the ai-benchmark-runs
 * admin screen hardcoded "30" as its small-sample-size warning threshold,
 * while the real value has always been configurable
 * (config('ai.min_recommended_sample_size'), env AI_BENCHMARK_MIN_SAMPLE_SIZE)
 * - a config change never reached the screen. Added a dedicated
 * GET .../ai-benchmark-runs/config endpoint the frontend now reads on
 * mount instead of assuming the number never changes.
 */
class AiBenchmarkRunConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'benchmark-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_it_returns_the_live_configured_minimum_sample_size(): void
    {
        $this->actingAsAdmin();

        Config::set('ai.min_recommended_sample_size', 42);

        $response = $this->getJson('/api/admin/v1/ai-benchmark-runs/config');

        $response->assertOk();
        $response->assertJsonPath('data.min_recommended_sample_size', 42);
    }

    public function test_the_config_route_is_never_shadowed_by_the_show_by_id_route(): void
    {
        $this->actingAsAdmin();

        // Real regression this test guards against: {run} was registered
        // before the literal "config" route once, which made Laravel try
        // to model-bind "config" as an AiBenchmarkRun id and 404 instead
        // of ever reaching the config() method.
        $response = $this->getJson('/api/admin/v1/ai-benchmark-runs/config');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['min_recommended_sample_size']]);
    }
}
