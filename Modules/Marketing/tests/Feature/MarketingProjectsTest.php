<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketing\Models\MarketingAuditEvent;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Tests\Feature\Concerns\MarketingSetup;
use Tests\TestCase;

/** Projects (U01), the project team (D2, U13), discovery (U02) and access control (POL-03, AT-07, AT-17). */
class MarketingProjectsTest extends TestCase
{
    use MarketingSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarketing();
    }

    public function test_the_owner_creates_lists_and_edits_a_project_with_optimistic_locking(): void
    {
        $this->subscribe($this->owner);
        $this->as($this->owner);

        $this->postJson($this->api('projects'), ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'industry']);
        $this->postJson($this->api('projects'), ['name' => 'Bakery', 'industry' => 'bakery', 'lock_version' => 3])->assertUnprocessable();

        $project = $this->createProject()->assertCreated()
            ->assertJsonPath('data.my_role', 'owner')->assertJsonPath('data.abilities.approve', true)
            ->assertJsonPath('data.currency', 'SAR')->assertJsonPath('data.lock_version', 1)->assertJsonPath('data.cities.0', 'Jeddah')
            ->json('data');

        $this->getJson($this->api('projects'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1);
        $this->getJson($this->api('projects?q=nothing'))->assertOk()->assertJsonCount(0, 'data');

        $this->patchJson($this->api("projects/{$project['id']}"), ['name' => 'Lamar Bakery & Cafe', 'lock_version' => 1])
            ->assertOk()->assertJsonPath('data.name', 'Lamar Bakery & Cafe')->assertJsonPath('data.lock_version', 2);
        // Saving over a version someone else already changed: refused, nothing written.
        $this->patchJson($this->api("projects/{$project['id']}"), ['name' => 'Stale', 'lock_version' => 1])
            ->assertStatus(409)->assertJsonPath('error_code', 'marketing_version_conflict')->assertJsonPath('data.lock_version', 2);
        $this->getJson($this->api("projects/{$project['id']}"))->assertJsonPath('data.name', 'Lamar Bakery & Cafe');

        $this->assertSame(['project.created', 'project.updated'], MarketingAuditEvent::query()->where('action', 'like', 'project.%')->orderBy('id')->pluck('action')->all());

        $this->deleteJson($this->api("projects/{$project['id']}"))->assertOk();
        $this->getJson($this->api("projects/{$project['id']}"))->assertNotFound();
    }

    public function test_the_team_roles_decide_what_each_member_may_do(): void
    {
        $id = $this->readyProject();
        $manager = $this->user('Mona', '+966500000002');
        $analyst = $this->user('Adel', '+966500000003');
        $viewer = $this->user('Vera', '+966500000004');

        // Only the owner manages the team; up to the package's members limit (2).
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000002', 'role' => 'manager'])->assertCreated()->assertJsonPath('data.role', 'manager');
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000003', 'role' => 'analyst'])->assertCreated();
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000004', 'role' => 'viewer'])->assertStatus(429)->assertJsonPath('data.limit_key', 'members');
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000002', 'role' => 'viewer'])->assertStatus(409);
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966599999999', 'role' => 'viewer'])->assertNotFound();
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000001', 'role' => 'viewer'])->assertStatus(422);
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000004', 'role' => 'owner'])->assertUnprocessable();

        $members = $this->getJson($this->api("projects/{$id}/members"))->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.role', 'owner')->json('data');
        $ownerRow = $members[0]['id'];
        $analystRow = collect($members)->firstWhere('name', 'Adel')['id'];

        // The manager edits and approves (2026-11-11) but does not manage the team.
        $this->as($manager);
        $this->getJson($this->api('projects'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.my_role', 'manager')->assertJsonPath('data.0.abilities.approve', true)->assertJsonPath('data.0.abilities.manage_team', false);
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['team_size' => 4]])->assertOk();
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000004', 'role' => 'viewer'])->assertForbidden()->assertJsonPath('error_code', 'marketing_role_forbidden');
        $this->postJson($this->api("projects/{$id}/archive"), ['archived' => true])->assertForbidden();
        $this->deleteJson($this->api("projects/{$id}"))->assertForbidden();

        // The analyst reads and researches, never edits.
        $this->as($analyst);
        $this->getJson($this->api("projects/{$id}"))->assertOk();
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['team_size' => 9]])->assertForbidden();
        $this->postJson($this->api("projects/{$id}/plans"), ['type' => 'gtm'])->assertForbidden();
        $this->searchResults = [];
        $this->postJson($this->api("projects/{$id}/research-runs"), ['kind' => 'market'])->assertStatus(202);

        // The owner changes and removes roles; the owner's own row never changes.
        $this->as($this->owner);
        $this->patchJson($this->api("projects/{$id}/members/{$analystRow}"), ['role' => 'viewer'])->assertOk()->assertJsonPath('data.role', 'viewer');
        $this->patchJson($this->api("projects/{$id}/members/{$ownerRow}"), ['role' => 'viewer'])->assertUnprocessable();
        $this->deleteJson($this->api("projects/{$id}/members/{$ownerRow}"))->assertStatus(422);
        $this->postJson($this->api("projects/{$id}/leave"))->assertStatus(422);

        $this->as($analyst);
        $this->postJson($this->api("projects/{$id}/research-runs"), ['kind' => 'market'])->assertForbidden();
        $this->postJson($this->api("projects/{$id}/leave"))->assertOk();
        $this->getJson($this->api("projects/{$id}"))->assertForbidden();

        $this->as($this->owner);
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000004', 'role' => 'viewer'])->assertCreated();
        $this->assertSame(3, MarketingProjectMember::query()->count());
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'member.role_changed']);
        $this->assertDatabaseHas('marketing_audit_events', ['action' => 'member.left']);
    }

    public function test_a_member_of_another_project_is_refused_and_the_refusal_is_audited(): void
    {
        $id = $this->readyProject();
        $stranger = $this->user('Sami', '+966500000009');
        $this->subscribe($stranger);
        $this->as($stranger);
        $other = $this->createProject(['name' => 'Other'])->assertCreated()->json('data.id');

        // AT-07 / AT-17: refused, nothing about the project leaks, the attempt is written down.
        $response = $this->getJson($this->api("projects/{$id}"))->assertForbidden()->assertJsonPath('error_code', 'marketing_not_member');
        $this->assertArrayNotHasKey('name', (array) $response->json('data'));
        foreach (['discovery', 'members', 'research-runs', 'plans', 'jobs'] as $path) {
            $this->getJson($this->api("projects/{$id}/{$path}"))->assertForbidden();
        }
        $this->patchJson($this->api("projects/{$id}"), ['name' => 'Hijack', 'lock_version' => 1])->assertForbidden();
        $this->postJson($this->api("projects/{$id}/members"), ['phone' => '+966500000009', 'role' => 'manager'])->assertForbidden();
        $this->assertGreaterThanOrEqual(8, MarketingAuditEvent::query()->where('action', 'access.denied')->where('actor_id', $stranger->id)->count());

        $this->getJson($this->api('projects'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other);
        $this->getJson($this->api('projects/00000000-0000-0000-0000-000000000000'))->assertNotFound();
    }

    public function test_a_guest_gets_401(): void
    {
        $this->getJson($this->api('status'))->assertUnauthorized();
        $this->getJson($this->api('projects'))->assertUnauthorized();
    }

    public function test_discovery_asks_only_what_is_missing_and_never_assumes_the_budget(): void
    {
        $this->subscribe($this->owner);
        $this->as($this->owner);
        $id = $this->createProject()->json('data.id');

        $state = $this->getJson($this->api("projects/{$id}/discovery"))->assertOk()->assertJsonPath('data.complete', false)->json('data');
        $byKey = collect($state['questions'])->keyBy('key');
        $this->assertTrue($byKey['cities']['answered']);
        $this->assertFalse($byKey['monthly_budget']['answered']);
        $this->assertTrue($byKey['monthly_budget']['required']);

        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['monthly_budget' => -5]])->assertUnprocessable();
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['monthly_budget' => '5000']])->assertUnprocessable();
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['team_size' => 0]])->assertUnprocessable();
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['hacker' => 'x']])->assertUnprocessable();

        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => [
            'offer' => 'Sourdough', 'customer_segments' => ['Families', 'Families', ' '], 'monthly_budget' => 300000, 'goals' => ['100 orders a week'],
        ]])->assertOk()->assertJsonPath('data.complete', true);
        $this->getJson($this->api("projects/{$id}"))->assertJsonPath('data.profile.monthly_budget_minor', 300000)
            ->assertJsonPath('data.profile.customer_segments', ['Families'])->assertJsonPath('data.goals.0', '100 orders a week');

        // "I don't know yet": the budget is cleared and asked again — never kept as a guess.
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['monthly_budget' => null]])->assertOk()->assertJsonPath('data.complete', false);
        $this->getJson($this->api("projects/{$id}"))->assertJsonPath('data.profile.monthly_budget_minor', null);

        // The apps clear with `clear` (Gson drops JSON nulls); an unknown key there is refused like in `answers`.
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['monthly_budget' => 1000]])->assertOk();
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => (object) [], 'clear' => ['monthly_budget', 'goals']])->assertOk();
        $this->getJson($this->api("projects/{$id}"))->assertJsonPath('data.profile.monthly_budget_minor', null)->assertJsonPath('data.goals', []);
        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => (object) [], 'clear' => ['hacker']])->assertUnprocessable();
    }

    public function test_the_ai_adds_questions_about_the_business_only(): void
    {
        $id = $this->readyProject();
        $this->aiReturns(['questions' => [['text' => 'Do you sell wholesale?'], ['text' => 'Do you sell wholesale?'], ['text' => ''], 'What are your opening hours?']]);

        $state = $this->postJson($this->api("projects/{$id}/discovery/suggest"))->assertOk()->json('data');
        $ai = collect($state['questions'])->where('origin', 'ai')->values();
        $this->assertSame(['Do you sell wholesale?', 'What are your opening hours?'], $ai->pluck('text')->all());
        $this->assertStringContainsString('Never ask about the country, the city or the budget', $this->aiPrompts[0]['system']);
        $this->assertStringContainsString('<project>', $this->aiPrompts[0]['user']);

        $this->putJson($this->api("projects/{$id}/discovery"), ['answers' => ['extra_1' => 'Yes, to cafes']])->assertOk();
        $this->getJson($this->api("projects/{$id}/discovery"))->assertJsonPath('data.questions.7.value', 'Yes, to cafes');

        // AI down: a clear 503, the saved answers stay.
        $this->aiDown = true;
        $this->postJson($this->api("projects/{$id}/discovery/suggest"))->assertStatus(503)->assertJsonPath('error_code', 'marketing_ai_unavailable');
        $this->getJson($this->api("projects/{$id}/discovery"))->assertJsonPath('data.questions.7.value', 'Yes, to cafes');
    }
}
