<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectInvitation;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Ai\FreelanceAi;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-401 / FS-501 (light): DORR AI for the freelance client — the project written from an idea (AI Project Builder) and the best
 * freelancers for a project with their reasons (Smart Matching). Both on the client's DORR AI plan, each with its country switch.
 */
class FreelanceAiClientTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $client;

    private FreelanceSpecialty $web;

    private FreelanceSkill $laravel;

    private FreelanceSkill $vue;

    /** What DORR AI answers next (JSON text). */
    private string $aiAnswer = '{}';

    /** @var list<string> */
    private array $aiPrompts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => function (HttpRequest $request) {
                $this->aiPrompts[] = (string) json_encode($request->data()['messages'] ?? [], JSON_UNESCAPED_UNICODE);

                return Http::response(['choices' => [['message' => ['content' => $this->aiAnswer]]]]);
            },
            '*' => Http::response([], 200),
        ]);

        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        $this->switches(['freelance', 'projects', 'invitations']);

        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web development'], ['locale' => 'ar', 'name' => 'تطوير مواقع']]);
        $this->laravel = $this->skill('laravel', 'Laravel');
        $this->vue = $this->skill('vue', 'Vue.js');

        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        Sanctum::actingAs($this->client, [], 'user_api');
    }

    public function test_the_buttons_follow_the_country_switches_and_the_plan(): void
    {
        $this->getJson('/api/mobile/v1/freelance/projects/ai/status')->assertOk()
            ->assertJsonPath('data.builder', false)->assertJsonPath('data.matching', false)
            ->assertJsonPath('data.allowed', false)->assertJsonPath('data.reason', 'no_plan');
        $this->postJson('/api/mobile/v1/freelance/projects/ai/build', ['idea' => 'I want an online store for my perfumes'])
            ->assertStatus(403)->assertJsonPath('error_code', 'freelance_feature_off');

        $this->switches(['ai_project_builder', 'smart_matching']);
        $this->getJson('/api/mobile/v1/freelance/projects/ai/status')->assertJsonPath('data.builder', true)->assertJsonPath('data.matching', true);

        // A DORR AI tool: nothing without a plan, and no call to the model.
        $this->postJson('/api/mobile/v1/freelance/projects/ai/build', ['idea' => 'I want an online store for my perfumes'])
            ->assertStatus(402)->assertJsonPath('error_code', 'freelance_ai_plan_no_plan')->assertJsonPath('data.reason', 'no_plan');
        $this->assertSame([], $this->aiPrompts);

        $this->trialPlan();
        $this->getJson('/api/mobile/v1/freelance/projects/ai/status')->assertJsonPath('data.allowed', true);
    }

    public function test_the_idea_becomes_a_professional_project_draft_from_the_catalog_and_inside_the_limits(): void
    {
        $this->switches(['ai_project_builder']);
        $this->trialPlan();
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['project.min_budget_minor' => 50000, 'project.max_budget_minor' => 1000000]);
        FreelancePolicies::forget();

        $this->aiAnswer = json_encode([
            'title' => 'Booking website for a dental clinic',
            'summary' => 'A website where patients book appointments online.',
            'goal' => 'Fewer phone calls and a full calendar.',
            'audience' => 'Patients of the clinic',
            'features' => ['Booking calendar', 'Doctor profiles'],
            'deliverables' => ['Source code', 'Admin panel'],
            'acceptance' => ['A patient books in under a minute'],
            'out_of_scope' => ['Mobile apps'],
            'assumptions' => ['The clinic has a domain'],
            'specialty_id' => $this->web->id,
            'skills' => ['laravel', 'Vue', 'Quantum Typing'],
            'budget' => ['type' => 'range', 'min' => 100, 'max' => 900],
            'duration_days' => 900,
            'freelancer_questions' => ['Show a similar site you built'],
            'client_questions' => ['Do you need online payment?'],
        ]);

        $draft = $this->postJson('/api/mobile/v1/freelance/projects/ai/build', [
            'idea' => 'عايز موقع لعيادة أسنان المرضى يحجزوا فيه، كلموني على 0501234567 أو mona@example.com',
        ])->assertOk()
            ->assertJsonPath('data.draft.title', 'Booking website for a dental clinic')
            ->assertJsonPath('data.draft.specialty.id', $this->web->id)
            ->assertJsonPath('data.draft.visibility', 'public')
            ->assertJsonPath('data.draft.questions.0', 'Show a similar site you built')
            ->assertJsonPath('data.questions.0', 'Do you need online payment?')
            ->assertJsonPath('data.brief.deliverables.1', 'Admin panel')
            ->json('data.draft');

        // Only skills DORR has; a name it lacks is left out, never invented.
        $this->assertEqualsCanonicalizing([$this->laravel->id, $this->vue->id], array_column($draft['skills'], 'id'));
        // The estimate stays inside the country's limits and the longest duration.
        $this->assertSame('range', $draft['budget']['type']);
        $this->assertSame(50000, $draft['budget']['min_minor']);
        $this->assertSame(90000, $draft['budget']['max_minor']);
        $this->assertSame((int) config('freelance.projects.max_duration_days', 365), $draft['duration_days']);
        // The brief is in the description, under headings.
        $this->assertStringContainsString("Deliverables:\n• Source code", $draft['description']);
        $this->assertStringContainsString('Out of scope', $draft['description']);
        // No phone number or e-mail reached the model.
        $this->assertStringNotContainsString('0501234567', $this->aiPrompts[0]);
        $this->assertStringNotContainsString('mona@example.com', $this->aiPrompts[0]);

        // The answers to its questions go back with the idea.
        $this->postJson('/api/mobile/v1/freelance/projects/ai/build', [
            'idea' => 'A booking website for my dental clinic',
            'answers' => [['question' => 'Do you need online payment?', 'answer' => 'Yes, by card']],
        ])->assertOk();
        $this->assertStringContainsString('Yes, by card', $this->aiPrompts[1]);

        // The draft is saved through the usual form — nothing was saved by the builder itself.
        $this->assertSame(0, FreelanceProject::query()->count());
    }

    public function test_the_best_people_for_a_project_come_with_their_reasons_and_the_ai_words(): void
    {
        $this->switches(['smart_matching']);
        $project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'private', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $project->skills()->sync([$this->laravel->id, $this->vue->id]);

        $sara = $this->freelancer('+966500000091', 'Sara');          // the specialty and both skills, active today
        $sara->specialties()->attach($this->web->id);
        $sara->skills()->attach([$this->laravel->id => ['level' => 'expert'], $this->vue->id => ['level' => 'expert']]);
        $sara->forceFill(['last_active_at' => now()])->save();
        $noor = $this->freelancer('+966500000093', 'Noor');          // one skill, no specialty
        $noor->skills()->attach([$this->vue->id => ['level' => 'intermediate']]);
        $this->freelancer('+966500000094', 'Omar')->specialties();     // nothing in common: not a candidate
        $this->freelancer('+966500000095', 'Hidden', 'verification_submitted')->specialties()->attach($this->web->id); // not verified

        $url = "/api/mobile/v1/freelance/projects/{$project->uuid}/matches";
        $this->getJson($url)->assertStatus(402)->assertJsonPath('error_code', 'freelance_ai_plan_no_plan');

        $this->trialPlan();
        $this->aiAnswer = '{"why": {"F1": "Knows both skills and is free now.", "F2": "Knows Vue; check their portfolio."}, "summary": "Start with the first one."}';
        $data = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.summary', 'Start with the first one.')
            ->assertJsonPath('data.can_invite', true)
            ->assertJsonPath('data.lists.best.0', $sara->uuid)
            ->assertJsonCount(2, 'data.candidates')
            ->json('data');

        $first = $data['candidates'][0];
        $this->assertSame($sara->uuid, $first['uuid']);
        $this->assertSame('Knows both skills and is free now.', $first['why']);
        $this->assertGreaterThan($data['candidates'][1]['score'], $first['score']);
        $this->assertContains('Has 2 of the 2 skills you need', $first['reasons']);
        $this->assertContains('Works in this exact specialty', $first['reasons']);
        $this->assertContains('New on DORR — a fresh chance', $first['reasons']);
        $this->assertFalse($first['invited']);
        // Names never reach the model.
        $this->assertStringNotContainsString('Sara', $this->aiPrompts[0]);

        // Opened again: the same answer without a new AI call; an invitation shows at once.
        FreelanceProjectInvitation::query()->create(['project_id' => $project->id, 'freelancer_id' => $sara->id, 'client_id' => $this->client->id, 'status' => FreelanceProjectInvitation::SENT, 'invited_at' => now()]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.candidates.0.invited', true);
        $this->assertCount(1, $this->aiPrompts);

        // Somebody else's project is not found.
        $other = User::query()->create(['name' => 'Ali', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->getJson($url)->assertNotFound();
    }

    private function trialPlan(): AiPlan
    {
        return AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 600, 'cooldown_minutes' => 5]);
    }

    private function skill(string $slug, string $name): FreelanceSkill
    {
        $skill = FreelanceSkill::query()->create(['slug' => $slug, 'status' => FreelanceSkill::ACTIVE]);
        $skill->translations()->create(['locale' => 'en', 'name' => $name, 'normalized' => FreelanceSkills::normalize($name)]);
        $skill->specialties()->attach($this->web->id);

        return $skill;
    }

    private function freelancer(string $phone, string $name, string $status = 'verified'): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => $status]);
    }

    /** @param  list<string>  $features */
    private function switches(array $features): void
    {
        foreach ($features as $feature) {
            FreelanceFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $this->saudi->id], ['enabled' => true]);
        }
        FreelanceFeatures::forget();
    }

    public function test_a_small_model_s_broken_json_is_repaired_not_refused(): void
    {
        // A real answer: the last array is never closed.
        $answer = "{\n\"title\": \"تطبيق مطعم\",\n\"skills\": [\"Flutter\"],\"budget\": {\"type\": \"محدد\", \"min\": 700},\n\"client_questions\": [\"س1\", \"س2\" }";
        $json = FreelanceAi::json($answer);
        $this->assertSame('تطبيق مطعم', $json['title']);
        $this->assertSame(['س1', 'س2'], $json['client_questions']);
        $this->assertSame(700, $json['budget']['min']);

        $this->assertSame(['a' => [1, 2]], FreelanceAi::json('{"a":[1,2,}'));
        $this->assertSame(['a' => 'x'], FreelanceAi::json('{"a":"x'));
        $this->assertSame(['a' => ['b' => [1]]], FreelanceAi::json("```json\n{\"a\":{\"b\":[1]}\n```"));
        $this->assertSame([], FreelanceAi::json('no json here'));
    }
}
