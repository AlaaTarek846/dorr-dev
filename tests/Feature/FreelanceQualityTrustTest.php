<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSkillChallenge;
use Modules\Freelance\Models\FreelanceSkillQuestion;
use Modules\Freelance\Models\FreelanceSkillTest;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Models\FreelancerSkillBadge;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 6 — quality and trust (owner's decisions 2026-11-12): verified works (FS-601), skill levels and the practical challenge (FS-602), the
 * reputation figures with their sources and their corrections (FS-604), and the quality desk (FS-605).
 */
class FreelanceQualityTrustTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private const ADMIN = '/api/admin/v1/freelance-quality';

    private Country $saudi;

    private User $mona;

    private User $omar;

    private Freelancer $sara;

    private FreelanceProject $open;

    private int $projects = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'rights', 'skill_tests'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->omar = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->open = $this->project($this->mona, FreelanceProjectStatus::Published);
    }

    // -------------------------------------------------------------- helpers

    private function as(Freelancer|User|Admin $who): void
    {
        $this->app['auth']->forgetGuards();
        $guard = match (true) {
            $who instanceof Freelancer => 'freelance_api',
            $who instanceof User => 'user_api',
            default => 'admin_api',
        };
        Sanctum::actingAs($who->refresh(), [], $guard);
    }

    private function staff(string ...$permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->as($admin);

        return $admin;
    }

    private function project(User $client, FreelanceProjectStatus $status): FreelanceProject
    {
        $this->projects++;

        return FreelanceProject::query()->create([
            'client_id' => $client->id, 'country_id' => $this->saudi->id, 'title' => "Project {$this->projects}", 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 30, 'visibility' => 'public', 'status' => $status,
            'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    /** A project of Sara's that ended (completed, or cancelled), with `parts` approved parts — `late` of them delivered after their days. */
    private function finished(User $client, string $status = FreelanceAgreement::COMPLETED, int $parts = 1, int $late = 0, bool $allowed = true): FreelanceAgreement
    {
        $p = $this->project($client, FreelanceProjectStatus::Closed);
        $o = FreelanceOffer::query()->create(['project_id' => $p->id, 'freelancer_id' => $this->sara->id, 'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'details' => 'Done.', 'status' => 'accepted']);
        $a = FreelanceAgreement::query()->create([
            'project_id' => $p->id, 'offer_id' => $o->id, 'client_id' => $client->id, 'freelancer_id' => $this->sara->id, 'scope' => 'A logo.',
            'price_minor' => 30000, 'currency_code' => 'SAR', 'duration_days' => 5, 'policy' => [], 'money' => [], 'status' => $status, 'activated_at' => now()->subDays(20),
            'completed_at' => $status === FreelanceAgreement::COMPLETED ? now()->subDays(2) : null,
            'rights' => ['model' => 'transfer', 'commercial' => true, 'exclusive' => true, 'term_months' => null, 'territory' => 'worldwide', 'portfolio' => true,
                'text' => ['id' => null, 'version' => 0, 'title' => ['ar' => 'ع', 'en' => 'T'], 'body' => ['ar' => 'ن', 'en' => 'B']]],
            'portfolio_allowed' => $allowed,
        ]);
        for ($i = 1; $i <= $parts; $i++) {
            FreelanceMilestone::query()->create([
                'agreement_id' => $a->id, 'project_id' => $p->id, 'title' => "Part {$i}", 'amount_minor' => 10000, 'position' => $i, 'status' => 'approved',
                'duration_days' => 5, 'started_at' => now()->subDays(20), 'delivered_at' => now()->subDays($i <= $late ? 10 : 17),
            ]);
        }

        return $a;
    }

    /** Sara offers on Mona's open project, so Mona may read her profile; returns the profile URL. */
    private function profileUrl(): string
    {
        $this->as($this->sara);
        $uuid = $this->postJson(self::API."/projects/{$this->open->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.'])
            ->assertCreated()->json('data.uuid');

        return self::CLIENT."/projects/{$this->open->uuid}/offers/{$uuid}/freelancer";
    }

    private function skillWithTest(bool $challenge = true): FreelanceSkill
    {
        $skill = FreelanceSkill::query()->create(['status' => FreelanceSkill::ACTIVE, 'slug' => 'react-'.uniqid()]);
        $skill->translations()->createMany([['locale' => 'en', 'name' => 'React', 'normalized' => 'react'.$skill->id], ['locale' => 'ar', 'name' => 'رياكت', 'normalized' => 'رياكت'.$skill->id]]);
        DB::table('freelancer_skills')->insert(['freelancer_id' => $this->sara->id, 'freelance_skill_id' => $skill->id]);
        FreelanceSkillTest::query()->create([
            'freelance_skill_id' => $skill->id, 'status' => true, 'questions_count' => 4, 'minutes' => 15, 'pass_percent' => 50, 'intermediate_percent' => 75, 'retake_hours' => 24,
            'challenge_enabled' => $challenge, 'challenge_brief' => ['ar' => 'ابني صفحة حجز', 'en' => 'Build a booking page'],
        ]);
        for ($i = 0; $i < 4; $i++) {
            FreelanceSkillQuestion::query()->create(['freelance_skill_id' => $skill->id, 'prompt' => ['ar' => "س {$i}", 'en' => "Q {$i}"], 'options' => [
                ['text' => ['ar' => 'صح', 'en' => 'Right'], 'correct' => true], ['text' => ['ar' => 'غلط', 'en' => 'Wrong'], 'correct' => false],
            ], 'status' => true]);
        }

        return $skill;
    }

    /** Sara sits the test, answering `$right` of the 4 questions right. */
    private function sitTest(FreelanceSkill $skill, int $right): array
    {
        $this->as($this->sara);
        $attempt = $this->postJson(self::API."/skill-tests/{$skill->id}/start")->assertOk()->json('data');
        $answers = [];
        foreach ($attempt['questions'] as $i => $q) {
            $order = json_decode((string) DB::table('freelance_skill_attempts')->where('id', $attempt['attempt_id'])->value('questions'), true)[$i]['options'];
            $rightAt = array_search(0, $order, true);
            $answers[$q['id']] = $i < $right ? $rightAt : 1 - $rightAt;
        }

        return $this->postJson(self::API."/skill-test-attempts/{$attempt['attempt_id']}/submit", ['answers' => $answers])->assertOk()->json('data');
    }

    // -------------------------------------------------------------- FS-601 verified works

    public function test_a_work_from_a_finished_project_the_client_allows_is_verified_and_tells_its_story(): void
    {
        $done = $this->finished($this->omar);
        $this->as($this->sara);
        $this->postJson(self::API.'/profile/portfolio', ['title' => 'Made outside', 'visibility' => 'public', 'rights_confirmed' => true])->assertCreated()->assertJsonPath('data.verified', false);
        $res = $this->postJson(self::API.'/profile/portfolio', [
            'title' => 'Clinic booking', 'visibility' => 'public', 'rights_confirmed' => true, 'agreement_uuid' => $done->uuid,
            'problem' => '  Patients <b>called</b> to book.  ', 'solution' => 'An online booking page.', 'role' => 'Design and build', 'tools' => 'Laravel, Vue', 'results' => '',
        ])->assertCreated()->assertJsonPath('data.verified', true)->assertJsonPath('data.case_study.problem', 'Patients called to book.')
            ->assertJsonPath('data.case_study.tools', 'Laravel, Vue')->assertJsonMissingPath('data.case_study.results');
        $this->assertSame(now()->subDays(2)->format('Y-m'), $res->json('data.verified_on'));

        // The client sees it verified, first, and never whose project it was.
        $url = $this->profileUrl();
        $this->as($this->mona);
        $profile = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame(['Clinic booking', 'Made outside'], array_column($profile['portfolio'], 'title'));
        $this->assertTrue($profile['portfolio'][0]['verified']);
        $this->assertStringNotContainsString('Omar', json_encode($profile['portfolio']));

        // The client taking the leave back takes the work off everyone's view.
        $done->update(['portfolio_allowed' => false]);
        $this->assertSame(['Made outside'], array_column($this->getJson($url)->json('data.portfolio'), 'title'));
    }

    public function test_staff_take_the_badge_back_with_a_reason_and_give_it_again(): void
    {
        $done = $this->finished($this->omar);
        $this->as($this->sara);
        $id = $this->postJson(self::API.'/profile/portfolio', ['title' => 'Clinic booking', 'visibility' => 'public', 'rights_confirmed' => true, 'agreement_uuid' => $done->uuid])->json('data.id');

        $this->staff('freelance-quality.view');
        $this->getJson(self::ADMIN.'/verified-works')->assertOk()->assertJsonPath('data.0.title', 'Clinic booking')->assertJsonPath('pagination.counts.verified', 1);
        $this->postJson(self::ADMIN."/verified-works/{$id}/unverify", ['reason' => 'The images are not from this project.'])->assertForbidden();

        $this->staff('freelance-quality.view', 'freelance-quality.update');
        $this->postJson(self::ADMIN."/verified-works/{$id}/unverify", ['reason' => 'The images are not from this project.'])->assertOk()->assertJsonPath('data.verified', false);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.portfolio.unverified')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'freelance.portfolio.unverified')->count());
        $this->getJson(self::ADMIN.'/verified-works?status=revoked')->assertJsonPath('data.0.revoked_reason', 'The images are not from this project.');

        $this->as($this->sara);
        $this->getJson(self::API.'/profile/portfolio')->assertJsonPath('data.0.verified', false)->assertJsonPath('data.0.verified_revoked_reason', 'The images are not from this project.');

        $this->staff('freelance-quality.view', 'freelance-quality.update');
        $this->postJson(self::ADMIN."/verified-works/{$id}/reverify")->assertOk()->assertJsonPath('data.verified', true);
    }

    // -------------------------------------------------------------- FS-602 levels and the challenge

    public function test_the_test_score_gives_beginner_or_intermediate(): void
    {
        $skill = $this->skillWithTest();
        $this->assertSame('beginner', $this->sitTest($skill, 2)['badge']['level']);

        FreelancerSkillBadge::query()->delete();
        $other = $this->skillWithTest();
        $this->assertSame('intermediate', $this->sitTest($other, 3)['badge']['level']);
        $this->getJson(self::API.'/skill-tests')->assertOk()->assertJsonPath('data.badges.0.level', 'intermediate');
    }

    public function test_the_practical_challenge_raises_the_level_and_staff_decide_it(): void
    {
        $skill = $this->skillWithTest();
        $this->sitTest($skill, 2); // beginner: no challenge yet
        $this->getJson(self::API.'/skill-tests')->assertJsonPath('data.tests.0.challenge.next_level', null);
        $body = ['target_level' => 'advanced', 'link' => 'https://github.com/sara/booking', 'note' => 'A booking page with reminders, tests and a short video.'];
        $this->postJson(self::API."/skill-tests/{$skill->id}/challenge", $body)->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_challenge_level');

        FreelancerSkillBadge::query()->update(['level' => 'intermediate']);
        $this->getJson(self::API.'/skill-tests')->assertJsonPath('data.tests.0.challenge.next_level', 'advanced')->assertJsonPath('data.tests.0.challenge.brief', 'Build a booking page')
            ->assertJsonPath('data.tests.0.challenge.can_send', true);
        $this->postJson(self::API."/skill-tests/{$skill->id}/challenge", ['target_level' => 'expert'] + $body)->assertUnprocessable();
        $uuid = $this->postJson(self::API."/skill-tests/{$skill->id}/challenge", $body)->assertCreated()->json('data.challenge.uuid');
        $this->postJson(self::API."/skill-tests/{$skill->id}/challenge", $body)->assertStatus(409)->assertJsonPath('error_code', 'freelance_skill_challenge_open');

        // Staff turn it down - with a note - and the next one waits.
        $this->staff('freelance-quality.view', 'freelance-quality.update');
        $this->getJson(self::ADMIN.'/challenges')->assertOk()->assertJsonPath('data.0.uuid', $uuid)->assertJsonPath('data.0.brief', 'Build a booking page')->assertJsonPath('pagination.counts.submitted', 1);
        $this->postJson(self::ADMIN."/challenges/{$uuid}/decide", ['decision' => 'reject'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_challenge_note_required');
        $this->postJson(self::ADMIN."/challenges/{$uuid}/decide", ['decision' => 'reject', 'note' => 'No tests in the repository.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson(self::ADMIN."/challenges/{$uuid}/decide", ['decision' => 'accept'])->assertStatus(409);
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.challenge.rejected')->count());

        $this->as($this->sara);
        $this->postJson(self::API."/skill-tests/{$skill->id}/challenge", $body)->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_challenge_wait');

        // Two weeks on, a new one is accepted at advanced (never above what was asked).
        $this->travel(15)->days();
        $uuid = $this->postJson(self::API."/skill-tests/{$skill->id}/challenge", $body)->assertCreated()->json('data.challenge.uuid');
        $this->staff('freelance-quality.update', 'freelance-quality.view');
        $this->postJson(self::ADMIN."/challenges/{$uuid}/decide", ['decision' => 'accept', 'level' => 'expert'])->assertUnprocessable();
        $this->postJson(self::ADMIN."/challenges/{$uuid}/decide", ['decision' => 'accept', 'note' => 'Clean work.'])->assertOk()->assertJsonPath('data.granted_level', 'advanced');
        $this->assertSame('advanced', FreelancerSkillBadge::query()->value('level'));
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.challenge.accepted')->count());

        $this->as($this->sara);
        $this->getJson(self::API.'/skill-tests')->assertJsonPath('data.tests.0.badge.level', 'advanced')->assertJsonPath('data.tests.0.challenge.next_level', 'expert');
    }

    public function test_a_renewal_keeps_the_level_a_challenge_earned(): void
    {
        $skill = $this->skillWithTest();
        $this->sitTest($skill, 4);
        FreelancerSkillBadge::query()->update(['level' => 'advanced', 'expires_at' => now()->addDays(10)]);
        $this->getJson(self::API.'/skill-tests')->assertJsonPath('data.tests.0.renewable', true)->assertJsonPath('data.tests.0.can_start', true);
        $this->assertSame('advanced', $this->sitTest($skill, 3)['badge']['level']);
        $this->assertTrue(FreelancerSkillBadge::query()->first()->expires_at->gt(now()->addDays(300)));
    }

    // -------------------------------------------------------------- FS-604 reputation

    public function test_the_reputation_figures_say_where_they_come_from_and_a_project_can_be_corrected(): void
    {
        // Three projects for Omar (one late), one cancelled by Sara's fault, one cancelled by the client, one more for Mona.
        $late = $this->finished($this->omar, parts: 2, late: 1);
        $this->finished($this->omar);
        $this->finished($this->mona);
        $mine = $this->finished($this->omar, FreelanceAgreement::CANCELLED, parts: 0);
        DB::table('freelance_cancellation_reasons')->insert(['project_id' => $mine->project_id, 'agreement_id' => $mine->id, 'actor_type' => 'freelancer', 'actor_id' => $this->sara->id, 'reason_code' => 'other', 'responsibility' => 'freelancer', 'created_at' => now()]);
        $theirs = $this->finished($this->mona, FreelanceAgreement::CANCELLED, parts: 0);
        DB::table('freelance_cancellation_reasons')->insert(['project_id' => $theirs->project_id, 'agreement_id' => $theirs->id, 'actor_type' => 'client', 'actor_id' => $this->mona->id, 'reason_code' => 'other', 'responsibility' => 'client', 'created_at' => now()]);

        $this->as($this->sara);
        $rep = collect($this->getJson(self::API.'/reputation')->assertOk()->json('data.indicators'))->keyBy('key');
        $this->assertSame(75, $rep['delivery']['value'], '3 of 4 parts on time');
        $this->assertSame(['kind' => 'parts', 'count' => 4, 'hits' => 3, 'excluded' => 0], $rep['delivery']['source']);
        $this->assertSame(75, $rep['completion']['value'], '3 finished of 4 counted - the client\'s own end is left out');
        $this->assertSame(1, $rep['completion']['source']['excluded']);
        $this->assertNull($rep['repeat_clients']['value'], 'two clients are too few');
        $this->assertSame('new', $rep['quality']['level']);
        $this->assertNull($rep['technical']['value']);

        // Sara asks to correct the late project; staff accept, and it leaves the figure.
        $this->postJson(self::API.'/reputation/corrections', ['agreement' => $late->uuid, 'indicator' => 'delivery', 'reason' => 'client_delay', 'note' => 'The client sent the content a week late.'])
            ->assertCreated()->assertJsonPath('data.corrections.0.status', 'open');
        $this->postJson(self::API.'/reputation/corrections', ['agreement' => $late->uuid, 'indicator' => 'delivery', 'reason' => 'client_delay', 'note' => 'The client sent the content a week late.'])->assertStatus(409);
        $this->postJson(self::API.'/reputation/corrections', ['agreement' => $theirs->uuid, 'indicator' => 'completion', 'reason' => 'other', 'note' => 'Not counted anyway, nothing to correct.'])->assertUnprocessable();

        $this->staff('freelance-quality.view', 'freelance-quality.update');
        $uuid = $this->getJson(self::ADMIN.'/corrections')->assertOk()->assertJsonPath('pagination.counts.open', 1)->json('data.0.uuid');
        $this->postJson(self::ADMIN."/corrections/{$uuid}/decide", ['decision' => 'accept'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.reputation.corrected')->count());

        $this->as($this->sara);
        $rep = collect($this->getJson(self::API.'/reputation')->json('data.indicators'))->keyBy('key');
        // Two parts are left: too few for a figure, so it says «new» until there is more work.
        $this->assertNull($rep['delivery']['value']);
        $this->assertSame(['kind' => 'parts', 'count' => 2, 'hits' => 2, 'excluded' => 1], $rep['delivery']['source']);
        $this->finished($this->mona);
        $this->assertSame(100, collect($this->getJson(self::API.'/reputation')->json('data.indicators'))->keyBy('key')['delivery']['value']);

        // The client reads the figures (never the projects behind them).
        $url = $this->profileUrl();
        $this->as($this->mona);
        $client = $this->getJson($url)->assertOk()->json('data.reputation');
        $this->assertCount(6, $client['indicators']);
        $this->assertArrayNotHasKey('projects', $client['indicators'][1]);
    }

    public function test_technical_quality_comes_from_the_levels_held(): void
    {
        $skill = $this->skillWithTest();
        $this->sitTest($skill, 4);
        FreelancerSkillBadge::query()->update(['level' => 'expert']);
        $rep = collect($this->getJson(self::API.'/reputation')->json('data.indicators'))->keyBy('key');
        $this->assertSame(100, $rep['technical']['value']);
        $this->assertSame([['name' => 'React', 'level' => 'expert']], $rep['technical']['source']['best']);
    }

    public function test_the_quality_desk_needs_its_permission(): void
    {
        $this->staff();
        $this->getJson(self::ADMIN.'/summary')->assertForbidden();
        $this->staff('freelance-quality.view');
        $this->getJson(self::ADMIN.'/summary')->assertOk()->assertJsonPath('data.challenges_waiting', 0);
    }
}
