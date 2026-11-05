<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceChangeRequest;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProjectHealth;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-106: how a running project is doing, from dates and records only - healthy, at risk, delayed or blocked - with the reasons and one next
 * step for each side, and a single alert when it gets worse.
 */
class FreelanceProjectHealthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private User $client;

    private Freelancer $sara;

    private FreelanceProject $project;

    private FreelanceAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'wallet_payment'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::InProgress, 'published_at' => now()->subDays(10),
        ]);
        $offer = FreelanceOffer::query()->create([
            'project_id' => $this->project->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21,
            'details' => 'I will build it in two steps with a demo each week.', 'status' => 'accepted',
        ]);
        $this->agreement = FreelanceAgreement::query()->create([
            'project_id' => $this->project->id, 'offer_id' => $offer->id, 'client_id' => $this->client->id, 'freelancer_id' => $this->sara->id, 'scope' => 'The site',
            'price_minor' => 350000, 'currency_code' => 'SAR', 'duration_days' => 21, 'policy' => [], 'money' => [], 'status' => FreelanceAgreement::ACTIVE, 'activated_at' => now()->subDays(2),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attrs */
    private function part(int $position, string $status, array $attrs = []): FreelanceMilestone
    {
        return FreelanceMilestone::query()->create([
            'agreement_id' => $this->agreement->id, 'project_id' => $this->project->id, 'position' => $position, 'title' => 'Part '.$position, 'amount_minor' => 100000,
            'duration_days' => 10, 'status' => $status,
        ] + $attrs);
    }

    private function health(): array
    {
        return app(ProjectHealth::class)->evaluate($this->agreement->fresh());
    }

    private function codes(): array
    {
        return array_column($this->health()['reasons'], 'code');
    }

    public function test_a_project_on_time_is_healthy(): void
    {
        $this->part(1, 'in_progress', ['started_at' => now()->subDays(1), 'funded_at' => now()->subDays(2)]);
        $this->assertSame(['state' => 'healthy', 'reasons' => []], $this->health());
        $this->agreement->forceFill(['status' => FreelanceAgreement::COMPLETED])->save();
        $this->assertNull(app(ProjectHealth::class)->evaluate($this->agreement->fresh()));
    }

    public function test_a_part_near_its_date_is_at_risk_and_past_it_is_delayed(): void
    {
        $part = $this->part(1, 'in_progress', ['started_at' => now()->subDays(9), 'funded_at' => now()->subDays(10)]);
        $health = $this->health();
        $this->assertSame('at_risk', $health['state']);
        $this->assertSame(['part_due_soon'], $this->codes());
        $this->assertSame('freelancer', $health['reasons'][0]['who']);

        $part->forceFill(['started_at' => now()->subDays(12)])->save();
        $this->assertSame('delayed', $this->health()['state']);
        $this->assertSame(['part_overdue'], $this->codes());
    }

    public function test_the_other_slow_things_are_at_risk(): void
    {
        $this->part(1, 'delivered', ['delivered_at' => now()->subDays(4), 'started_at' => now()->subDays(6)]);
        $this->part(2, 'funded', ['funded_at' => now()->subDays(3)]);
        $this->agreement->forceFill(['report_every_days' => 7, 'next_report_due_at' => now()->subHour()])->save();
        $change = FreelanceChangeRequest::query()->create([
            'agreement_id' => $this->agreement->id, 'project_id' => $this->project->id, 'type' => 'extend_days', 'requested_by' => 'client', 'requester_id' => $this->client->id,
            'payload' => ['days' => 3], 'status' => FreelanceChangeRequest::PENDING, 'respond_by' => now()->addDays(3),
        ]);
        $change->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->assertSame('at_risk', $this->health()['state']);
        $this->assertEqualsCanonicalizing(['review_waiting', 'part_not_started', 'report_late', 'change_waiting'], $this->codes());
        $byCode = collect($this->health()['reasons'])->keyBy('code');
        $this->assertSame('client', $byCode['review_waiting']['who']);
        $this->assertSame('freelancer', $byCode['change_waiting']['who']); // the client asked: the freelancer answers
    }

    public function test_a_dispute_or_an_unpaid_next_part_blocks(): void
    {
        $this->part(1, 'approved', ['started_at' => now()->subDays(8), 'approved_at' => now()->subDays(4)]);
        $next = $this->part(2, 'unfunded');
        $this->assertSame('blocked', $this->health()['state']);
        $this->assertSame(['part_unfunded'], $this->codes());
        $this->assertSame('client', $this->health()['reasons'][0]['who']);

        // Paid just now: not blocked yet.
        FreelanceMilestone::query()->where('position', 1)->update(['approved_at' => now()->subHours(5)]);
        $this->assertSame('healthy', $this->health()['state']);

        $next->forceFill(['status' => 'disputed'])->save();
        $this->assertSame(['dispute_open'], $this->codes());
        $this->assertSame('blocked', $this->health()['state']);
    }

    public function test_each_side_gets_its_own_next_step(): void
    {
        $this->part(1, 'delivered', ['delivered_at' => now()->subDays(4), 'started_at' => now()->subDays(6)]);
        $this->part(2, 'in_progress', ['started_at' => now()->subDays(12)]);

        $client = app(ProjectHealth::class)->present($this->agreement->fresh(), 'client');
        $freelancer = app(ProjectHealth::class)->present($this->agreement->fresh(), 'freelancer');
        $this->assertSame('delayed', $client['state']);
        $this->assertSame(['code' => 'review_waiting', 'mine' => true], $client['next_step']);
        $this->assertSame(['code' => 'part_overdue', 'mine' => true], $freelancer['next_step']);
        // For the client the overdue part is somebody else's to do.
        $this->assertFalse(collect($client['reasons'])->firstWhere('code', 'part_overdue')['mine']);
    }

    public function test_both_apps_read_it_on_the_agreement(): void
    {
        $this->part(1, 'in_progress', ['started_at' => now()->subDays(12)]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/agreement")->assertOk()
            ->assertJsonPath('data.health.state', 'delayed')->assertJsonPath('data.health.reasons.0.code', 'part_overdue')
            ->assertJsonPath('data.health.reasons.0.mine', false)->assertJsonPath('data.health.next_step.mine', false);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertOk()
            ->assertJsonPath('data.health.state', 'delayed')->assertJsonPath('data.health.next_step.mine', true);
    }

    public function test_a_setback_is_told_once_and_a_recovery_is_only_recorded(): void
    {
        $part = $this->part(1, 'in_progress', ['started_at' => now()->subDays(9)]);
        $health = app(ProjectHealth::class);

        // At risk because of the freelancer: only the freelancer is asked.
        $this->assertSame(1, $health->alertWorse());
        $this->assertSame('at_risk', $this->agreement->fresh()->health_state);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.health.at_risk')->count());
        $this->assertSame(0, $this->client->notifications()->count());
        $this->assertSame(0, $health->alertWorse(), 'nothing changed, nothing sent');

        // Delayed: both are told.
        $part->forceFill(['started_at' => now()->subDays(12)])->save();
        $this->assertSame(2, $health->alertWorse());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.health.delayed')->count());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.health.delayed')->count());

        // Back on track: recorded, nobody told - so a later setback is told again.
        $part->forceFill(['started_at' => now()])->save();
        $this->assertSame(0, $health->alertWorse());
        $this->assertSame('healthy', $this->agreement->fresh()->health_state);
        $part->forceFill(['started_at' => now()->subDays(12)])->save();
        $this->assertSame(2, $health->alertWorse());
    }

    public function test_the_command_runs(): void
    {
        $this->part(1, 'in_progress', ['started_at' => now()->subDays(12)]);
        $this->artisan('freelance:health-alerts')->expectsOutput('2 alerted.')->assertSuccessful();
    }
}
