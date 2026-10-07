<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiPlan;
use Tests\TestCase;

class AiPlanMediaLimitsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): void
    {
        Sanctum::actingAs(Admin::query()->create([
            'name' => 'Plan Admin', 'email' => 'plan-admin-'.uniqid().'@example.test', 'password' => 'password', 'status' => true,
        ]), ['*'], 'admin_api');
    }

    protected function payload(array $extra = []): array
    {
        return $extra + ['name' => 'Pro', 'code' => 'pro-'.uniqid(), 'usage_minutes' => 120, 'cooldown_minutes' => 0, 'duration_days' => 30, 'price' => 10];
    }

    public function test_admin_can_set_media_limits_on_a_plan(): void
    {
        $this->admin();

        $response = $this->postJson('/api/admin/v1/ai-plans', $this->payload(['image_daily_limit' => 20, 'video_daily_limit' => 3, 'video_max_seconds' => 8]));

        $response->assertSuccessful();
        $this->assertSame(20, $response->json('data.image_daily_limit'));
        $this->assertSame(3, $response->json('data.video_daily_limit'));
        $this->assertSame(8, $response->json('data.video_max_seconds'));
    }

    public function test_a_new_plan_without_media_fields_keeps_images_unlimited_and_video_off(): void
    {
        $this->admin();

        $response = $this->postJson('/api/admin/v1/ai-plans', $this->payload());

        $response->assertSuccessful();
        $plan = AiPlan::query()->findOrFail($response->json('data.id'));
        $this->assertNull($plan->image_daily_limit);
        $this->assertSame(0, $plan->video_daily_limit);
        $this->assertSame(0, $plan->video_max_seconds);
    }

    public function test_clearing_the_video_fields_means_none_and_clearing_images_means_unlimited(): void
    {
        $this->admin();
        $plan = AiPlan::query()->create($this->payload(['image_daily_limit' => 5, 'video_daily_limit' => 2, 'video_max_seconds' => 8, 'is_active' => true]));

        $this->putJson("/api/admin/v1/ai-plans/{$plan->id}", ['image_daily_limit' => null, 'video_daily_limit' => null, 'video_max_seconds' => ''])->assertSuccessful();

        $plan->refresh();
        $this->assertNull($plan->image_daily_limit);
        $this->assertSame(0, $plan->video_daily_limit);
        $this->assertSame(0, $plan->video_max_seconds);
    }

    public function test_negative_or_absurd_values_are_rejected(): void
    {
        $this->admin();

        $this->postJson('/api/admin/v1/ai-plans', $this->payload(['image_daily_limit' => -1]))->assertUnprocessable();
        $this->postJson('/api/admin/v1/ai-plans', $this->payload(['video_max_seconds' => 9999]))->assertUnprocessable();
    }
}
