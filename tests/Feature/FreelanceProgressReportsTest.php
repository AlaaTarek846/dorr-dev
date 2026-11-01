<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProgressReports;
use Modules\User\Models\User;
use Tests\TestCase;

/** FL-605: the optional progress reports on an agreement - asked every 3/7/14 days (or never), sent by the freelancer, reminded and flagged late once. */
class FreelanceProgressReportsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $client;

    private FreelanceProject $project;

    private Freelancer $sara;

    private Freelancer $lina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        $f = Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->asFreelancer($f);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();

        return $f;
    }

    private function asFreelancer(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client->refresh(), [], 'user_api');
    }

    private function offer(Freelancer $f): FreelanceOffer
    {
        return FreelanceOffer::query()->where('freelancer_id', $f->id)->firstOrFail();
    }

    /** The client looks at the offers and chooses Sara. */
    private function chooseSara(): void
    {
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/choose")->assertOk();
    }

    /** Draft with a cadence, send, approve: an active agreement. */
    private function activeAgreement(?int $every): void
    {
        $this->chooseSara();
        $url = self::CLIENT."/{$this->project->uuid}/agreement";
        $this->putJson($url, ['scope' => 'A booking site.'] + ($every === null ? [] : ['report_every_days' => $every]))->assertOk();
        $this->postJson($url.'/send')->assertOk();
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();
    }

    public function test_reports_are_optional_and_only_3_7_or_14_days_can_be_asked(): void
    {
        $this->chooseSara();
        $url = self::CLIENT."/{$this->project->uuid}/agreement";
        $this->putJson($url, ['scope' => 'A booking site.', 'report_every_days' => 5])->assertStatus(422)->assertJsonPath('error_code', 'freelance_report_days_invalid');
        $this->putJson($url, ['report_every_days' => 7])->assertOk()->assertJsonPath('data.reports.every_days', 7)->assertJsonPath('data.reports.allowed_days', [3, 7, 14]);
        $this->putJson($url, ['report_every_days' => null])->assertOk()->assertJsonPath('data.reports.every_days', null);
    }

    public function test_the_freelancer_sends_reports_the_client_reads_them_and_each_one_starts_the_next_period(): void
    {
        $this->activeAgreement(7);
        $agreement = FreelanceAgreement::query()->firstOrFail();
        $this->assertSame(7, $agreement->report_every_days);
        $this->assertTrue($agreement->next_report_due_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        $this->asFreelancer($this->sara);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/agreement")->assertJsonPath('data.can.report', true);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/reports", ['note' => 'Design is done, building the booking form.', 'percent' => 40])->assertCreated()
            ->assertJsonPath('data.reports.0.percent', 40)->assertJsonPath('data.summary.late', false);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/reports", ['note' => 'ab'])->assertStatus(422);
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.report.received')->count());

        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/reports")->assertOk()->assertJsonCount(1, 'data.reports')->assertJsonPath('data.reports.0.note', 'Design is done, building the booking form.');
        // The client cannot send one.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/reports", ['note' => 'Trying to send'])->assertForbidden();
    }

    public function test_a_report_can_be_sent_without_a_cadence_and_nobody_else_sees_them(): void
    {
        $this->activeAgreement(null);
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/reports", ['note' => 'A quick update from me.'])->assertCreated()->assertJsonPath('data.summary.next_due_at', null);

        $this->asFreelancer($this->lina);
        $this->getJson(self::FREELANCER."/{$this->project->uuid}/reports")->assertNotFound();
    }

    public function test_a_due_report_reminds_the_freelancer_and_a_day_later_tells_the_client_once_each(): void
    {
        $this->activeAgreement(3);
        $agreement = FreelanceAgreement::query()->firstOrFail();
        $reports = app(ProgressReports::class);

        $this->assertSame(0, $reports->remindDue());

        $agreement->update(['next_report_due_at' => now()->subHour()]);
        $this->assertSame(1, $reports->remindDue());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.report.due')->count());
        $this->assertSame(0, $this->client->notifications()->where('data->event', 'freelance.report.late')->count());
        $this->assertSame(0, $reports->remindDue(), 'nothing is sent twice for the same due date');

        $agreement->update(['next_report_due_at' => now()->subHours(25)]);
        $this->assertSame(1, $reports->remindDue());
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.report.late')->count());
        $this->assertSame(0, $reports->remindDue());
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/reports")->assertJsonPath('data.summary.late', true);

        // A report ends the lateness and starts the next period.
        $this->asFreelancer($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/reports", ['note' => 'Sorry for the delay, here is the update.'])->assertCreated()->assertJsonPath('data.summary.late', false);
        $this->assertNull($agreement->refresh()->report_late_notified_at);
        $this->assertSame(0, $reports->remindDue());
    }

    public function test_no_cadence_means_no_reminders(): void
    {
        $this->activeAgreement(null);
        $this->assertSame(0, app(ProgressReports::class)->remindDue());
    }
}
