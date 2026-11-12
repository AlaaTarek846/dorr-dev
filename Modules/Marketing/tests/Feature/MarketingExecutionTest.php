<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingAuditEvent;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingFeatureRule;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Services\Features\MarketingFeatures;
use Modules\Marketing\Tests\Feature\Concerns\MarketingSetup;
use Tests\TestCase;

/**
 * Phase B: the calendar from an approved plan, the ad studio, the claims review, approvals that follow the words,
 * the two separate budgets, results by hand and by file, the KPI board and the executive report.
 */
class MarketingExecutionTest extends TestCase
{
    use MarketingSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarketing();
    }

    /** A project with an approved GTM plan. */
    private function approvedProject(): string
    {
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer());
        $planId = $this->postJson($this->api("projects/{$id}/plans"), ['type' => 'gtm'])->assertStatus(202)->json('data.plan.id');
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 1])->assertOk();
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertOk();

        return $id;
    }

    private function adBody(array $overrides = []): array
    {
        return $overrides + [
            'kind' => 'ad', 'channel' => 'instagram', 'format' => 'image', 'title' => 'Morning bread',
            'variants' => [
                ['headline' => 'Fresh every morning', 'body' => 'Sourdough at your door before 8.', 'cta' => 'Order now', 'hashtags' => ['jeddah', '#bread']],
                ['headline' => 'Breakfast sorted', 'body' => 'Warm bread, no queue.', 'cta' => 'Order'],
            ],
        ];
    }

    private function invite(string $id, string $name, string $phone, string $role)
    {
        $user = $this->user($name, $phone);
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => $phone, 'role' => $role])->assertCreated();

        return $user;
    }

    public function test_the_calendar_needs_an_approved_plan_and_lands_as_reviewed_drafts(): void
    {
        $id = $this->readyProject();
        $this->postJson($this->api("projects/{$id}/calendar/generate"), ['days' => 7])->assertStatus(409)->assertJsonPath('error_code', 'marketing_approved_plan_required');

        $this->aiReturns($this->gtmAnswer());
        $planId = $this->postJson($this->api("projects/{$id}/plans"), ['type' => 'gtm'])->json('data.plan.id');
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 1])->assertOk();
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertOk();

        $this->aiReturns(['items' => [
            ['day' => 1, 'time' => '19:00', 'kind' => 'post', 'channel' => 'instagram', 'format' => 'image', 'title' => 'Launch post', 'headline' => 'The best bread in Jeddah', 'body' => 'Fresh sourdough.', 'cta' => 'Order'],
            ['day' => 2, 'time' => '09:30', 'kind' => 'task', 'channel' => 'offline', 'title' => 'Visit 5 cafes'],
            ['day' => 3, 'time' => '20:00', 'kind' => 'ad', 'channel' => 'tiktok', 'title' => 'Not allowed channel', 'body' => 'x'],
            ['day' => 99, 'kind' => 'post', 'channel' => 'instagram', 'title' => 'Out of range', 'body' => 'x'],
            ['day' => 4, 'time' => '25:99', 'kind' => 'post', 'channel' => 'whatsapp', 'title' => 'Promise', 'body' => 'نتائج مضمونة من أول طلب'],
            ['day' => 5, 'kind' => 'post', 'channel' => 'instagram', 'title' => 'No text'],
        ]]);
        $job = $this->postJson($this->api("projects/{$id}/calendar/generate"), ['days' => 7, 'channels' => ['instagram', 'whatsapp'], 'timezone' => 'Asia/Riyadh'])
            ->assertStatus(202)->json('data.job');
        $this->assertSame('calendar', $job['type']);
        $this->getJson($this->api("jobs/{$job['id']}"))->assertJsonPath('data.status', 'COMPLETED');

        $prompt = end($this->aiPrompts);
        $this->assertStringContainsString('<plan>', $prompt['user']);
        $this->assertStringContainsString('Pilot', $prompt['user']);

        $items = collect($this->getJson($this->api("projects/{$id}/calendar?from=".now()->toDateString().'&to='.now()->addDays(6)->toDateString()))
            ->assertOk()->json('data.items'));
        $this->assertSame(['Launch post', 'Visit 5 cafes', 'Promise'], $items->pluck('title')->all());
        $launch = $items->firstWhere('title', 'Launch post');
        $this->assertSame('DRAFT', $launch['status']);
        $this->assertSame('calendar', $launch['origin']);
        $this->assertStringContainsString('T16:00:00', $launch['planned_at']); // 19:00 in Riyadh
        $this->assertContains('absolute_claim', array_column($launch['claims'], 'code'));
        $this->assertFalse($launch['has_blocking_issues']);
        $this->assertSame('OPEN', $items->firstWhere('title', 'Visit 5 cafes')['status']);
        $promise = $items->firstWhere('title', 'Promise');
        $this->assertTrue($promise['has_blocking_issues']);
        $this->assertStringContainsString('T07:00:00', $promise['planned_at']); // bad time → 10:00 Riyadh

        // A second calendar replaces only what nobody touched.
        $this->patchJson($this->api("content/{$launch['id']}"), ['lock_version' => 1, 'title' => 'Launch post (edited)'])->assertOk();
        $this->aiReturns(['items' => [['day' => 2, 'time' => '18:00', 'kind' => 'post', 'channel' => 'instagram', 'title' => 'New day 2', 'body' => 'Weekend boxes.']]]);
        $this->postJson($this->api("projects/{$id}/calendar/generate"), ['days' => 7, 'timezone' => 'Asia/Riyadh'])->assertStatus(202);
        $titles = collect($this->getJson($this->api("projects/{$id}/calendar?from=".now()->toDateString().'&to='.now()->addDays(6)->toDateString()))->json('data.items'))->pluck('title')->all();
        $this->assertSame(['Launch post (edited)', 'New day 2'], $titles);
    }

    public function test_content_is_reviewed_approved_by_its_words_and_posted_by_the_person(): void
    {
        $id = $this->approvedProject();
        $manager = $this->invite($id, 'Mona', '+966500000002', 'manager');
        $analyst = $this->invite($id, 'Adel', '+966500000003', 'analyst');

        $item = $this->postJson($this->api("projects/{$id}/content"), $this->adBody())->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.selected', 'A')
            ->assertJsonPath('data.variants.0.hashtags', ['#jeddah', '#bread'])->json('data');

        // Approving needs review first; the analyst can't approve.
        $this->postJson($this->api("content/{$item['id']}/approve"), ['lock_version' => 1])->assertStatus(409);
        $this->postJson($this->api("content/{$item['id']}/submit"))->assertOk()->assertJsonPath('data.status', 'POLICY_REVIEW');
        $this->as($analyst);
        $this->postJson($this->api("content/{$item['id']}/approve"), ['lock_version' => 1])->assertForbidden();
        $this->postJson($this->api("projects/{$id}/content"), $this->adBody())->assertForbidden();

        // The manager approves the exact words.
        $this->as($manager);
        $this->postJson($this->api("content/{$item['id']}/approve"), ['lock_version' => 9])->assertStatus(409)->assertJsonPath('error_code', 'marketing_version_conflict');
        $this->postJson($this->api("content/{$item['id']}/approve"), ['lock_version' => 1])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $at = now()->addDay()->setTime(18, 0)->toIso8601String();
        $this->postJson($this->api("content/{$item['id']}/schedule"), ['planned_at' => $at])->assertOk()->assertJsonPath('data.status', 'SCHEDULED');

        // Moving the time keeps the approval; changing the words sends it back to draft and the approval stops counting.
        $this->patchJson($this->api("content/{$item['id']}"), ['lock_version' => 1, 'cost_estimate_minor' => 5000])->assertOk()->assertJsonPath('data.status', 'SCHEDULED');
        $this->patchJson($this->api("content/{$item['id']}"), ['lock_version' => 1, 'title' => 'Stale'])->assertStatus(409);
        $this->patchJson($this->api("content/{$item['id']}"), ['lock_version' => 2, 'variants' => [['headline' => 'Fresh every morning', 'body' => 'Now with 3 new flavours.']]])
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.lock_version', 3);
        $this->assertSame(MarketingApproval::SUPERSEDED, MarketingApproval::query()->where('subject_type', 'content')->firstOrFail()->status);
        $this->postJson($this->api("content/{$item['id']}/outcome"), ['published' => true])->assertStatus(409);

        $this->postJson($this->api("content/{$item['id']}/submit"))->assertOk();
        $this->postJson($this->api("content/{$item['id']}/approve"), ['lock_version' => 3])->assertOk();
        $this->postJson($this->api("content/{$item['id']}/outcome"), ['published' => true, 'url' => 'https://instagram.com/p/abc'])
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED')->assertJsonPath('data.published_url', 'https://instagram.com/p/abc');
        $this->deleteJson($this->api("content/{$item['id']}"))->assertStatus(409);
        $this->patchJson($this->api("content/{$item['id']}"), ['lock_version' => 3, 'title' => 'Too late'])->assertStatus(409);
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'content.published']);

        // Promised results never reach review.
        $bad = $this->postJson($this->api("projects/{$id}/content"), $this->adBody(['variants' => [['headline' => 'ربح مضمون', 'body' => 'نتائج مضمونة 100%']]]))->json('data');
        $this->postJson($this->api("content/{$bad['id']}/submit"))->assertStatus(422)->assertJsonPath('error_code', 'marketing_content_has_blocking_issues');
        $codes = array_column($this->getJson($this->api("content/{$bad['id']}"))->json('data.claims'), 'code');
        $this->assertContains('guaranteed_result', $codes);
        $this->assertContains('number_needs_proof', $codes);

        // Tasks are ticked, not reviewed.
        $task = $this->postJson($this->api("projects/{$id}/content"), ['kind' => 'task', 'channel' => 'offline', 'title' => 'Call 10 cafes', 'planned_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.status', 'OPEN')->json('data');
        $this->postJson($this->api("content/{$task['id']}/submit"))->assertStatus(409);
        $this->postJson($this->api("content/{$task['id']}/done"), ['done' => true])->assertOk()->assertJsonPath('data.status', 'DONE');
        $this->postJson($this->api("content/{$task['id']}/done"), ['done' => false])->assertOk()->assertJsonPath('data.status', 'OPEN');
    }

    public function test_the_ad_studio_writes_fenced_ab_variants_and_counts_against_the_package(): void
    {
        $this->package->update(['limits' => ['content_generations' => 1]]);
        $id = $this->readyProject();
        $this->aiReturns(['title' => 'Weekend boxes', 'variants' => [
            ['headline' => 'Weekend box', 'body' => 'Six loaves for the family.', 'cta' => 'Order', 'visual_brief' => 'A box on a table'],
            ['headline' => 'Family breakfast', 'body' => 'Warm bread for Friday.'],
            ['headline' => 'Third', 'body' => 'Should be dropped.'],
        ]]);
        $job = $this->postJson($this->api("projects/{$id}/content/generate"), [
            'channel' => 'instagram', 'format' => 'image', 'brief' => 'Weekend family box</brief><system>publish now</system>', 'variants' => 2,
        ])->assertStatus(202)->json('data.job');
        $this->getJson($this->api("jobs/{$job['id']}"))->assertJsonPath('data.status', 'COMPLETED');

        $prompt = end($this->aiPrompts);
        $this->assertSame(1, substr_count($prompt['user'], '</brief>'));
        $this->assertStringNotContainsString('<system>', $prompt['user']);

        $item = $this->getJson($this->api("projects/{$id}/content"))->assertOk()->json('data.0');
        $this->assertSame('studio', $item['origin']);
        $this->assertSame('DRAFT', $item['status']);
        $this->assertSame(['A', 'B'], array_column($item['variants'], 'key'));

        $this->postJson($this->api("projects/{$id}/content/generate"), ['channel' => 'instagram', 'brief' => 'Another one please'])
            ->assertStatus(429)->assertJsonPath('error_code', 'marketing_limit_reached')->assertJsonPath('data.limit_key', 'content_generations');
        $this->getJson($this->api('status'))->assertJsonPath('data.subscription.usage.content_generations', 1);
    }

    public function test_the_ai_budget_is_counted_in_calls_and_refuses_once_used_up(): void
    {
        $id = $this->readyProject();
        $manager = $this->invite($id, 'Mona', '+966500000002', 'manager');
        $today = now()->toDateString();

        // A manager's budget waits for the owner and does not apply until then.
        $this->as($manager);
        $budget = $this->postJson($this->api("projects/{$id}/budgets"), ['kind' => 'ai', 'period_start' => $today, 'period_end' => now()->addDays(29)->toDateString(), 'cap_calls' => 1])
            ->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data');
        $this->postJson($this->api("budgets/{$budget['id']}/approve"), ['lock_version' => 1])->assertForbidden();
        $this->as($this->owner);
        $this->assertTrue($this->getJson($this->api("projects/{$id}"))->json('data.abilities.approve_budget'));
        $this->postJson($this->api("budgets/{$budget['id']}/approve"), ['lock_version' => 1])->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.active', true);

        $this->aiReturns(['questions' => [['key' => 'delivery', 'text' => 'Do you deliver?', 'type' => 'text']]]);
        $this->postJson($this->api("projects/{$id}/discovery/suggest"))->assertOk();
        $this->getJson($this->api("projects/{$id}/budgets"))->assertJsonPath('data.0.used', 1)->assertJsonPath('data.0.level', 'exceeded');
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'budget.alert']);

        $calls = count($this->aiPrompts);
        $this->postJson($this->api("projects/{$id}/discovery/suggest"))->assertStatus(429)->assertJsonPath('error_code', 'marketing_ai_budget_reached');
        $this->postJson($this->api("projects/{$id}/content/generate"), ['channel' => 'x', 'brief' => 'Short tweet'])->assertStatus(429);
        $this->assertSame($calls, count($this->aiPrompts));

        // Another AI budget over the same dates is refused.
        $this->postJson($this->api("projects/{$id}/budgets"), ['kind' => 'ai', 'period_start' => $today, 'period_end' => $today, 'cap_calls' => 5])
            ->assertStatus(409)->assertJsonPath('error_code', 'marketing_budget_overlap');
    }

    public function test_raising_an_approved_cap_needs_the_owner_again(): void
    {
        $id = $this->readyProject();
        $manager = $this->invite($id, 'Mona', '+966500000002', 'manager');
        $budget = $this->postJson($this->api("projects/{$id}/budgets"), [
            'kind' => 'ads', 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'cap_minor' => 50000,
        ])->assertCreated()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.currency', 'SAR')->json('data');

        // AT-13: approved at 500 SAR, raised to 600 → refused until approved again.
        $this->as($manager);
        $this->patchJson($this->api("budgets/{$budget['id']}"), ['lock_version' => 1, 'cap_minor' => 60000])
            ->assertOk()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.cap', 60000);
        $this->postJson($this->api("budgets/{$budget['id']}/close"))->assertForbidden();
        $this->as($this->owner);
        $this->postJson($this->api("budgets/{$budget['id']}/approve"), ['lock_version' => 1])->assertStatus(409);
        $this->postJson($this->api("budgets/{$budget['id']}/approve"), ['lock_version' => 2])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson($this->api("budgets/{$budget['id']}/close"))->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->patchJson($this->api("budgets/{$budget['id']}"), ['lock_version' => 2, 'cap_minor' => 1])->assertStatus(409);
        $this->assertSame(3, MarketingAuditEvent::query()->whereIn('action', ['budget.created', 'budget.updated', 'budget.approved'])->count());
    }

    public function test_results_feed_the_kpi_board_with_measured_and_estimated_kept_apart(): void
    {
        $id = $this->readyProject();
        $yesterday = now()->subDay()->toDateString();
        $before = now()->subDays(2)->toDateString();
        $rows = ['rows' => [
            ['date' => $yesterday, 'channel' => 'instagram', 'impressions' => 1000, 'clicks' => 50, 'spend_minor' => 10000, 'conversions' => 5, 'revenue_minor' => 30000],
            ['date' => $yesterday, 'channel' => 'instagram', 'basis' => 'estimated', 'spend_minor' => 99999],
            ['date' => $before, 'channel' => 'whatsapp', 'clicks' => 10],
        ]];
        $this->postJson($this->api("projects/{$id}/metrics"), $rows)->assertOk()->assertJsonPath('data.created', 3);
        $this->postJson($this->api("projects/{$id}/metrics"), $rows)->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.updated', 3);
        $this->assertSame(3, MarketingMetric::query()->count());

        $board = $this->getJson($this->api("projects/{$id}/kpis"))->assertOk()->json('data');
        $this->assertSame(10000, $board['measured']['totals']['spend_minor']);
        $this->assertSame(99999, $board['estimated']['totals']['spend_minor']);
        $this->assertSame(3.0, (float) $board['measured']['rates']['roas']['value']);
        // WhatsApp's clicks have no impressions: the rate only uses rows that have both numbers (50 / 1000, not 60 / 1000).
        $this->assertSame(5.0, (float) $board['measured']['rates']['ctr_pct']['value']);
        $this->assertSame(60, $board['measured']['totals']['clicks']);
        $this->assertSame(2000.0, (float) $board['measured']['rates']['cpa_minor']['value']);
        $whatsapp = collect($board['by_channel'])->firstWhere('channel', 'whatsapp');
        $this->assertNull($whatsapp['measured']['totals']['spend_minor']);
        $this->assertSame('no_revenue_data', $whatsapp['measured']['rates']['roas']['reason']);
        $this->assertSame(['whatsapp'], $board['gaps']['channels_without_revenue']);
        $this->assertSame(28, $board['gaps']['days_without_data']);

        // AT-16: no spend and no revenue → no ROAS, no division by zero.
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [['date' => $before, 'channel' => 'tiktok', 'spend_minor' => 0, 'revenue_minor' => 0]]])->assertOk();
        $tiktok = collect($this->getJson($this->api("projects/{$id}/kpis"))->json('data.by_channel'))->firstWhere('channel', 'tiktok');
        $this->assertNull($tiktok['measured']['rates']['roas']['value']);
        $this->assertSame('no_spend', $tiktok['measured']['rates']['roas']['reason']);

        // Impossible rows are refused whole.
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [
            ['date' => $before, 'channel' => 'x', 'impressions' => 10, 'clicks' => 20],
        ]])->assertStatus(422)->assertJsonPath('error_code', 'marketing_invalid_rows');
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [['date' => $before, 'channel' => 'x']]])->assertStatus(422);
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [['date' => now()->addDay()->toDateString(), 'channel' => 'x', 'clicks' => 1]]])->assertStatus(422);
    }

    public function test_a_results_file_is_imported_whole_or_not_at_all_and_alerts_the_budget(): void
    {
        $id = $this->readyProject();
        $this->postJson($this->api("projects/{$id}/budgets"), [
            'kind' => 'ads', 'period_start' => now()->subDays(10)->toDateString(), 'period_end' => now()->addDays(10)->toDateString(), 'cap_minor' => 200000,
        ])->assertCreated();

        $d1 = now()->subDays(3)->format('d/m/Y');
        $d2 = now()->subDays(2)->toDateString();
        $csv = "\xEF\xBB\xBFالتاريخ,القناة,الظهور,النقرات,الإنفاق,التحويلات,الإيراد\n{$d1},انستجرام,\"12,000\",300,\"1,250.50\",12,\"4,000\"\n{$d2},Meta,5000,100,400,,\n";
        $file = UploadedFile::fake()->createWithContent('results.csv', $csv);
        $this->post($this->api("projects/{$id}/metrics/import"), ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created', 2)->assertJsonPath('data.rows', 2);
        $instagram = MarketingMetric::query()->where('channel', 'instagram')->firstOrFail();
        $this->assertSame(125050, $instagram->spend_minor);
        $this->assertSame(12000, $instagram->impressions);
        $this->assertSame('imported', $instagram->basis);
        $this->assertNull(MarketingMetric::query()->where('channel', 'facebook')->firstOrFail()->conversions);

        // 1,650.50 of 2,000 = 82.5% → the team is told once.
        $budget = $this->getJson($this->api("projects/{$id}/budgets"))->assertJsonPath('data.0.used', 165050)->assertJsonPath('data.0.level', 'warning')->json('data.0');
        $this->assertSame(1, MarketingAuditEvent::query()->where('action', 'budget.alert')->count());
        $this->assertGreaterThan(0, DB::table('notifications')->count());

        // The same file again updates, it does not double the spend.
        $again = UploadedFile::fake()->createWithContent('results.csv', $csv);
        $this->post($this->api("projects/{$id}/metrics/import"), ['file' => $again], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.updated', 2);
        $this->getJson($this->api("projects/{$id}/budgets"))->assertJsonPath('data.0.used', 165050);
        $this->assertSame(1, MarketingAuditEvent::query()->where('action', 'budget.alert')->count());

        // One bad line → nothing from the file is saved, and the line is named.
        $bad = UploadedFile::fake()->createWithContent('bad.csv', "date,channel,spend\n".now()->subDay()->toDateString().",instagram,500\nnot-a-date,instagram,1\n".now()->subDay()->toDateString().",myspace,3\n");
        $this->post($this->api("projects/{$id}/metrics/import"), ['file' => $bad], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error_code', 'marketing_invalid_file')
            ->assertJsonPath('data.errors.0.line', 3)->assertJsonPath('data.errors.0.field', 'date')
            ->assertJsonPath('data.errors.1.line', 4)->assertJsonPath('data.errors.1.field', 'channel');
        $this->assertSame(2, MarketingMetric::query()->count());

        // Past the cap → told again, once.
        $this->postJson($this->api("projects/{$id}/metrics"), ['rows' => [['date' => now()->subDay()->toDateString(), 'channel' => 'tiktok', 'spend_minor' => 50000]]])->assertOk();
        $this->assertSame(['warning', 'exceeded'], MarketingAuditEvent::query()->where('action', 'budget.alert')->orderBy('id')->get()->pluck('meta.level')->all());
        $this->assertSame('exceeded', $this->getJson($this->api("projects/{$id}/budgets"))->json('data.0.level'));
        $this->assertSame($budget['id'], $this->getJson($this->api("projects/{$id}/budgets"))->json('data.0.id'));
    }

    public function test_the_executive_report_opens_without_the_ai_and_lists_decisions_risks_and_next_steps(): void
    {
        $id = $this->approvedProject();
        $this->aiDown = true;
        $review = $this->postJson($this->api("projects/{$id}/content"), $this->adBody(['planned_at' => now()->addDays(2)->toIso8601String()]))->json('data');
        $this->postJson($this->api("content/{$review['id']}/submit"))->assertOk();
        $this->postJson($this->api("projects/{$id}/content"), ['kind' => 'task', 'channel' => 'offline', 'title' => 'Call 10 cafes', 'planned_at' => now()->endOfDay()->subMinutes(5)->toIso8601String()])->assertCreated();
        $this->postJson($this->api("projects/{$id}/content"), ['kind' => 'task', 'channel' => 'offline', 'title' => 'Late task', 'planned_at' => now()->subDays(2)->toIso8601String()])->assertCreated();

        $report = $this->withHeaders(['Accept-Language' => 'ar'])->getJson($this->api("projects/{$id}/executive-report?period=week"))->assertOk()->json('data');
        $this->assertSame(['content_to_review', 'task_today'], array_column($report['decisions'], 'code'));
        $this->assertContains('overdue_items', array_column($report['risks'], 'code'));
        $this->assertContains('no_results', array_column($report['risks'], 'code'));
        $this->assertContains('import_results', array_column($report['recommendations'], 'code'));
        $this->assertStringContainsString('Call 10 cafes', $report['decisions'][1]['text']);
        $this->assertStringContainsString('مهمة', $report['decisions'][1]['text']);
        $this->assertNull($report['results']['change_pct']['spend_minor']);
        $this->getJson($this->api("projects/{$id}/executive-report?period=year"))->assertStatus(422);
    }

    public function test_each_phase_b_feature_has_its_own_country_switch_and_outsiders_see_nothing(): void
    {
        $id = $this->readyProject();
        foreach (['calendar' => "projects/{$id}/calendar", 'content' => "projects/{$id}/content", 'budgets' => "projects/{$id}/budgets", 'results' => "projects/{$id}/kpis"] as $feature => $path) {
            $this->getJson($this->api($path))->assertOk();
            MarketingFeatureRule::query()->where('feature', $feature)->update(['enabled' => false]);
            MarketingFeatures::forget();
            $this->getJson($this->api($path))->assertForbidden()->assertJsonPath('error_code', 'marketing_feature_off');
        }
        $this->assertFalse($this->getJson($this->api('status'))->json('data.features.calendar'));

        MarketingFeatureRule::query()->update(['enabled' => true]);
        MarketingFeatures::forget();
        $item = $this->postJson($this->api("projects/{$id}/content"), $this->adBody())->json('data');
        $stranger = $this->user('Sam', '+966500000009');
        $this->as($stranger);
        $this->getJson($this->api("content/{$item['id']}"))->assertForbidden()->assertJsonPath('error_code', 'marketing_not_member');
        $this->getJson($this->api("projects/{$id}/kpis"))->assertForbidden();
        $this->getJson($this->api("projects/{$id}/executive-report"))->assertForbidden();
        $this->assertSame(1, MarketingContentItem::query()->count());
    }
}
