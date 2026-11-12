<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingCampaign;
use Modules\Marketing\Models\MarketingFeatureRule;
use Modules\Marketing\Models\MarketingIntegration;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\Marketing\Tests\Feature\Concerns\MarketingSetup;
use Tests\TestCase;

/**
 * Phase C with a fake Meta Graph API: connecting (sandbox token and OAuth), the campaign state machine, approvals
 * bound to version + payload + cap, delegations, Idempotency-Key, UNKNOWN + reconcile, expired tokens, metrics and
 * signed webhooks — the spec's AT-04/05/06/08/11/13/14/18.
 */
class MarketingPlatformsTest extends TestCase
{
    use MarketingSetup;
    use RefreshDatabase;

    /** @var list<HttpRequest> */
    private array $graph = [];

    /** @var array<string, callable|array> "METHOD path" => response */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarketing();
        config([
            'marketing.platforms.meta.sandbox' => true,
            'marketing.platforms.meta.sandbox_token' => 'EAAB-sandbox-secret',
            'marketing.platforms.meta.sandbox_account' => 'act_1',
            'marketing.platforms.meta.app_secret' => 'app-secret',
            'marketing.platforms.meta.webhook_verify_token' => 'verify-me',
        ]);
        $this->routes = [
            'GET me/adaccounts' => ['data' => [['id' => 'act_1', 'name' => 'Sandbox', 'currency' => 'SAR', 'account_status' => 1]]],
            'POST act_1/campaigns' => ['id' => 'c-100'],
            'POST act_1/adsets' => ['id' => 'as-100'],
        ];
        Http::swap(new Factory);
        Http::fake(['graph.facebook.com/*' => function (HttpRequest $request) {
            $this->graph[] = $request;
            $path = preg_replace('#^https://graph\.facebook\.com/v[0-9.]+/#', '', strtok($request->url(), '?'));
            $answer = $this->routes[$request->method().' '.$path] ?? ['data' => []];
            if (is_callable($answer)) {
                return $answer($request);
            }

            return Http::response($answer);
        }]);
    }

    private function calls(string $method, string $path): int
    {
        return count(array_filter($this->graph, fn (HttpRequest $r) => $r->method() === $method && str_contains($r->url(), '/'.$path)));
    }

    /** Owner + project + connected sandbox ad account; returns [project id, integration id]. */
    private function connected(): array
    {
        $id = $this->readyProject();
        $integration = $this->postJson($this->api("projects/{$id}/integrations/meta/connect"))->assertOk()
            ->assertJsonPath('data.integration.status', 'CONNECTED')->assertJsonPath('data.authorize_url', null)->json('data.integration.id');

        return [$id, $integration];
    }

    private function draft(string $project, ?string $integration, array $overrides = []): array
    {
        return $this->postJson($this->api("projects/{$project}/campaigns"), $overrides + [
            'integration' => $integration,
            'name' => 'Weekend boxes',
            'objective' => 'traffic',
            'daily_budget_minor' => 5000,
            'start_at' => now()->addDay()->startOfHour()->toIso8601String(),
            'end_at' => now()->addDays(11)->startOfHour()->toIso8601String(),
            'targeting' => ['countries' => ['sa'], 'age_min' => 21, 'age_max' => 45],
        ])->assertCreated()->json('data');
    }

    /** Draft → validated → awaiting → approved by the owner. */
    private function approved(string $project, ?string $integration): array
    {
        $c = $this->draft($project, $integration);
        $this->postJson($this->api("campaigns/{$c['id']}/validate"))->assertOk()->assertJsonPath('data.status', 'VALIDATED');
        $this->postJson($this->api("campaigns/{$c['id']}/approval-requests"))->assertOk()->assertJsonPath('data.status', 'AWAITING_APPROVAL');

        return $this->postJson($this->api("campaigns/{$c['id']}/approve"), ['version' => $c['version']])->assertOk()->assertJsonPath('data.status', 'APPROVED')->json('data');
    }

    private function publish(array $c, string $key, array $overrides = [])
    {
        return $this->withHeaders(['Idempotency-Key' => $key])->postJson($this->api("campaigns/{$c['id']}/publish"), $overrides + [
            'approval_id' => $c['approval']['id'] ?? '00000000-0000-0000-0000-000000000000',
            'campaign_version' => $c['version'],
            'expected_budget_minor' => $c['total_budget_minor'],
            'currency' => $c['currency'],
        ]);
    }

    public function test_the_owner_connects_the_sandbox_account_and_the_token_never_leaves_the_server(): void
    {
        $id = $this->readyProject();
        $manager = $this->user('Mona', '+966500000002');
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000002', 'role' => 'manager'])->assertCreated();

        $this->as($manager);
        $this->postJson($this->api("projects/{$id}/integrations/meta/connect"))->assertForbidden();
        $this->as($this->owner);
        $raw = $this->postJson($this->api("projects/{$id}/integrations/meta/connect"))->assertOk()
            ->assertJsonPath('data.integration.account.id', 'act_1')->assertJsonPath('data.integration.account.currency', 'SAR')
            ->assertJsonPath('data.integration.sandbox', true)->getContent();
        $this->assertStringNotContainsString('EAAB-sandbox-secret', $raw);
        $this->assertStringNotContainsString('EAAB-sandbox-secret', (string) DB::table('marketing_integrations')->value('credential_ref'));
        $this->assertSame('EAAB-sandbox-secret', MarketingIntegration::query()->firstOrFail()->credential_ref);
        $this->assertSame('Bearer EAAB-sandbox-secret', $this->graph[0]->header('Authorization')[0]);

        $list = $this->getJson($this->api("projects/{$id}/integrations"))->assertOk()->json('data');
        $this->assertSame(['meta', 'google', 'tiktok', 'snapchat'], array_column($list['platforms'], 'provider'));
        $this->assertTrue($list['platforms'][0]['available']);
        $this->assertTrue($list['platforms'][1]['coming_soon']);
        $this->assertStringNotContainsString('EAAB-sandbox-secret', json_encode($this->getJson($this->api("projects/{$id}/integrations"))->json()));

        // AT-18: the platform switched off in the country → refused, clearly.
        MarketingFeatureRule::query()->where('feature', 'platform_meta')->update(['enabled' => false]);
        MarketingFeatures::forget();
        $this->postJson($this->api("projects/{$id}/integrations/meta/connect"))->assertForbidden()->assertJsonPath('error_code', 'marketing_platform_unavailable');
    }

    public function test_a_campaign_goes_from_draft_to_a_paused_platform_campaign_only_with_an_exact_approval_and_a_key(): void
    {
        [$id, $integration] = $this->connected();
        $manager = $this->user('Mona', '+966500000002');
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000002', 'role' => 'manager'])->assertCreated();

        $c = $this->draft($id, $integration);
        $this->assertSame('DRAFT', $c['status']);
        $this->assertSame(50000, $c['total_budget_minor']);
        $this->assertSame(['SA'], $c['targeting']['countries']);
        // No shortcut from DRAFT to PUBLISHED (AT-05).
        $this->publish($c, 'key-draft-0001')->assertStatus(409);

        $this->postJson($this->api("campaigns/{$c['id']}/validate"))->assertOk();
        $this->postJson($this->api("campaigns/{$c['id']}/approval-requests"))->assertOk();
        $this->assertGreaterThan(0, DB::table('notifications')->count());

        // A manager without a delegation can't approve spending.
        $this->as($manager);
        $this->postJson($this->api("campaigns/{$c['id']}/approve"), ['version' => 1])->assertForbidden()->assertJsonPath('error_code', 'marketing_spend_approval_forbidden');
        $this->as($this->owner);
        $c = $this->postJson($this->api("campaigns/{$c['id']}/approve"), ['version' => 1])->assertOk()->json('data');
        $this->assertSame(50000, $c['approval']['cap_minor']);

        $this->flushHeaders()->postJson($this->api("campaigns/{$c['id']}/publish"), [])->assertStatus(422)->assertJsonPath('error_code', 'marketing_idempotency_required');
        $this->publish($c, 'key-wrong-version', ['campaign_version' => 2])->assertStatus(409)->assertJsonPath('error_code', 'marketing_approval_invalid');
        $this->publish($c, 'key-wrong-budget', ['expected_budget_minor' => 60000])->assertStatus(409)->assertJsonPath('data.reason', 'budget');
        $this->assertSame(0, $this->calls('POST', 'act_1/campaigns'));

        $first = $this->publish($c, 'key-publish-0001')->assertOk()->assertJsonPath('data.campaign.status', 'PUBLISHED')
            ->assertJsonPath('data.campaign.platform_ref', 'c-100')->assertJsonPath('data.campaign.platform_status', 'PAUSED')
            ->assertJsonPath('data.replayed', false)->json('data');
        $sent = collect($this->graph)->first(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'act_1/campaigns'))->data();
        $this->assertSame('PAUSED', $sent['status']);
        $this->assertSame('OUTCOME_TRAFFIC', $sent['objective']);
        $this->assertSame(5000, (int) $sent['daily_budget']);
        $this->assertStringContainsString('dorr-', $sent['name']);

        // AT-14: the same key twice → one result, one platform call.
        $this->publish($c, 'key-publish-0001')->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.action.id', $first['action']['id']);
        $this->assertSame(1, $this->calls('POST', 'act_1/campaigns'));
        $this->publish($c, 'key-publish-0002')->assertStatus(409);
        // A published campaign can't be edited or deleted.
        $this->patchJson($this->api("campaigns/{$c['id']}"), ['version' => 1, 'name' => 'New'])->assertStatus(409);
        $this->deleteJson($this->api("campaigns/{$c['id']}"))->assertStatus(409);
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'campaign.published']);
    }

    public function test_an_unconnected_campaign_is_refused_and_kept_as_a_draft(): void
    {
        $id = $this->readyProject();
        $c = $this->draft($id, null);
        $issues = $this->postJson($this->api("campaigns/{$c['id']}/validate"))->assertStatus(422)
            ->assertJsonPath('error_code', 'marketing_campaign_invalid')->json('data.issues');
        $this->assertContains('integration_not_connected', array_column($issues, 'code'));
        $this->getJson($this->api("campaigns/{$c['id']}"))->assertJsonPath('data.status', 'DRAFT');
        $this->postJson($this->api("campaigns/{$c['id']}/approval-requests"))->assertStatus(409);
        $this->assertSame(0, count($this->graph));

        $bad = $this->draft($id, null, ['daily_budget_minor' => 10, 'start_at' => now()->subDay()->toIso8601String(), 'end_at' => now()->addDays(200)->toIso8601String(), 'targeting' => ['countries' => []]]);
        $codes = array_column($this->postJson($this->api("campaigns/{$bad['id']}/validate"))->assertStatus(422)->json('data.issues'), 'code');
        $this->assertEqualsCanonicalizing(['integration_not_connected', 'budget_too_low', 'start_in_past', 'bad_dates', 'no_audience'], $codes);
    }

    public function test_a_lost_answer_leaves_the_campaign_unknown_until_a_reconcile_finds_it(): void
    {
        [$id, $integration] = $this->connected();
        $c = $this->approved($id, $integration);
        $this->routes['POST act_1/campaigns'] = fn () => throw new ConnectionException('timeout');

        $this->publish($c, 'key-timeout-0001')->assertOk()->assertJsonPath('data.campaign.status', 'UNKNOWN')->assertJsonPath('data.action.status', 'UNKNOWN');
        // AT-06: no second attempt until we know what happened.
        $this->publish($c, 'key-timeout-0002')->assertStatus(409)->assertJsonPath('error_code', 'marketing_reconcile_required');
        $this->publish($c, 'key-timeout-0001')->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, $this->calls('POST', 'act_1/campaigns'));

        // Not on the platform → back to APPROVED, publishable with a new key.
        $this->postJson($this->api("campaigns/{$c['id']}/reconcile"))->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $marker = null;
        $this->routes['GET act_1/campaigns'] = function (HttpRequest $r) use (&$marker) {
            $filter = json_decode($r->data()['filtering'] ?? '[]', true);
            $marker = $filter[0]['value'] ?? null;

            return Http::response(['data' => [['id' => 'c-200', 'name' => 'Weekend boxes ['.$marker.']', 'status' => 'PAUSED']]]);
        };
        $this->publish($c, 'key-timeout-0003')->assertOk()->assertJsonPath('data.campaign.status', 'UNKNOWN');
        $this->postJson($this->api("campaigns/{$c['id']}/reconcile"))->assertOk()->assertJsonPath('data.status', 'PUBLISHED')->assertJsonPath('data.platform_ref', 'c-200');
        $this->assertStringStartsWith('dorr-', (string) $marker);
    }

    public function test_changing_an_approved_budget_needs_a_new_approval(): void
    {
        [$id, $integration] = $this->connected();
        $c = $this->approved($id, $integration);
        $oldApproval = $c['approval']['id'];

        // AT-13: approved at 500 SAR, raised to 600 → a new version, the old approval is superseded.
        $c2 = $this->patchJson($this->api("campaigns/{$c['id']}"), ['version' => 1, 'daily_budget_minor' => 6000])->assertOk()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.version', 2)->assertJsonPath('data.total_budget_minor', 60000)
            ->assertJsonPath('data.approval', null)->json('data');
        $this->assertSame(MarketingApproval::SUPERSEDED, MarketingApproval::query()->where('uuid', $oldApproval)->value('status'));
        $this->publish($c2, 'key-old-approval', ['approval_id' => $oldApproval])->assertStatus(409);
        $this->patchJson($this->api("campaigns/{$c['id']}"), ['version' => 1, 'name' => 'Stale'])->assertStatus(409)->assertJsonPath('error_code', 'marketing_version_conflict');

        $this->postJson($this->api("campaigns/{$c['id']}/validate"))->assertOk();
        $this->postJson($this->api("campaigns/{$c['id']}/approval-requests"))->assertOk();
        $c3 = $this->postJson($this->api("campaigns/{$c['id']}/approve"), ['version' => 2])->assertOk()->json('data');
        $this->assertSame(60000, $c3['approval']['cap_minor']);
        $this->publish($c3, 'key-new-approval')->assertOk()->assertJsonPath('data.campaign.status', 'PUBLISHED');
    }

    public function test_a_delegation_lets_a_manager_approve_within_its_cap_until_it_is_revoked(): void
    {
        [$id, $integration] = $this->connected();
        $manager = $this->user('Mona', '+966500000002');
        $memberId = $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000002', 'role' => 'manager'])->assertCreated()->json('data.id');
        $delegation = $this->postJson($this->api("projects/{$id}/delegations"), ['member_id' => $memberId, 'cap_minor' => 60000, 'expires_at' => now()->addDays(10)->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.remaining_minor', 60000)->json('data');

        $this->as($manager);
        $this->postJson($this->api("projects/{$id}/delegations"), ['member_id' => $memberId, 'cap_minor' => 1000, 'expires_at' => now()->addDay()->toIso8601String()])->assertForbidden();
        $a = $this->approved($id, $integration);
        $this->assertTrue($a['approval']['by_delegation']);
        $this->getJson($this->api("projects/{$id}/delegations"))->assertJsonPath('data.0.used_minor', 50000)->assertJsonPath('data.0.remaining_minor', 10000);

        // Over what is left of the cap → refused.
        $b = $this->draft($id, $integration, ['daily_budget_minor' => 2000]);
        $this->postJson($this->api("campaigns/{$b['id']}/validate"))->assertOk();
        $this->postJson($this->api("campaigns/{$b['id']}/approval-requests"))->assertOk();
        $this->postJson($this->api("campaigns/{$b['id']}/approve"), ['version' => 1])->assertForbidden()->assertJsonPath('error_code', 'marketing_delegation_cap_exceeded');

        // AT-11: revoked → the approval given under it no longer publishes.
        $this->as($this->owner);
        $this->deleteJson($this->api("delegations/{$delegation['id']}"))->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->as($manager);
        $this->getJson($this->api("campaigns/{$a['id']}"))->assertJsonPath('data.status', 'AWAITING_APPROVAL')->assertJsonPath('data.approval', null);
        $this->publish($a, 'key-after-revoke')->assertStatus(409);
        $this->postJson($this->api("campaigns/{$a['id']}/approve"), ['version' => 1])->assertForbidden();
        $this->assertSame(0, $this->calls('POST', 'act_1/campaigns'));
    }

    public function test_an_expired_token_stops_publishing_and_asks_to_reconnect(): void
    {
        [$id, $integration] = $this->connected();
        $c = $this->approved($id, $integration);
        $this->routes['POST act_1/campaigns'] = fn () => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 400);

        // AT-08: EXPIRED + reconnect needed; the campaign keeps its approval (nothing was created).
        $this->publish($c, 'key-expired-0001')->assertOk()->assertJsonPath('data.campaign.status', 'APPROVED')->assertJsonPath('data.action.error_code', 'integration_expired');
        $this->getJson($this->api("integrations/{$integration}"))->assertJsonPath('data.status', 'EXPIRED')->assertJsonPath('data.reconnect_needed', true);
        $this->publish($c, 'key-expired-0002')->assertStatus(409)->assertJsonPath('error_code', 'marketing_integration_not_connected');

        // A refused campaign is FAILED with the platform's reason.
        $this->postJson($this->api("projects/{$id}/integrations/meta/connect"))->assertOk();
        $this->routes['POST act_1/campaigns'] = fn () => Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter', 'error_user_msg' => 'Budget too low for this currency']], 400);
        $this->publish($c, 'key-rejected-001')->assertOk()->assertJsonPath('data.campaign.status', 'FAILED')->assertJsonPath('data.campaign.last_error', 'Budget too low for this currency');
    }

    public function test_platform_metrics_are_read_as_actual_results_with_their_time(): void
    {
        [$id, $integration] = $this->connected();
        $c = $this->approved($id, $integration);
        $this->publish($c, 'key-metrics-0001')->assertOk();
        $this->routes['GET c-100/insights'] = ['data' => [
            ['date_start' => now()->subDay()->toDateString(), 'impressions' => '1200', 'clicks' => '60', 'spend' => '45.50',
                'actions' => [['action_type' => 'purchase', 'value' => '3']], 'action_values' => [['action_type' => 'purchase', 'value' => '300.00']]],
        ]];
        $this->postJson($this->api("campaigns/{$c['id']}/metrics/sync"))->assertOk()->assertJsonPath('data.rows', 1);
        $this->postJson($this->api("campaigns/{$c['id']}/metrics/sync"))->assertOk();
        $this->assertSame(1, MarketingMetric::query()->count());
        $row = MarketingMetric::query()->firstOrFail();
        $this->assertSame(['platform', 'actual', 4550, 3, 30000], [$row->source, $row->basis, $row->spend_minor, $row->conversions, $row->revenue_minor]);
        $this->getJson($this->api("campaigns/{$c['id']}/metrics"))->assertOk()->assertJsonCount(1, 'data.rows');
        $this->assertNotNull($this->getJson($this->api("campaigns/{$c['id']}/metrics"))->json('data.synced_at'));
        $this->getJson($this->api("projects/{$id}/kpis"))->assertJsonPath('data.measured.totals.spend_minor', 4550);
    }

    public function test_oauth_connects_with_a_one_time_state_and_disconnect_revokes(): void
    {
        config(['marketing.platforms.meta.sandbox' => false, 'marketing.platforms.meta.app_id' => '123']);
        $this->routes['GET oauth/access_token'] = ['access_token' => 'long-token', 'expires_in' => 5184000];
        $this->routes['GET me/adaccounts'] = ['data' => [
            ['id' => 'act_7', 'name' => 'Shop', 'currency' => 'SAR', 'account_status' => 1],
            ['id' => 'act_8', 'name' => 'Old', 'currency' => 'SAR', 'account_status' => 1],
        ]];
        $id = $this->readyProject();
        $url = $this->postJson($this->api("projects/{$id}/integrations/meta/connect"))->assertOk()->assertJsonPath('data.integration.status', 'PENDING')->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('ads_management,ads_read', $query['scope']);

        $this->get('/api/marketing/v1/oauth/meta/callback?state=wrong&code=abc')->assertStatus(400);
        $this->get('/api/marketing/v1/oauth/meta/callback?state='.$query['state'].'&code=abc')->assertOk()->assertSee('✅', false);
        // The state works once.
        $this->get('/api/marketing/v1/oauth/meta/callback?state='.$query['state'].'&code=abc')->assertStatus(400);

        $integration = $this->getJson($this->api("projects/{$id}/integrations"))->json('data.integrations.0');
        $this->assertSame('PENDING', $integration['status']);
        $this->assertCount(2, $integration['available_accounts']);
        $this->postJson($this->api("integrations/{$integration['id']}/account"), ['account_id' => 'act_9'])->assertStatus(422);
        $this->postJson($this->api("integrations/{$integration['id']}/account"), ['account_id' => 'act_7'])->assertOk()->assertJsonPath('data.status', 'CONNECTED')->assertJsonPath('data.account.id', 'act_7');

        $this->postJson($this->api("integrations/{$integration['id']}/disconnect"))->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->assertSame(1, $this->calls('DELETE', 'me/permissions'));
        $this->assertNull(MarketingIntegration::query()->firstOrFail()->credential_ref);
    }

    public function test_webhooks_need_a_valid_signature_and_a_repeat_is_handled_once(): void
    {
        [$id, $integration] = $this->connected();
        $c = $this->approved($id, $integration);
        $this->publish($c, 'key-webhook-0001')->assertOk();

        $this->get('/api/marketing/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42')->assertOk()->assertSee('42');
        $this->get('/api/marketing/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=42')->assertForbidden();

        $body = json_encode(['object' => 'ad_account', 'entry' => [['id' => 'act_1', 'changes' => [['field' => 'in_process_ad_objects', 'value' => ['id' => 'c-100', 'status' => 'ACTIVE']]]]]]);
        $this->call('POST', '/api/marketing/v1/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertForbidden();
        $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret');
        $this->call('POST', '/api/marketing/v1/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $body)->assertOk()->assertJsonPath('updated', 1);
        $this->call('POST', '/api/marketing/v1/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('ACTIVE', MarketingCampaign::query()->firstOrFail()->platform_status);
        $this->assertSame(1, DB::table('marketing_audit_events')->where('action', 'campaign.platform_status')->count());
    }
}
