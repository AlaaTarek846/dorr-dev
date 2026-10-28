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
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-305 / FL-309: the client compares the offers on their project — sorted, filtered, two or three side by side, each freelancer's
 * profile without a way to reach them outside Dorr — and chooses one: it is accepted, the others are turned down and told, the
 * project stops taking offers and becomes `awarded`. Only once, only on a published project, only by its client.
 */
class FreelanceOfferChoiceTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private Country $saudi;

    private User $client;

    private FreelanceProject $project;

    /** @var array<string, Freelancer> */
    private array $f = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $laravel = $this->skill('laravel', 'Laravel');
        $vue = $this->skill('vue', 'Vue');
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->project->skills()->sync([$laravel->id, $vue->id]);

        // Sara: cheap and slow, knows both skills. Lina: dear and fast. Omar: in the middle, with a fuller profile.
        $this->f['sara'] = $this->freelancer('+966500000091', 'Sara', 300000, 25, [$laravel->id, $vue->id]);
        $this->f['lina'] = $this->freelancer('+966500000092', 'Lina', 500000, 10, []);
        $this->f['omar'] = $this->freelancer('+966500000093', 'Omar', 400000, 15, [$laravel->id], ['headline' => 'Full-stack developer', 'bio' => str_repeat('I build web apps. ', 10), 'years_of_experience' => 6]);
    }

    private function skill(string $slug, string $name): FreelanceSkill
    {
        $s = FreelanceSkill::query()->create(['slug' => $slug, 'status' => FreelanceSkill::ACTIVE]);
        $s->translations()->create(['locale' => 'en', 'name' => $name, 'normalized' => FreelanceSkills::normalize($name)]);

        return $s;
    }

    /** @param  list<int>  $skills  @param  array<string, mixed>  $profile */
    private function freelancer(string $phone, string $name, int $price, int $days, array $skills, array $profile = []): Freelancer
    {
        $f = Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'email' => strtolower($name).'@example.test', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified'] + $profile);
        $f->skills()->attach(collect($skills)->mapWithKeys(fn ($id) => [$id => ['level' => 'advanced', 'created_at' => now()]])->all());
        $this->asFreelancer($f);
        $this->postJson("/api/freelance/v1/projects/{$this->project->uuid}/offers", [
            'price_minor' => $price, 'duration_days' => $days, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
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

    private function offer(string $who): FreelanceOffer
    {
        return FreelanceOffer::query()->where('freelancer_id', $this->f[$who]->id)->firstOrFail();
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        return collect($this->getJson(self::CLIENT."/{$this->project->uuid}/offers{$query}")->assertOk()->json('data'))->pluck('freelancer.name')->all();
    }

    public function test_the_offers_come_with_what_helps_to_compare_sorted_and_filtered(): void
    {
        $this->asClient();
        $this->assertSame(['Omar', 'Lina', 'Sara'], $this->names(''), 'newest first');
        $this->assertSame(['Sara', 'Omar', 'Lina'], $this->names('?sort=cheapest'));
        $this->assertSame(['Lina', 'Omar', 'Sara'], $this->names('?sort=fastest'));
        $this->assertSame('Omar', $this->names('?sort=completeness')[0]);
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers?sort=random")->assertStatus(422);

        $sara = collect($this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->json('data'))->firstWhere('freelancer.name', 'Sara');
        $this->assertTrue($sara['freelancer']['verified']);
        $this->assertSame(['Laravel', 'Vue'], collect($sara['freelancer']['matching_skills'])->pluck('name')->sort()->values()->all());
        $this->assertSame(2, $sara['freelancer']['project_skills_count']);
        $this->assertNull($sara['freelancer']['rating']);
        $this->assertSame(['shortlist' => true, 'unshortlist' => false, 'reject' => true, 'choose' => true, 'chat' => true], $sara['can']);
        // Without the client fee switch the client pays the price.
        $this->assertSame(['price_minor' => 300000, 'client_fee_minor' => 0, 'total_minor' => 300000, 'currency' => 'SAR'], array_diff_key($sara['client_cost'], ['lines' => 1]));
        $this->assertSame(['price', 'total'], array_column($sara['client_cost']['lines'], 'key'));
        $this->assertArrayNotHasKey('phone', $sara['freelancer']);
        $this->assertArrayNotHasKey('email', $sara['freelancer']);

        // Only the shortlisted.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer('lina')->uuid}/shortlist")->assertOk();
        $this->assertSame(['Lina'], $this->names('?status=shortlisted'));

        // Taking her off the shortlist is quiet and puts her back to «seen».
        $lina = $this->offer('lina')->uuid;
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$lina}/unshortlist")->assertOk()->assertJsonPath('data.status', 'viewed')->assertJsonPath('data.can.unshortlist', false);
        $this->assertSame([], $this->names('?status=shortlisted'));
        $this->assertSame(0, $this->f['lina']->notifications()->where('data->event', 'freelance.offer.unshortlisted')->count());
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$lina}/unshortlist")->assertStatus(409);
    }

    public function test_two_or_three_offers_side_by_side_and_a_profile_without_contact_details(): void
    {
        $this->asClient();
        $ids = [$this->offer('sara')->uuid, $this->offer('lina')->uuid, $this->offer('omar')->uuid];
        $res = $this->getJson(self::CLIENT."/{$this->project->uuid}/offers/compare?ids=".implode(',', $ids))->assertOk();
        $this->assertSame(['Sara', 'Lina', 'Omar'], collect($res->json('data.offers'))->pluck('freelancer.name')->all(), 'in the order picked');
        $res->assertJsonPath('data.best.price', $ids[0])->assertJsonPath('data.best.duration', $ids[1])->assertJsonPath('data.best.skills', $ids[0])->assertJsonPath('data.best.completeness', $ids[2]);

        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers/compare?ids={$ids[0]}")->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_compare_count');
        $other = FreelanceProject::query()->create(['client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'title' => 'Another one', 'currency_code' => 'SAR', 'status' => FreelanceProjectStatus::Draft]);
        $this->getJson(self::CLIENT."/{$other->uuid}/offers/compare?ids={$ids[0]},{$ids[1]}")->assertNotFound();

        $profile = $this->getJson(self::CLIENT."/{$this->project->uuid}/offers/{$ids[2]}/freelancer")->assertOk()
            ->assertJsonPath('data.name', 'Omar')->assertJsonPath('data.headline', 'Full-stack developer')->assertJsonPath('data.years_of_experience', 6)->json('data');
        $this->assertArrayNotHasKey('phone', $profile);
        $this->assertArrayNotHasKey('email', $profile);
        $this->assertStringNotContainsString('omar@example.test', json_encode($profile));
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers/{$ids[2]}")->assertOk()->assertJsonPath('data.uuid', $ids[2]);
    }

    public function test_choosing_accepts_one_turns_the_others_down_and_awards_the_project(): void
    {
        FreelanceFeatureRule::query()->create(['feature' => 'client_fee', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $omar = $this->offer('omar')->uuid;
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer('lina')->uuid}/reject", ['reason' => 'Too dear'])->assertOk();

        // The confirm sheet shows what it costs: the price and the client fee of the policy.
        $expected = FreelanceFees::breakdown(400000, app(FreelancePolicies::class)->for($this->saudi->id, $this->project->freelance_specialty_id)->all());
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers/{$omar}")->assertJsonPath('data.client_cost.client_fee_minor', $expected['client_fee_minor'])->assertJsonPath('data.client_cost.total_minor', $expected['client_total_minor']);

        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$omar}/choose")->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.can.choose', false);

        $this->assertSame('accepted', $this->offer('omar')->status->value);
        $this->assertNotNull($this->offer('omar')->accepted_at);
        $this->assertSame('rejected', $this->offer('sara')->status->value);
        $this->assertSame('Too dear', $this->offer('lina')->status_reason, 'a reason the client gave stays');
        $project = $this->project->refresh();
        $this->assertSame(FreelanceProjectStatus::Awarded, $project->status);
        $this->assertNotNull($project->awarded_at);
        $this->assertSame('chosen', $project->offers_close_reason);
        $this->assertSame(1, $project->events()->where('type', 'project.awarded')->count());

        // Told: the chosen one is accepted; Sara is told another was chosen (Lina was already told when she was turned down).
        $this->assertSame(1, $this->f['omar']->notifications()->where('data->event', 'freelance.offer.accepted')->count());
        $this->assertSame(1, $this->f['sara']->notifications()->where('data->event', 'freelance.offer.rejected')->count());
        $this->assertSame(1, $this->f['lina']->notifications()->where('data->event', 'freelance.offer.rejected')->count());

        // The client's project page: awarded, closed to offers because one was chosen, with the chosen offer.
        $this->getJson(self::CLIENT."/{$this->project->uuid}")->assertJsonPath('data.status', 'awarded')->assertJsonPath('data.offers.close_reason', 'chosen')
            ->assertJsonPath('data.offers.chosen', $omar)->assertJsonPath('data.offers.can_reopen', false);

        // Sara reads why, still finds the project, and can't change her offer; nobody new can offer.
        $this->asFreelancer($this->f['sara']);
        $this->getJson("/api/freelance/v1/projects/{$this->project->uuid}")->assertOk()->assertJsonPath('data.status', 'awarded');
        $this->getJson("/api/freelance/v1/projects/{$this->project->uuid}/my-offer")->assertJsonPath('data.offer.status', 'rejected')
            ->assertJsonPath('data.offer.status_reason', __('freelance.offer_reasons.another_chosen'))->assertJsonPath('data.offer.can.edit', false);
        $this->assertNotContains($this->project->uuid, collect($this->getJson('/api/freelance/v1/projects?scope=all')->json('data'))->pluck('uuid')->all(), 'no longer in the list');
    }

    public function test_a_second_choice_and_a_project_not_published_are_refused(): void
    {
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer('sara')->uuid}/choose")->assertOk();
        // Lina was turned down by the choice; even so, a second choice is «already chosen».
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer('omar')->uuid}/choose")->assertStatus(409)->assertJsonPath('error_code', 'freelance_offer_already_chosen');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer('omar')->uuid}/shortlist")->assertStatus(409);
        $this->assertSame(1, FreelanceOffer::query()->where('status', 'accepted')->count());

        // A cancelled project takes no choice.
        $this->project->update(['status' => FreelanceProjectStatus::Cancelled]);
        FreelanceOffer::query()->whereKey($this->offer('omar')->id)->update(['status' => 'viewed']);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer('omar')->uuid}/choose")->assertStatus(409)->assertJsonPath('error_code', 'freelance_project_invalid_state');

        // Another client sees none of it.
        $other = User::query()->create(['name' => 'Ali', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertNotFound();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers/{$this->offer('omar')->uuid}/freelancer")->assertNotFound();
    }
}
