<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-307: the client asks the same questions of everyone who offers; each offer must answer all of them (no contact details), the
 * client reads the answers side by side.
 */
class FreelanceOfferQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1';

    private FreelanceProject $project;

    private Freelancer $me;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $client->id, 'country_id' => $saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->me = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        Sanctum::actingAs($this->me, [], 'freelance_api');
    }

    private const DETAILS = 'I built three booking sites like this; I will deliver in three steps with a demo each week.';

    public function test_offers_answer_the_projects_questions_and_the_client_compares_the_answers(): void
    {
        $this->project->update(['questions' => ['Have you built a booking site?', 'Which stack will you use?']]);
        $this->getJson(self::API."/projects/{$this->project->uuid}")->assertJsonPath('data.questions.1', 'Which stack will you use?');

        $send = fn (array $answers) => $this->postJson(self::API."/projects/{$this->project->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => self::DETAILS, 'answers' => $answers]);
        $send(['Yes, three.'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_answers_required');
        $send(['Yes, three.', ' '])->assertStatus(422);
        $send(['Yes, three.', 'Laravel — see www.mysite.com'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_contact_blocked');
        $send(['Yes, three.', 'Laravel and Vue.'])->assertCreated()->assertJsonPath('data.answers.1.answer', 'Laravel and Vue.');

        $offer = FreelanceOffer::query()->firstOrFail();
        $this->putJson(self::API."/offers/{$offer->uuid}", ['answers' => ['Yes, three.', 'Laravel, Vue and Tailwind.']])->assertOk()->assertJsonPath('data.answers.1.answer', 'Laravel, Vue and Tailwind.');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->firstOrFail(), [], 'user_api');
        $this->getJson("/api/mobile/v1/freelance/projects/{$this->project->uuid}/offers")->assertOk()
            ->assertJsonPath('data.0.answers.0.question', 'Have you built a booking site?')->assertJsonPath('data.0.answers.1.answer', 'Laravel, Vue and Tailwind.');
    }

    public function test_the_client_sets_at_most_five_questions_while_the_project_can_be_edited(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->firstOrFail(), [], 'user_api');
        $draft = $this->postJson('/api/mobile/v1/freelance/projects', ['title' => 'A logo for my clinic', 'questions' => ['Your style?', '', 'How many drafts?']])->assertCreated();
        $draft->assertJsonPath('data.questions', ['Your style?', 'How many drafts?']);
        $uuid = $draft->json('data.uuid');
        $this->putJson("/api/mobile/v1/freelance/projects/{$uuid}", ['questions' => ['1', '2', '3', '4', '5', '6']])->assertStatus(422);
        $this->putJson("/api/mobile/v1/freelance/projects/{$uuid}", ['questions' => []])->assertOk()->assertJsonPath('data.questions', []);
        // A published project can't change its questions.
        $this->putJson("/api/mobile/v1/freelance/projects/{$this->project->uuid}", ['questions' => ['New?']])->assertStatus(409);
    }

    public function test_without_questions_nothing_is_asked(): void
    {
        $this->postJson(self::API."/projects/{$this->project->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => self::DETAILS])->assertCreated()->assertJsonPath('data.answers', []);
    }
}
