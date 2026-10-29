<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\ContactGuard;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-306: the client keeps freelancers who offered on their projects, sees the mark on their offers, and lists them.
 */
class FreelanceClientFavoritesTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance';

    private const FREELANCER = '/api/freelance/v1';

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
        $this->postJson(self::FREELANCER."/projects/{$this->project->uuid}/offers", [
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

    private function open(Freelancer $f): string
    {
        $this->asClient();

        return $this->postJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($f)->uuid}/chat")->assertOk()->json('data.uuid');
    }

    public function test_the_client_keeps_a_freelancer_who_offered_and_sees_the_mark(): void
    {
        $this->asClient();
        $sara = $this->sara->uuid;
        $this->getJson(self::CLIENT.'/favorites')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::CLIENT."/favorites/{$sara}")->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->postJson(self::CLIENT."/favorites/{$sara}")->assertOk();
        $this->getJson(self::CLIENT.'/favorites')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sara')->assertJsonMissingPath('data.0.phone');

        $offers = collect($this->getJson(self::CLIENT."/projects/{$this->project->uuid}/offers")->json('data'));
        $this->assertTrue($offers->firstWhere('freelancer.name', 'Sara')['freelancer']['is_favorite']);
        $this->assertFalse($offers->firstWhere('freelancer.name', 'Lina')['freelancer']['is_favorite']);
        $this->getJson(self::CLIENT."/projects/{$this->project->uuid}/offers/{$this->offer($this->sara)->uuid}/freelancer")->assertJsonPath('data.is_favorite', true);

        $this->deleteJson(self::CLIENT."/favorites/{$sara}")->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->getJson(self::CLIENT.'/favorites')->assertJsonCount(0, 'data');
    }

    public function test_only_a_freelancer_who_offered_to_me_can_be_kept(): void
    {
        $stranger = Freelancer::query()->create(['name' => 'Omar', 'phone' => '+966500000093', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->asClient();
        $this->postJson(self::CLIENT."/favorites/{$stranger->uuid}")->assertNotFound();

        $other = User::query()->create(['name' => 'Ali', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->postJson(self::CLIENT."/favorites/{$this->sara->uuid}")->assertNotFound();
    }

    public function test_the_kept_freelancers_come_in_pages(): void
    {
        $this->asClient();
        $this->postJson(self::CLIENT."/favorites/{$this->sara->uuid}")->assertOk();
        $this->postJson(self::CLIENT."/favorites/{$this->lina->uuid}")->assertOk();
        $this->getJson(self::CLIENT.'/favorites?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.has_more_pages', true)->assertJsonPath('pagination.total', 2);
        $this->getJson(self::CLIENT.'/favorites?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.has_more_pages', false);
    }
}
