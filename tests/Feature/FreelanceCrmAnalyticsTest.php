<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceCrmReminder;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceSubscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Phase 7 — growth, the first two: the freelancer's private CRM (FS-703: clients, past projects, notes, labels, follow-up reminders — by what
 * the plan's `crm` opens) and the advanced analytics (FS-704: where the earnings came from and how the portfolio does).
 */
class FreelanceCrmAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private User $mona;

    private User $omar;

    private User $huda;

    private Freelancer $sara;

    private Freelancer $lina;

    private FreelancePlan $plus;

    private FreelancePlan $pro;

    private FreelanceSpecialty $web;

    private int $projects = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'subscriptions'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->omar = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->huda = User::query()->create(['name' => 'Huda', 'phone' => '+966500000003', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->lina = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000092', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);

        $this->plus = FreelancePlan::query()->create(['slug' => 'plus', 'rank' => 1, 'name' => ['ar' => 'بلس', 'en' => 'Plus'], 'offers_monthly' => 40, 'analytics' => 1, 'crm' => 1]);
        $this->pro = FreelancePlan::query()->create(['slug' => 'pro', 'rank' => 2, 'name' => ['ar' => 'برو', 'en' => 'Pro'], 'offers_unlimited' => true, 'analytics' => 2, 'crm' => 2]);
        foreach ([[$this->plus, 3400], [$this->pro, 9000]] as [$plan, $monthly]) {
            FreelancePlanPrice::query()->create(['plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'period' => 'monthly', 'price_minor' => $monthly]);
        }
    }

    // -------------------------------------------------------------- helpers

    private function as(Freelancer|User $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], $who instanceof Freelancer ? 'freelance_api' : 'user_api');
    }

    private function subscribe(Freelancer $f, FreelancePlan $plan, array $over = []): FreelanceSubscription
    {
        return FreelanceSubscription::query()->create($over + [
            'freelancer_id' => $f->id, 'plan_id' => $plan->id, 'country_id' => $this->saudi->id, 'currency_code' => 'SAR', 'period' => 'monthly', 'price_minor' => 3400,
            'status' => 'active', 'auto_renew' => true, 'started_at' => now()->subDays(3), 'period_start' => now()->subDays(3), 'period_end' => now()->addDays(27),
        ]);
    }

    private function project(User $client, FreelanceProjectStatus $status = FreelanceProjectStatus::Published, ?int $service = null): FreelanceProject
    {
        $this->projects++;

        return FreelanceProject::query()->create([
            'client_id' => $client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => "Project {$this->projects}", 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 30, 'visibility' => 'public', 'status' => $status,
            'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    /** Sara's work for a client, running or finished, with what she earned from it (released money, paid `$ago` days back). */
    private function work(Freelancer $f, User $client, string $status, int $earned = 0, int $ago = 3): FreelanceProject
    {
        $p = $this->project($client, FreelanceProjectStatus::Closed);
        $o = FreelanceOffer::query()->create(['project_id' => $p->id, 'freelancer_id' => $f->id, 'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'details' => 'Done.', 'status' => 'accepted']);
        FreelanceAgreement::query()->create([
            'project_id' => $p->id, 'offer_id' => $o->id, 'client_id' => $client->id, 'freelancer_id' => $f->id, 'scope' => 'A logo.', 'price_minor' => 30000, 'currency_code' => 'SAR',
            'duration_days' => 5, 'policy' => [], 'money' => [], 'status' => $status, 'activated_at' => now()->subDays(20),
            'completed_at' => $status === FreelanceAgreement::COMPLETED ? now()->subDays($ago) : null,
        ]);
        if ($earned > 0) {
            // The test runs inside a transaction that is never committed, so deferring the checks lets an earning be written without its part.
            DB::statement('PRAGMA defer_foreign_keys = ON');
            DB::table('freelance_earnings')->insert([
                'freelancer_id' => $f->id, 'project_id' => $p->id, 'milestone_id' => 1, 'country_id' => $this->saudi->id, 'kind' => 'approved', 'amount_minor' => $earned,
                'currency_code' => 'SAR', 'status' => 'released', 'released_at' => now()->subDays($ago), 'key' => 'crm-'.$p->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $p;
    }

    /** An offer of Sara's still waiting on a client's open project (a lead). */
    private function lead(User $client, Freelancer $f): FreelanceProject
    {
        $p = $this->project($client);
        FreelanceOffer::query()->create(['project_id' => $p->id, 'freelancer_id' => $f->id, 'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'details' => 'Waiting.', 'status' => 'submitted']);

        return $p;
    }

    /** Omar (finished), Mona (running) and Huda (a lead) are Sara's clients. @return array<string, string> client name => CRM uuid */
    private function crmOfSara(): array
    {
        $this->work($this->sara, $this->omar, FreelanceAgreement::COMPLETED, 9000);
        $this->work($this->sara, $this->mona, FreelanceAgreement::ACTIVE);
        $this->lead($this->huda, $this->sara);
        $this->as($this->sara);

        return collect($this->getJson(self::API.'/crm/clients')->assertOk()->json('data.clients'))->pluck('uuid', 'name')->all();
    }

    // -------------------------------------------------------------- FS-703 the CRM

    public function test_a_plan_without_a_crm_is_locked_and_names_the_plan_that_opens_it(): void
    {
        $this->crmOfSara();
        $res = $this->getJson(self::API.'/crm/clients')->assertOk();
        $this->assertSame([0, true, 'plus', true], [$res->json('data.level'), $res->json('data.locked'), $res->json('data.upgrade.slug'), $res->json('data.subscriptions_enabled')]);
        $this->assertNull($res->json('data.clients'));

        // Not even by guessing: every other call is refused with the plan that opens it.
        $this->postJson(self::API.'/crm/clients/'.fake()->uuid().'/notes', ['body' => 'hello'])->assertStatus(403)->assertJsonPath('error_code', 'freelance_crm_locked');
    }

    public function test_the_clients_are_read_from_the_work_and_grouped_by_where_they_stand(): void
    {
        $this->subscribe($this->sara, $this->plus);
        $this->crmOfSara();

        $res = $this->getJson(self::API.'/crm/clients')->assertOk();
        $this->assertSame([1, false, 'pro'], [$res->json('data.level'), $res->json('data.locked'), $res->json('data.upgrade.slug')]);
        $this->assertSame(['all' => 3, 'active' => 1, 'past' => 1, 'leads' => 1, 'starred' => 0], $res->json('data.counts'));
        $byName = collect($res->json('data.clients'))->keyBy('name');
        $this->assertSame(['active', 'past', 'lead'], [$byName['Mona']['stage'], $byName['Omar']['stage'], $byName['Huda']['stage']]);
        $this->assertSame([1, 9000], [$byName['Omar']['projects'], $byName['Omar']['earned_minor']]);
        $this->assertTrue($byName['Huda']['waiting_offer']);
        $this->assertArrayNotHasKey('id', $byName['Omar'], 'a client is named by the CRM uuid, never by the user id');
        $this->assertSame([], $res->json('data.due'), 'reminders are for the higher level');

        $this->assertSame(['Omar'], array_column($this->getJson(self::API.'/crm/clients?tab=past')->json('data.clients'), 'name'));
        $this->assertSame(['Huda'], array_column($this->getJson(self::API.'/crm/clients?tab=leads')->json('data.clients'), 'name'));
        $this->assertSame(['Mona'], array_column($this->getJson(self::API.'/crm/clients?search=mon')->json('data.clients'), 'name'));
        $this->getJson(self::API.'/crm/clients?tab=nope')->assertStatus(422);
    }

    public function test_a_client_page_shows_the_projects_together_the_notes_and_what_is_waiting(): void
    {
        $this->subscribe($this->sara, $this->plus);
        $uuids = $this->crmOfSara();

        $omar = $this->getJson(self::API.'/crm/clients/'.$uuids['Omar'])->assertOk()->json('data.client');
        $this->assertSame(['completed', 9000], [$omar['history'][0]['status'], $omar['history'][0]['earned_minor']]);
        $this->assertSame([], $omar['leads']);
        $huda = $this->getJson(self::API.'/crm/clients/'.$uuids['Huda'])->assertOk()->json('data.client');
        $this->assertSame(['offer', 'submitted'], [$huda['leads'][0]['kind'], $huda['leads'][0]['status']]);
        $this->assertSame([], $huda['history']);

        // Notes: add, edit, delete — and the client never sees them.
        $note = $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes', ['body' => 'Pays late, loves blue.'])->assertCreated()->json('data.client.notes.0.uuid');
        $this->putJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes/'.$note, ['body' => 'Pays on time now.'])->assertOk()->assertJsonPath('data.client.notes.0.body', 'Pays on time now.');
        $this->assertSame(1, collect($this->getJson(self::API.'/crm/clients')->json('data.clients'))->firstWhere('name', 'Omar')['notes_count']);
        $this->deleteJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes/'.$note)->assertOk()->assertJsonPath('data.client.notes', []);
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes', ['body' => ''])->assertStatus(422);

        // The star is in the first level; the label and the reminders are not.
        $this->putJson(self::API.'/crm/clients/'.$uuids['Omar'], ['starred' => true])->assertOk()->assertJsonPath('data.client.starred', true);
        $this->assertSame(['Omar'], array_column($this->getJson(self::API.'/crm/clients?tab=starred')->json('data.clients'), 'name'));
        $this->putJson(self::API.'/crm/clients/'.$uuids['Omar'], ['label' => 'vip'])->assertStatus(403)->assertJsonPath('error_code', 'freelance_crm_locked');
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/reminders', ['title' => 'Call', 'due_at' => now()->addDay()->toIso8601String()])->assertStatus(403);
    }

    public function test_the_top_plan_adds_labels_and_reminders_and_tells_when_one_is_due_once(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $uuids = $this->crmOfSara();

        $this->putJson(self::API.'/crm/clients/'.$uuids['Omar'], ['label' => 'vip'])->assertOk()->assertJsonPath('data.client.label', 'vip');
        $this->putJson(self::API.'/crm/clients/'.$uuids['Omar'], ['label' => 'bogus'])->assertStatus(422);
        $this->putJson(self::API.'/crm/clients/'.$uuids['Omar'], ['label' => null])->assertOk()->assertJsonPath('data.client.label', null);

        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/reminders', ['title' => 'Back then', 'due_at' => now()->subDay()->toIso8601String()])->assertStatus(422)->assertJsonPath('error_code', 'freelance_crm_bad_due');
        $r = $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/reminders', ['title' => 'Ask for a review', 'due_at' => now()->addDays(2)->toIso8601String()])->assertCreated();
        $this->assertSame('Ask for a review', $r->json('data.client.reminders.0.title'));
        $this->assertSame('Omar', $this->getJson(self::API.'/crm/reminders')->json('data.reminders.0.client.name'));
        $this->assertCount(1, $this->getJson(self::API.'/crm/clients')->json('data.due'));

        // The app writes the moment with its own offset: it is kept as the same moment, not as the same clock time.
        $at = now()->addDays(3)->utc()->setTime(7, 0);
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/reminders', ['title' => 'Offset', 'due_at' => $at->copy()->setTimezone('+02:00')->format('Y-m-d\TH:i:sP')])->assertCreated();
        $this->assertSame($at->format('Y-m-d H:i:s'), FreelanceCrmReminder::query()->where('title', 'Offset')->firstOrFail()->due_at->utc()->format('Y-m-d H:i:s'));
        FreelanceCrmReminder::query()->where('title', 'Offset')->delete();

        // Due now: the command tells the freelancer once, and a done reminder is never told.
        $reminder = FreelanceCrmReminder::query()->firstOrFail();
        $reminder->update(['due_at' => now()->subMinute()]);
        Artisan::call('freelance:crm-reminders');
        $this->assertNotNull($reminder->refresh()->notified_at);
        $first = $reminder->notified_at;
        $this->travel(1)->hour();
        Artisan::call('freelance:crm-reminders');
        $this->assertEquals($first, $reminder->refresh()->notified_at, 'told once');

        $this->putJson(self::API.'/crm/clients/'.$uuids['Omar'].'/reminders/'.$reminder->uuid, ['done' => true])->assertOk()->assertJsonPath('data.client.reminders.0.overdue', false);
        $this->assertSame([], $this->getJson(self::API.'/crm/reminders')->json('data.reminders'));
        $this->deleteJson(self::API.'/crm/clients/'.$uuids['Omar'].'/reminders/'.$reminder->uuid)->assertOk()->assertJsonPath('data.client.reminders', []);
    }

    public function test_a_freelancer_cannot_reach_the_clients_of_another_and_a_lower_plan_hides_what_was_written(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $this->subscribe($this->lina, $this->pro);
        $uuids = $this->crmOfSara();
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes', ['body' => 'Private.'])->assertCreated();

        $this->as($this->lina);
        $this->assertSame(0, $this->getJson(self::API.'/crm/clients')->json('data.counts.all'));
        $this->getJson(self::API.'/crm/clients/'.$uuids['Omar'])->assertNotFound();
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes', ['body' => 'Mine now?'])->assertNotFound();

        // The plan ends: the screen is locked again, and the note is still there when the plan comes back.
        FreelanceSubscription::query()->where('freelancer_id', $this->sara->id)->update(['status' => 'cancelled', 'period_end' => now()->subDay(), 'auto_renew' => false]);
        $this->as($this->sara);
        $this->assertTrue($this->getJson(self::API.'/crm/clients')->json('data.locked'));
        $this->subscribe($this->sara, $this->plus);
        $this->assertSame('Private.', $this->getJson(self::API.'/crm/clients/'.$uuids['Omar'])->json('data.client.notes.0.body'));
    }

    public function test_the_notes_of_a_client_have_a_limit(): void
    {
        config(['freelance.crm.notes_max' => 2]);
        $this->subscribe($this->sara, $this->plus);
        $uuids = $this->crmOfSara();
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes', ['body' => 'one'])->assertCreated();
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes', ['body' => 'two'])->assertCreated();
        $this->postJson(self::API.'/crm/clients/'.$uuids['Omar'].'/notes', ['body' => 'three'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_crm_notes_full');
    }

    // -------------------------------------------------------------- FS-704 advanced analytics

    public function test_the_full_level_breaks_the_earnings_down_and_compares_with_the_stretch_before(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $this->work($this->sara, $this->omar, FreelanceAgreement::COMPLETED, 6000, 3);
        $this->work($this->sara, $this->omar, FreelanceAgreement::COMPLETED, 2000, 10);
        $this->work($this->sara, $this->mona, FreelanceAgreement::COMPLETED, 2000, 12);
        $this->work($this->sara, $this->mona, FreelanceAgreement::COMPLETED, 5000, 45); // the stretch before the last 30 days
        $this->as($this->sara);

        $a = $this->getJson(self::API.'/insights?days=30')->assertOk()->json('data.data.earnings');
        $this->assertSame(10000, $a['earned_minor']);
        $this->assertSame([5000, 100], [$a['analysis']['before_minor'], $a['analysis']['change_percent']]);
        $this->assertSame(['projects' => 10000, 'services' => 0, 'consultations' => 0], $a['analysis']['sources']);
        $this->assertSame([['name' => 'Omar', 'earned_minor' => 8000, 'share' => 80], ['name' => 'Mona', 'earned_minor' => 2000, 'share' => 20]], $a['analysis']['top_clients']);
        $this->assertSame([$this->web->id, 'Web', 10000, 100], [$a['analysis']['by_specialty'][0]['id'], $a['analysis']['by_specialty'][0]['name'], $a['analysis']['by_specialty'][0]['earned_minor'], $a['analysis']['by_specialty'][0]['share']]);
        $this->assertSame([3, 3333], [$a['analysis']['projects_paid'], $a['analysis']['average_project_minor']]);

        // The first level has the summary only.
        $this->subscribe($this->lina, $this->plus);
        $this->as($this->lina);
        $this->assertArrayNotHasKey('analysis', $this->getJson(self::API.'/insights')->json('data.data.earnings'));
        $this->assertArrayNotHasKey('portfolio', $this->getJson(self::API.'/insights')->json('data.data'));
    }

    public function test_the_portfolio_is_counted_work_by_work_per_day_never_per_person(): void
    {
        $this->subscribe($this->sara, $this->pro);
        $this->as($this->sara);
        $a = FreelancerPortfolioItem::query()->create(['freelancer_id' => $this->sara->id, 'title' => 'Clinic site', 'visibility' => 'public', 'rights_confirmed_at' => now()]);
        $b = FreelancerPortfolioItem::query()->create(['freelancer_id' => $this->sara->id, 'title' => 'Shop app', 'visibility' => 'clients_only', 'rights_confirmed_at' => now()]);
        FreelancerPortfolioItem::query()->create(['freelancer_id' => $this->sara->id, 'title' => 'Draft', 'visibility' => 'private', 'rights_confirmed_at' => now()]);

        // A client opens the portfolio and then two of its works; opening the same work again at once is not counted twice.
        $this->as($this->mona);
        $this->getJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio")->assertOk();
        $this->postJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio/{$a->uuid}/view")->assertOk();
        $this->postJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio/{$a->uuid}/view")->assertOk();
        $this->as($this->omar);
        $this->postJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio/{$a->uuid}/view")->assertOk();
        $this->postJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio/{$b->uuid}/view")->assertOk();
        // A private work is not there to open.
        $private = FreelancerPortfolioItem::query()->where('title', 'Draft')->firstOrFail();
        $this->postJson(self::CLIENT."/freelancers/{$this->sara->uuid}/portfolio/{$private->uuid}/view")->assertNotFound();
        $this->assertSame(3, (int) DB::table('freelance_profile_views')->where('kind', 'work')->sum('views'));

        $this->as($this->sara);
        $p = $this->getJson(self::API.'/insights?days=30')->assertOk()->json('data.data.portfolio');
        $this->assertSame([3, 2, 0, 1, 3], [$p['works'], $p['shown'], $p['verified'], $p['visits'], $p['opened']]);
        $this->assertSame([['Clinic site', 2], ['Shop app', 1]], array_map(fn ($w) => [$w['title'], $w['views']], $p['top']));
        $this->assertArrayNotHasKey('id', $p['top'][0]);

        // The visits of the profile and the services do not count the works.
        $views = $this->getJson(self::API.'/insights?days=30')->json('data.data.views');
        $this->assertSame([1, 0], [$views['profile'], $views['service']]);
    }

    public function test_the_plans_desk_sets_the_crm_level_of_a_plan(): void
    {
        $this->assertSame([1, 2], [(int) $this->plus->crm, (int) $this->pro->crm]);
        $this->assertSame(0, (int) FreelancePlan::query()->create(['slug' => 'free', 'rank' => 0, 'name' => ['ar' => 'مجانية', 'en' => 'Free']])->crm);
    }
}
