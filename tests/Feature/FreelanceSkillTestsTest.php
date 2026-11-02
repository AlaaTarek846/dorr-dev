<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSkillAttempt;
use Modules\Freelance\Models\FreelanceSkillQuestion;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerSkillBadge;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L08: a skill's test — the admin writes the questions and sets it, a freelancer with that skill takes it, the server marks it and a pass
 * earns a verified badge that clients see and that breaks a tie in the «recommended» order. The right answers never reach the app.
 */
class FreelanceSkillTestsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private const ADMIN = '/api/admin/v1';

    private const CLIENT = '/api/mobile/v1/freelance';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private FreelanceSkill $php;

    private Freelancer $sara;

    private Freelancer $omar;

    private User $mona;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'services', 'skill_tests'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->omar = Freelancer::query()->create(['name' => 'Omar', 'phone' => '+966500000092', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);

        $this->php = $this->skill('php', 'PHP', 'بي إتش بي');
        $this->giveSkill($this->sara, $this->php);
        $this->giveSkill($this->omar, $this->php);
    }

    // -------------------------------------------------------------- helpers

    private function skill(string $slug, string $en, string $ar): FreelanceSkill
    {
        $s = FreelanceSkill::query()->create(['slug' => $slug, 'status' => FreelanceSkill::ACTIVE]);
        $s->translations()->createMany([['locale' => 'en', 'name' => $en, 'normalized' => mb_strtolower($en)], ['locale' => 'ar', 'name' => $ar, 'normalized' => $ar]]);

        return $s;
    }

    private function giveSkill(Freelancer $f, FreelanceSkill $s): void
    {
        DB::table('freelancer_skills')->insert(['freelancer_id' => $f->id, 'freelance_skill_id' => $s->id, 'level' => 'intermediate', 'created_at' => now()]);
    }

    private function asFreelancer(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function asClient(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u->refresh(), [], 'user_api');
    }

    private function staff(string ...$permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /** Four questions for the skill, the right answer being the option "yes" in each; the test on with `questions_count` = 4 and the given pass mark. */
    private function bank(FreelanceSkill $skill, int $pass = 75, bool $on = true): void
    {
        for ($i = 1; $i <= 4; $i++) {
            FreelanceSkillQuestion::query()->create([
                'freelance_skill_id' => $skill->id, 'sort_order' => $i,
                'prompt' => ['ar' => "سؤال $i", 'en' => "Question $i"],
                'options' => [
                    ['text' => ['ar' => 'لا', 'en' => 'no'], 'correct' => false],
                    ['text' => ['ar' => 'نعم', 'en' => 'yes'], 'correct' => true],
                    ['text' => ['ar' => 'ربما', 'en' => 'maybe'], 'correct' => false],
                ],
            ]);
        }
        \Modules\Freelance\Models\FreelanceSkillTest::query()->create(['freelance_skill_id' => $skill->id, 'status' => $on, 'questions_count' => 4, 'minutes' => 10, 'pass_percent' => $pass, 'retake_hours' => 24]);
    }

    /** Start the test and answer every question "yes" (right) or "no" (wrong), as the app would, by the position shown. */
    private function take(Freelancer $f, bool $right, int $wrongCount = 0): array
    {
        $this->asFreelancer($f);
        $start = $this->postJson(self::API."/skill-tests/{$this->php->id}/start", [], ['Accept-Language' => 'en'])->assertOk()->json('data');
        $answers = [];
        foreach ($start['questions'] as $n => $q) {
            $want = $right && $n >= $wrongCount ? 'yes' : 'no';
            $answers[$q['id']] = array_search($want, $q['options'], true);
        }

        return $this->postJson(self::API."/skill-test-attempts/{$start['attempt_id']}/submit", ['answers' => $answers])->assertOk()->json('data');
    }

    // -------------------------------------------------------------- the admin

    public function test_the_admin_builds_a_test_and_it_needs_enough_questions_to_go_on(): void
    {
        $this->staff('freelance-catalogs.view', 'freelance-catalogs.create', 'freelance-catalogs.update', 'freelance-catalogs.delete');
        $question = fn (string $a) => [
            'prompt' => ['ar' => 'ما هو '.$a, 'en' => 'What is '.$a],
            'options' => [['text' => ['ar' => 'أ', 'en' => 'a'], 'correct' => true], ['text' => ['ar' => 'ب', 'en' => 'b'], 'correct' => false]],
        ];

        $this->putJson(self::ADMIN."/freelance-skill-tests/{$this->php->id}", ['status' => true])->assertStatus(422);
        foreach (['x', 'y', 'z'] as $x) {
            $this->postJson(self::ADMIN."/freelance-skill-tests/{$this->php->id}/questions", $question($x))->assertCreated();
        }
        $this->putJson(self::ADMIN."/freelance-skill-tests/{$this->php->id}", ['status' => true, 'pass_percent' => 80])->assertOk()->assertJsonPath('data.test.status', true)->assertJsonPath('data.test.pass_percent', 80);

        $detail = $this->getJson(self::ADMIN."/freelance-skill-tests/{$this->php->id}")->assertOk()->json('data');
        $this->assertCount(3, $detail['questions']);
        $this->assertTrue($detail['questions'][0]['options'][0]['correct'], 'the admin sees the right answer');

        $bad = $question('w');
        $bad['options'][1]['correct'] = true;
        $this->postJson(self::ADMIN."/freelance-skill-tests/{$this->php->id}/questions", $bad)->assertStatus(422);

        // Deleting one of the three leaves too few, so the test goes off by itself.
        $this->deleteJson(self::ADMIN.'/freelance-skill-questions/'.$detail['questions'][0]['id'])->assertOk();
        $this->getJson(self::ADMIN."/freelance-skill-tests/{$this->php->id}")->assertJsonPath('data.test.status', false);
    }

    // -------------------------------------------------------------- the freelancer

    public function test_the_questions_sent_never_carry_the_answer(): void
    {
        $this->bank($this->php);
        $this->asFreelancer($this->sara);

        $start = $this->postJson(self::API."/skill-tests/{$this->php->id}/start", [], ['Accept-Language' => 'en'])->assertOk()->json('data');

        $this->assertCount(4, $start['questions']);
        $this->assertEqualsCanonicalizing(['yes', 'maybe', 'no'], $start['questions'][0]['options']);
        $this->assertStringNotContainsString('correct', json_encode($start));
        $this->assertGreaterThan(590, $start['seconds_left']);
        $this->assertLessThanOrEqual(600, $start['seconds_left']);
        $this->assertSame($start['attempt_id'], $this->postJson(self::API."/skill-tests/{$this->php->id}/start")->json('data.attempt_id'), 'asking again resumes the same attempt');
    }

    public function test_a_pass_earns_the_badge_and_a_fail_waits_before_the_next_go(): void
    {
        $this->bank($this->php, 75);

        $fail = $this->take($this->sara, true, 2);
        $this->assertFalse($fail['passed']);
        $this->assertSame(50, $fail['score_percent']);
        $this->assertNotNull($fail['retake_at']);
        $this->assertSame(0, FreelancerSkillBadge::query()->count());

        $this->postJson(self::API."/skill-tests/{$this->php->id}/start")->assertStatus(422)->assertJsonPath('error_code', 'freelance_skill_test_wait');
        $overview = $this->getJson(self::API.'/skill-tests')->assertOk()->json('data');
        $this->assertFalse($overview['tests'][0]['can_start']);

        FreelanceSkillAttempt::query()->update(['submitted_at' => now()->subHours(25)]);
        $pass = $this->take($this->sara, true);
        $this->assertTrue($pass['passed']);
        $this->assertSame(100, $pass['score_percent']);
        $this->assertNotNull($pass['badge']['expires_at']);
        $this->assertSame(1, FreelancerSkillBadge::query()->where('freelancer_id', $this->sara->id)->count());

        $this->postJson(self::API."/skill-tests/{$this->php->id}/start")->assertStatus(422)->assertJsonPath('error_code', 'freelance_skill_test_held');
        $this->assertSame($this->php->id, $this->getJson(self::API.'/skill-tests')->json('data.badges.0.skill_id'));
    }

    public function test_submitting_twice_gives_the_first_result_and_a_late_one_is_not_taken(): void
    {
        $this->bank($this->php);
        $this->asFreelancer($this->sara);
        $start = $this->postJson(self::API."/skill-tests/{$this->php->id}/start")->json('data');
        $answers = [];

        $first = $this->postJson(self::API."/skill-test-attempts/{$start['attempt_id']}/submit", ['answers' => $answers])->assertOk()->json('data');
        $second = $this->postJson(self::API."/skill-test-attempts/{$start['attempt_id']}/submit", ['answers' => $answers])->assertOk()->json('data');
        $this->assertSame($first['attempt_id'], $second['attempt_id']);
        $this->assertSame($first['status'], $second['status']);

        FreelanceSkillAttempt::query()->update(['submitted_at' => now()->subHours(30)]);
        $this->postJson(self::API."/skill-tests/{$this->php->id}/start")->assertOk();
        FreelanceSkillAttempt::query()->where('status', 'started')->update(['expires_at' => now()->subMinutes(5)]);
        $late = $this->postJson(self::API.'/skill-test-attempts/'.FreelanceSkillAttempt::query()->where('status', 'started')->value('id').'/submit', ['answers' => []])->assertOk()->json('data');
        $this->assertSame('expired', $late['status']);
        $this->assertFalse($late['passed']);
    }

    public function test_only_a_skill_on_the_profile_can_be_tested_and_the_country_switch_applies(): void
    {
        $go = $this->skill('go', 'Go', 'جو');
        $this->bank($go);
        $this->asFreelancer($this->sara);
        $this->postJson(self::API."/skill-tests/{$go->id}/start")->assertStatus(422)->assertJsonPath('error_code', 'freelance_skill_test_not_yours');
        $this->assertSame([], $this->getJson(self::API.'/skill-tests')->json('data.tests'), 'a skill that is not on my profile is not offered');

        $this->giveSkill($this->sara, $go);
        FreelanceFeatureRule::query()->where('feature', 'skill_tests')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->postJson(self::API."/skill-tests/{$go->id}/start")->assertStatus(403)->assertJsonPath('error_code', 'freelance_skill_test_closed');
        $this->assertFalse($this->getJson(self::API.'/skill-tests')->json('data.enabled'));
    }

    public function test_a_test_that_is_off_or_has_a_thin_bank_is_not_offered(): void
    {
        $this->bank($this->php, 70, false);
        $this->asFreelancer($this->sara);
        $this->assertSame([], $this->getJson(self::API.'/skill-tests')->json('data.tests'));
        $this->postJson(self::API."/skill-tests/{$this->php->id}/start")->assertStatus(404);
    }

    // -------------------------------------------------------------- the badge

    public function test_clients_see_the_badge_and_dropping_the_skill_takes_it_off(): void
    {
        $this->bank($this->php);
        $this->assertTrue($this->take($this->sara, true)['passed']);

        $card = $this->published($this->sara);
        $this->assertSame(['PHP'], array_column($card['freelancer']['skill_badges'], 'name'));
        $this->assertArrayNotHasKey('correct', $card['freelancer']['skill_badges'][0]);

        DB::table('freelancer_skills')->where('freelancer_id', $this->sara->id)->delete();
        $this->assertSame([], $this->published($this->sara)['freelancer']['skill_badges']);
    }

    public function test_the_badge_breaks_a_tie_in_the_recommended_order_and_an_expired_or_revoked_one_does_not(): void
    {
        $this->bank($this->php);
        $sarasService = $this->publishAs($this->sara, 'Sara logo');
        $omarsService = $this->publishAs($this->omar, 'Omar logo');
        $this->assertTrue($this->take($this->sara, true)['passed']);

        $this->asClient($this->mona);
        $order = fn () => collect($this->getJson(self::CLIENT.'/services?sort=recommended')->assertOk()->json('data'))->pluck('uuid')->all();
        $this->assertSame([$sarasService, $omarsService], $order(), 'the verified skill comes first');

        FreelancerSkillBadge::query()->update(['expires_at' => now()->subDay()]);
        $this->assertSame([$omarsService, $sarasService], $order(), 'an ended badge counts for nothing (newest first)');

        FreelancerSkillBadge::query()->update(['expires_at' => now()->addDay()]);
        $badge = FreelancerSkillBadge::query()->firstOrFail();
        $this->staff('freelance-catalogs.view', 'freelance-catalogs.update');
        $this->postJson(self::ADMIN."/freelance-skill-badges/{$badge->id}/revoke", ['reason' => 'cheating'])->assertOk();
        $this->asClient($this->mona);
        $this->assertSame([$omarsService, $sarasService], $order());
        $this->assertNotNull($badge->refresh()->revoked_at);
    }

    public function test_a_revoked_badge_lets_the_freelancer_sit_the_test_again_at_once(): void
    {
        $this->bank($this->php);
        $this->assertTrue($this->take($this->sara, true)['passed']);
        $badge = FreelancerSkillBadge::query()->firstOrFail();

        $this->staff('freelance-catalogs.view', 'freelance-catalogs.update');
        $this->postJson(self::ADMIN."/freelance-skill-badges/{$badge->id}/revoke", [])->assertOk();
        $list = $this->getJson(self::ADMIN.'/freelance-skill-badges?state=all')->assertOk()->json('data');
        $this->assertFalse($list[0]['is_valid']);

        $this->asFreelancer($this->sara);
        $this->postJson(self::API."/skill-tests/{$this->php->id}/start")->assertOk();
    }

    // -------------------------------------------------------------- more helpers

    /** The first card of the service catalog for the freelancer's service, read as the client. */
    private function published(Freelancer $f): array
    {
        $uuid = $this->publishAs($f, $f->name.' service');
        $this->asClient($this->mona);

        return collect($this->getJson(self::CLIENT.'/services')->assertOk()->json('data'))->firstWhere('uuid', $uuid);
    }

    private function publishAs(Freelancer $f, string $title): string
    {
        $this->asFreelancer($f);
        $uuid = $this->postJson(self::API.'/services', [
            'title' => $title,
            'description' => 'I draw a clean logo that fits your shop, with the colours and the files you need for print and the web.',
            'specialty_id' => $this->web->id,
            'tiers' => [['level' => 'basic', 'name' => 'Starter', 'price_minor' => 20000, 'delivery_days' => 3, 'revisions' => 1, 'features' => ['One idea', 'PNG']]],
        ])->assertCreated()->json('data.uuid');
        $this->postJson(self::API."/services/{$uuid}/submit")->assertOk();
        $this->staff('freelance-services.view', 'freelance-services.update');
        $this->postJson("/api/admin/v1/freelance-services/{$uuid}/approve")->assertOk();
        FreelanceService::query()->where('uuid', $uuid)->firstOrFail();

        return $uuid;
    }
}
