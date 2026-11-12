<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AI\Models\AiDataPolicy;
use Modules\AI\Models\AiRequest;
use Modules\AI\Services\AiChatService;
use Modules\AI\Tests\Feature\Concerns\BuildsAiRoutingScreens;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AI data policies as a catalog like countries: a name / description per stored language (classification, retention
 * and switches stay technical), status toggle and counts, trash / restore / force delete, bulk delete and the
 * ai-data-policies.* permissions per action - and a switched-off or trashed policy is never read by the retention
 * command or the log minimization.
 */
class AiDataPolicyCatalogTest extends TestCase
{
    use BuildsAiRoutingScreens, RefreshDatabase;

    private const BASE = '/api/admin/v1/ai-data-policies';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedStoredLanguages();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'data_classification' => 'personal',
            'retention_days' => 30,
            'consent_required' => true,
            'minimization_enabled' => true,
            'external_provider_allowed' => false,
            'status' => true,
            'translations' => [
                ['locale' => 'ar', 'name' => 'البيانات الشخصية', 'description' => 'بيانات العملاء'],
                ['locale' => 'en', 'name' => 'Personal data', 'description' => 'Customer data'],
            ],
        ];
    }

    private function oldRequest(): AiRequest
    {
        $request = AiRequest::query()->create(['owner_type' => 'user', 'owner_id' => 1, 'status' => AiRequest::STATUS_COMPLETED]);
        DB::table('ai_requests')->where('id', $request->id)->update(['created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);

        return $request;
    }

    public function test_admin_creates_a_policy_with_a_translation_per_language(): void
    {
        $this->actingAsScreenAdmin('ai-data-policies');

        $response = $this->postJson(self::BASE, $this->payload(), ['X-Locale' => 'en'])->assertCreated();

        $this->assertSame('Personal data', $response->json('data.name'));
        $this->assertSame(30, $response->json('data.retention_days'));
        $this->assertFalse($response->json('data.external_provider_allowed'));
        $this->assertTrue($response->json('data.is_active'));
        $this->assertCount(2, AiDataPolicy::query()->findOrFail($response->json('data.id'))->translations);

        $this->getJson(self::BASE.'/'.$response->json('data.id'), ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('data.name', 'البيانات الشخصية');

        $this->postJson(self::BASE, $this->payload(['data_classification' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('data_classification');
        $payload = $this->payload();
        $payload['translations'] = [['locale' => 'ar', 'name' => 'عربي فقط']];
        $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('translations');
    }

    public function test_status_counts_trash_restore_force_delete_and_bulk_delete(): void
    {
        $this->actingAsScreenAdmin('ai-data-policies');
        $a = $this->postJson(self::BASE, $this->payload())->json('data.id');
        $b = $this->postJson(self::BASE, $this->payload(['data_classification' => 'internal']))->json('data.id');

        $this->patchJson(self::BASE."/{$a}/status", ['status' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson(self::BASE.'?status_counts=1')->assertOk()
            ->assertJsonPath('status_counts.total', 2)
            ->assertJsonPath('status_counts.inactive', 1);

        $this->deleteJson(self::BASE."/{$a}")->assertSuccessful();
        $this->assertSoftDeleted('ai_data_policies', ['id' => $a]);
        $this->postJson(self::BASE."/{$a}/restore")->assertSuccessful();

        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$a, $b]])->assertSuccessful();
        $this->assertSoftDeleted('ai_data_policies', ['id' => $b]);

        $this->deleteJson(self::BASE."/{$b}/force")->assertSuccessful();
        $this->assertDatabaseMissing('ai_data_policies', ['id' => $b]);
        $this->assertDatabaseMissing('ai_data_policy_translations', ['ai_data_policy_id' => $b]);
    }

    public function test_each_action_needs_its_permission(): void
    {
        $policy = AiDataPolicy::query()->create(['name' => 'Personal', 'data_classification' => 'personal', 'is_active' => true]);

        $this->actingAsScreenAdmin('ai-data-policies', ['view']);
        $this->getJson(self::BASE)->assertOk();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE."/{$policy->id}", $this->payload())->assertForbidden();
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => false])->assertForbidden();
        $this->deleteJson(self::BASE."/{$policy->id}")->assertForbidden();
        $this->postJson(self::BASE.'/delete-multiple', ['ids' => [$policy->id]])->assertForbidden();

        $this->actingAsScreenAdmin('ai-data-policies', ['view', 'update', 'change-status']);
        $this->putJson(self::BASE."/{$policy->id}", $this->payload())->assertOk();
        $this->patchJson(self::BASE."/{$policy->id}/status", ['status' => true])->assertOk();

        $this->actingAsScreenAdmin('ai-data-policies', []);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_a_switched_off_or_trashed_policy_is_never_read_by_the_engine(): void
    {
        $policy = AiDataPolicy::query()->create([
            'name' => 'Personal', 'data_classification' => 'personal', 'retention_days' => 30,
            'minimization_enabled' => false, 'is_active' => false,
        ]);
        $minimized = function (string $text): string {
            $method = new ReflectionMethod(AiChatService::class, 'minimizedForLog');
            $method->setAccessible(true);

            return $method->invoke(app(AiChatService::class), $text);
        };

        // Switched off: no retention, and the log stays minimized (the fail-safe default).
        $old = $this->oldRequest();
        $this->artisan('ai:enforce-retention')->assertSuccessful();
        $this->assertDatabaseHas('ai_requests', ['id' => $old->id]);
        $this->assertStringNotContainsString('ali@example.com', $minimized('mail ali@example.com'));

        // Switched on: its retention purges and its "minimization off" is honoured.
        $policy->update(['status' => true]);
        $this->assertSame('mail ali@example.com', $minimized('mail ali@example.com'));
        $this->artisan('ai:enforce-retention')->assertSuccessful();
        $this->assertDatabaseMissing('ai_requests', ['id' => $old->id]);

        // In the trash: the same as switched off.
        $policy->delete();
        $old = $this->oldRequest();
        $this->assertNull(AiDataPolicy::activePersonal());
        $this->artisan('ai:enforce-retention')->assertSuccessful();
        $this->assertDatabaseHas('ai_requests', ['id' => $old->id]);
        $this->assertStringNotContainsString('ali@example.com', $minimized('mail ali@example.com'));
    }
}
