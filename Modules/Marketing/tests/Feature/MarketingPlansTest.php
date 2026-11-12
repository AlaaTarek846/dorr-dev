<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingPlanVersion;
use Modules\Marketing\Models\MarketingSource;
use Modules\Marketing\Tests\Feature\Concerns\MarketingSetup;
use Tests\TestCase;

/** Plans (U04/U05): schema, critic, versions, review and approval (AT-01, AT-03), finance, export (AT-20), AI down (AT-09). */
class MarketingPlansTest extends TestCase
{
    use MarketingSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarketing();
    }

    private function generate(string $id, string $type = 'gtm'): array
    {
        return $this->postJson($this->api("projects/{$id}/plans"), ['type' => $type])->assertStatus(202)->json('data');
    }

    public function test_a_gtm_plan_is_written_reviewed_and_approved_by_the_owner_or_a_manager(): void
    {
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer());
        $started = $this->generate($id);
        $planId = $started['plan']['id'];
        $this->assertSame('plan', $started['job']['type']);

        $plan = $this->getJson($this->api("plans/{$planId}"))->assertOk()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.current_version.version', 1)
            ->assertJsonPath('data.current_version.content.phases.0.title', 'Pilot')
            ->assertJsonPath('data.current_version.content.horizon_days', 90)
            ->json('data');
        $this->assertSame(64, strlen($plan['current_version']['content_hash']));
        // No finance yet: `scenarios` is an empty JSON object, never `[]` (the apps read a map).
        $raw = $this->getJson($this->api("plans/{$planId}"))->getContent();
        $this->assertStringContainsString('"scenarios":{}', $raw);
        $this->assertStringContainsString('go/no-go', $this->aiPrompts[0]['system']);

        $manager = $this->user('Mona', '+966500000002');
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000002', 'role' => 'manager'])->assertCreated();

        // Submitting needs the current version; approving needs review first.
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertStatus(409)->assertJsonPath('error_code', 'marketing_invalid_transition');
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 2])->assertStatus(409)->assertJsonPath('data.current_version', 1);
        $this->as($manager);
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 1])->assertOk()->assertJsonPath('data.status', 'READY_FOR_REVIEW');
        $this->assertTrue($this->getJson($this->api("projects/{$id}"))->json('data.abilities.approve'));

        // An analyst can't approve; a manager can (owner decision 2026-11-11).
        $analyst = $this->user('Adel', '+966500000003');
        $this->as($this->owner);
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000003', 'role' => 'analyst'])->assertCreated();
        $this->as($analyst);
        $this->assertFalse($this->getJson($this->api("projects/{$id}"))->json('data.abilities.approve'));
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertForbidden();
        $this->postJson($this->api("plans/{$planId}/return"), ['version' => 1])->assertForbidden();

        $this->as($manager);
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.approval.version', 1)
            ->assertJsonPath('data.approval.payload_hash', $plan['current_version']['content_hash']);
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertStatus(409);
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'plan.approved']);
    }

    public function test_changing_an_approved_plan_writes_a_new_version_and_the_old_approval_stops_counting(): void
    {
        // AT-03 / POL-02 / POL-14.
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer());
        $planId = $this->generate($id)['plan']['id'];
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 1])->assertOk();
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertOk();

        $content = $this->getJson($this->api("plans/{$planId}"))->json('data.current_version.content');
        $content['phases'][0]['tasks'][] = 'Partner with two cafes';
        $this->putJson($this->api("plans/{$planId}"), ['version' => 1, 'content' => $content])->assertOk()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.current_version.version', 2)
            ->assertJsonPath('data.current_version.origin', 'edit')->assertJsonPath('data.approval', null);
        $this->assertSame(MarketingApproval::SUPERSEDED, MarketingApproval::query()->firstOrFail()->status);

        // v1 stays readable and unchanged.
        $this->getJson($this->api("plans/{$planId}/versions/1"))->assertOk()->assertJsonCount(1, 'data.content.phases.0.tasks');
        $this->getJson($this->api("plans/{$planId}/versions"))->assertJsonCount(2, 'data');

        // Saving over v1 again: conflict. The same content again: nothing to save.
        $this->putJson($this->api("plans/{$planId}"), ['version' => 1, 'content' => $content])->assertStatus(409);
        $this->putJson($this->api("plans/{$planId}"), ['version' => 2, 'content' => $content])->assertStatus(422)->assertJsonPath('error_code', 'marketing_no_changes');

        // New finance figures are a change too.
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 2])->assertOk();
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 2])->assertOk();
        $this->putJson($this->api("plans/{$planId}/finance"), ['version' => 2, 'inputs' => ['marketing_spend_minor' => 300000]])->assertOk()
            ->assertJsonPath('data.current_version.version', 3)->assertJsonPath('data.status', 'DRAFT');
        $this->assertSame(0, MarketingApproval::query()->where('status', 'active')->count());
        $this->assertSame(2, MarketingApproval::query()->where('status', 'superseded')->count());
    }

    public function test_without_a_budget_the_plan_says_so_and_invents_none(): void
    {
        // AT-01.
        $id = $this->readyProject(null);
        $this->aiReturns([
            'title' => 'Business plan', 'summary' => 'A bakery',
            'sections' => [
                ['key' => 'problem', 'title' => 'Problem', 'body' => 'No fresh bread'],
                ['key' => 'solution', 'title' => 'Solution', 'body' => 'Morning delivery'],
                ['key' => 'target_market', 'title' => 'Market', 'body' => 'Families'],
                ['key' => 'made_up', 'title' => 'X', 'body' => 'Y'],
            ],
            'financial' => ['budget_monthly_minor' => 1000000, 'notes' => 'Spend 10,000 a month'],
            'sources' => ['S404'],
        ]);
        $planId = $this->generate($id, 'business')['plan']['id'];
        $version = $this->getJson($this->api("plans/{$planId}"))->json('data.current_version');

        $this->assertNull($version['content']['financial']['budget_monthly_minor']);
        $this->assertContains(__('marketing.plans.budget_unknown'), $version['content']['assumptions']);
        $this->assertSame([], $version['content']['sources']);
        $this->assertCount(3, $version['content']['sections']);
        $codes = collect($version['critic'])->pluck('code')->all();
        foreach (['budget_missing', 'invented_budget_removed', 'unknown_sources_removed', 'numbers_without_sources'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertStringContainsString('UNKNOWN', $this->aiPrompts[0]['system']);
        $this->assertStringContainsString('"monthly_budget_minor":null', $this->aiPrompts[0]['user']);
    }

    public function test_plans_cite_only_the_projects_own_sources(): void
    {
        $id = $this->readyProject();
        $this->searchResults = $this->tavilyResults(3);
        $this->aiReturns(['summary' => 'x', 'findings' => [['claim' => 'Demand grew 12%', 'sources' => ['S1'], 'confidence' => 'high']], 'gaps' => []]);
        $this->postJson($this->api("projects/{$id}/research-runs"), ['kind' => 'market'])->assertStatus(202);
        $ref = 'S'.MarketingSource::query()->where('ref', 'S1')->value('id');

        $this->aiReturns($this->gtmAnswer(['sources' => [$ref, 'S999999']]));
        $planId = $this->generate($id)['plan']['id'];

        // The findings went in with the project-wide refs.
        $this->assertStringContainsString('"sources":["'.$ref.'"]', $this->aiPrompts[1]['user']);
        $this->getJson($this->api("plans/{$planId}"))->assertJsonPath('data.current_version.content.sources', [$ref]);

        // Another owner's source can't be cited in an edit either.
        $content = $this->getJson($this->api("plans/{$planId}"))->json('data.current_version.content');
        $content['sources'] = [$ref, 'S1'];
        $this->putJson($this->api("plans/{$planId}"), ['version' => 1, 'content' => array_merge($content, ['summary' => 'changed'])])->assertOk()
            ->assertJsonPath('data.current_version.content.sources', [$ref]);
    }

    public function test_an_unusable_ai_answer_is_retried_once_then_the_job_fails(): void
    {
        $id = $this->readyProject();
        $this->aiReturns(['title' => 'Only a title'], $this->gtmAnswer());
        $planId = $this->generate($id)['plan']['id'];
        $this->getJson($this->api("plans/{$planId}"))->assertJsonPath('data.current_version.version', 1);
        $this->assertStringContainsString('missing: summary, phases', $this->aiPrompts[1]['system']);

        $other = $this->readyPlanJob($id, 'marketing', [['title' => 'x'], ['title' => 'y']]);
        $this->assertSame('FAILED', $other['status']);
        $this->assertSame('invalid_ai_output', $other['error_code']);
        $this->getJson($this->api("projects/{$id}/plans"))->assertJsonPath('data.1.current_version', null);
    }

    public function test_a_blocking_issue_keeps_the_plan_out_of_review(): void
    {
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer(['phases' => [['day_from' => 40, 'day_to' => 10, 'title' => 'Bad', 'tasks' => [], 'kpis' => [], 'go_no_go_criteria' => []]]]));
        $planId = $this->generate($id)['plan']['id'];
        $codes = collect($this->getJson($this->api("plans/{$planId}"))->json('data.current_version.critic'))->pluck('code')->all();
        $this->assertContains('invalid_phase_range', $codes);
        $this->assertContains('phase_without_go_no_go', $codes);
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 1])->assertStatus(422)->assertJsonPath('error_code', 'marketing_plan_has_blocking_issues');

        // A person fixes it: the new version is clean and can go to review.
        $content = $this->getJson($this->api("plans/{$planId}"))->json('data.current_version.content');
        $content['phases'] = [['day_from' => 1, 'day_to' => 30, 'title' => 'Good', 'tasks' => ['a'], 'kpis' => ['b'], 'go_no_go_criteria' => ['c']]];
        $this->putJson($this->api("plans/{$planId}"), ['version' => 1, 'content' => $content])->assertOk();
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 2])->assertOk();

        // An edit that drops what a plan needs is refused.
        $content['phases'] = [];
        $this->putJson($this->api("plans/{$planId}"), ['version' => 2, 'content' => $content])->assertStatus(422)->assertJsonPath('data.missing', ['phases']);
    }

    public function test_finance_is_computed_by_code_with_three_scenarios_and_no_division_by_zero(): void
    {
        // AT-16 and spec 09.
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer());
        $planId = $this->generate($id)['plan']['id'];

        $this->putJson($this->api("plans/{$planId}/finance"), ['version' => 1, 'inputs' => ['price_minor' => -1]])->assertUnprocessable();
        $plan = $this->putJson($this->api("plans/{$planId}/finance"), ['version' => 1, 'inputs' => [
            'price_minor' => 2500, 'variable_cost_minor' => 1000, 'fixed_costs_minor' => 300000,
            'marketing_spend_minor' => 200000, 'expected_new_customers' => 100, 'expected_conversions' => 400,
            'attributed_revenue_minor' => 0, 'monthly_churn_pct' => 10,
        ]])->assertOk()->json('data.current_version');

        $base = $plan['scenarios']['base']['outputs'];
        $this->assertSame(1500, $base['contribution_margin_per_unit_minor']['value']);
        $this->assertSame(200, $base['break_even_units']['value']);
        $this->assertSame(2000, $base['cac_minor']['value']);
        $this->assertSame(500, $base['cpa_minor']['value']);
        $this->assertEquals(0, $base['roas']['value']);
        $this->assertSame(15000, $base['ltv_minor']['value']);
        $this->assertSame(1500 * 100 - 300000 - 200000, $base['monthly_profit_minor']['value']);
        $this->assertSame('estimated', $base['roas']['basis']);
        $this->assertSame(70, $plan['scenarios']['conservative']['inputs']['expected_new_customers']);
        $this->assertSame(220000, $plan['scenarios']['conservative']['inputs']['marketing_spend_minor']);
        $this->assertSame(130, $plan['scenarios']['optimistic']['inputs']['expected_new_customers']);
        $this->assertSame('fin-1', $plan['scenarios']['base']['calculation_version']);

        // No spend and no customers: not computed, with the reason — never a made-up number.
        $plan = $this->putJson($this->api("plans/{$planId}/finance"), ['version' => 2, 'inputs' => [
            'price_minor' => 1000, 'variable_cost_minor' => 1500, 'marketing_spend_minor' => 0, 'expected_new_customers' => 0, 'fixed_costs_minor' => 1000,
        ]])->assertOk()->json('data.current_version.scenarios.base.outputs');
        $this->assertNull($plan['roas']['value']);
        $this->assertSame('no_spend', $plan['roas']['reason']);
        $this->assertSame('no_customers', $plan['cac_minor']['reason']);
        $this->assertSame('non_positive_margin', $plan['break_even_units']['reason']);
        $this->assertSame('missing_input', $plan['cpa_minor']['reason']);
        $this->assertSame(3, MarketingPlanVersion::query()->count());
    }

    public function test_a_saved_plan_opens_while_the_ai_is_down(): void
    {
        // AT-09 / POL-19.
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer());
        $planId = $this->generate($id)['plan']['id'];

        $this->aiDown = true;
        $this->getJson($this->api("plans/{$planId}"))->assertOk()->assertJsonPath('data.current_version.version', 1);
        $this->get($this->api("plans/{$planId}/versions/1/export?format=docx"))->assertOk();
        $job = $this->generate($id)['job'];
        $this->getJson($this->api("jobs/{$job['id']}"))->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.error_code', 'ai_unavailable');
        $this->getJson($this->api("plans/{$planId}"))->assertJsonPath('data.current_version.version', 1);
    }

    public function test_an_arabic_plan_exports_to_pdf_and_docx(): void
    {
        // AT-20.
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer(['title' => 'خطة دخول السوق في جدة', 'summary' => 'نبدأ بحيّين ثم المدينة كلها.']));
        $planId = $this->withHeaders(['Accept-Language' => 'ar'])->postJson($this->api("projects/{$id}/plans"), ['type' => 'gtm'])->json('data.plan.id');
        $this->putJson($this->api("plans/{$planId}/finance"), ['version' => 1, 'inputs' => ['price_minor' => 2500, 'variable_cost_minor' => 1000, 'fixed_costs_minor' => 300000]])->assertOk();

        $docx = $this->get($this->api("plans/{$planId}/versions/2/export?format=docx"))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('gtm-v2.docx', (string) $docx->headers->get('Content-Disposition'));
        $zip = tempnam(sys_get_temp_dir(), 'mk');
        file_put_contents($zip, $docx->getContent());
        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($zip) === true);
        $xml = (string) $archive->getFromName('word/document.xml');
        $archive->close();
        @unlink($zip);
        $this->assertStringContainsString('خطة دخول السوق في جدة', $xml);
        $this->assertStringContainsString('w:bidi', $xml);

        $pdf = $this->get($this->api("plans/{$planId}/versions/2/export?format=pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get($this->api("plans/{$planId}/versions/2/export?format=exe"))->assertStatus(422);
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'plan.exported']);
    }

    public function test_one_generation_per_type_at_a_time_and_the_package_limit(): void
    {
        $id = $this->readyProject();
        foreach (range(1, 5) as $i) {
            $this->aiReturns($this->gtmAnswer(['summary' => "Version {$i}"]));
            $this->generate($id);
        }
        $this->postJson($this->api("projects/{$id}/plans"), ['type' => 'gtm'])->assertStatus(429)->assertJsonPath('data.limit_key', 'plan_generations');
        // One plan per type: the five generations are five versions of it.
        $this->getJson($this->api("projects/{$id}/plans"))->assertJsonCount(1, 'data')->assertJsonPath('data.0.versions_count', 5);
        $this->postJson($this->api("projects/{$id}/plans"), ['type' => 'nope'])->assertUnprocessable();
    }

    public function test_archiving_revokes_the_approval_and_stops_changes(): void
    {
        $id = $this->readyProject();
        $this->aiReturns($this->gtmAnswer());
        $planId = $this->generate($id)['plan']['id'];
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 1])->assertOk();
        $this->postJson($this->api("plans/{$planId}/return"), ['version' => 1, 'note' => 'More detail'])->assertOk()->assertJsonPath('data.status', 'DRAFT');
        $this->postJson($this->api("plans/{$planId}/submit"), ['version' => 1])->assertOk();
        $this->postJson($this->api("plans/{$planId}/approve"), ['version' => 1])->assertOk();

        $this->postJson($this->api("plans/{$planId}/archive"))->assertOk()->assertJsonPath('data.status', 'ARCHIVED')->assertJsonPath('data.approval', null);
        $this->assertSame(MarketingApproval::REVOKED, MarketingApproval::query()->firstOrFail()->status);
        $content = $this->getJson($this->api("plans/{$planId}"))->json('data.current_version.content');
        $this->putJson($this->api("plans/{$planId}"), ['version' => 1, 'content' => array_merge($content, ['summary' => 'new'])])->assertStatus(409);
    }

    /** @param  list<array<string, mixed>>  $answers */
    private function readyPlanJob(string $project, string $type, array $answers): array
    {
        $this->aiReturns(...$answers);

        $job = $this->postJson($this->api("projects/{$project}/plans"), ['type' => $type])->assertStatus(202)->json('data.job.id');

        return $this->getJson($this->api("jobs/{$job}"))->json('data');
    }
}
