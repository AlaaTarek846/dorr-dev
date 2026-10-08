<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiAuditEvent;
use Modules\AI\Services\AiAuditTrail;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Known Gaps item: ai_audit_events was being written to for real
 * (AiAuditTrail::record(), built earlier this engagement) but had no
 * admin screen at all - the ledger existed and nobody could see it. This
 * covers the admin-facing read side: list, single-record detail, the
 * owner/actor morph resolution, and that it's genuinely read-only (no
 * store/update/destroy routes registered - matches the model's own
 * update()/delete() throwing).
 */
class AiAuditEventAdminScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Audit Screen Admin',
            'email' => 'audit-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/v1/ai-audit-events')->assertStatus(401);
    }

    public function test_an_admin_can_list_audit_events_with_owner_resolved(): void
    {
        $this->actingAsAdmin();

        $user = User::query()->create([
            'name' => 'Audit Subject User',
            'email' => 'audit-subject-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);

        app(AiAuditTrail::class)->record(
            eventType: AiAuditTrail::EVENT_DATA_EXPORTED,
            owner: $user,
            metadata: ['reason' => 'user requested export'],
        );

        $response = $this->getJson('/api/admin/v1/ai-audit-events');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(AiAuditTrail::EVENT_DATA_EXPORTED, $response->json('data.0.event_type'));
        $this->assertSame($user->id, $response->json('data.0.owner.id'));
        $this->assertSame($user->name, $response->json('data.0.owner.name'));
    }

    public function test_an_admin_can_view_a_single_audit_event(): void
    {
        $this->actingAsAdmin();

        $event = AiAuditEvent::query()->create([
            'event_type' => AiAuditTrail::EVENT_CIRCUIT_BREAKER_OPENED,
            'severity' => 'high',
            'metadata' => ['provider' => 'openai'],
        ]);

        $response = $this->getJson("/api/admin/v1/ai-audit-events/{$event->id}");

        $response->assertStatus(200);
        $this->assertSame('high', $response->json('data.severity'));
        $this->assertSame(['provider' => 'openai'], $response->json('data.metadata'));
    }

    public function test_show_returns_404_for_a_nonexistent_event(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/v1/ai-audit-events/999999')->assertStatus(404);
    }

    public function test_no_write_routes_are_registered_for_the_append_only_ledger(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_starts_with($route->uri(), 'api/admin/v1/ai-audit-events'),
        );

        $methods = $routes->flatMap(fn ($route) => $route->methods())->unique()->values()->all();

        sort($methods);
        $this->assertSame(['GET', 'HEAD'], $methods, 'only read methods must be routed - the ledger is append-only');
    }
}
