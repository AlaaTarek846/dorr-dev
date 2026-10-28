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
 * FL-303: what happens to an offer after it is sent — the client opening them marks them seen (once, and the freelancer is told),
 * then shortlists, turns down (with an optional reason the freelancer reads) or chooses one; each move is allowed only from the
 * right status, is on record and is told to the freelancer at once.
 */
class FreelanceOfferFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private Country $saudi;

    private User $client;

    private Freelancer $sara;

    private Freelancer $lina;

    private FreelanceProject $project;

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
        $this->postJson("/api/freelance/v1/projects/{$this->project->uuid}/offers", [
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

    private function offerOf(Freelancer $f): FreelanceOffer
    {
        return FreelanceOffer::query()->where('freelancer_id', $f->id)->firstOrFail();
    }

    private function noticesOf(Freelancer $f, string $event): int
    {
        return $f->notifications()->where('data->event', $event)->count();
    }

    public function test_opening_the_offers_marks_them_seen_once_and_tells_each_freelancer_once(): void
    {
        $this->assertSame('submitted', $this->offerOf($this->sara)->status->value);

        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'viewed');
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();

        foreach ([$this->sara, $this->lina] as $f) {
            $offer = $this->offerOf($f);
            $this->assertSame('viewed', $offer->status->value);
            $this->assertNotNull($offer->viewed_at);
            $this->assertSame(1, $offer->events()->where('type', 'offer.viewed')->count());
            $this->assertSame(1, $this->noticesOf($f, 'freelance.offer.viewed'));
        }

        // The freelancer sees where it stands, with the history; the notice carries the project to open.
        $this->asFreelancer($this->sara);
        $notice = collect($this->getJson('/api/freelance/v1/notifications')->assertOk()->json('data'))->firstWhere('event', 'freelance.offer.viewed');
        $this->assertSame($this->project->uuid, $notice['data']['project'] ?? null);
        $this->getJson("/api/freelance/v1/projects/{$this->project->uuid}/my-offer")->assertOk()->assertJsonPath('data.offer.status', 'viewed')
            ->assertJsonPath('data.offer.timeline.0.type', 'offer.submitted')->assertJsonPath('data.offer.timeline.1.type', 'offer.viewed')->assertJsonPath('data.offer.timeline.1.to', 'viewed');
    }

    public function test_shortlisting_rejecting_with_a_reason_and_choosing(): void
    {
        $this->asClient();
        $sara = $this->offerOf($this->sara)->uuid;
        $lina = $this->offerOf($this->lina)->uuid;

        // Straight from «sent» (without opening the list) a client may shortlist or turn down.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$sara}/shortlist")->assertOk()->assertJsonPath('data.status', 'shortlisted');
        $this->assertNotNull($this->offerOf($this->sara)->viewed_at, 'deciding is also looking');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$lina}/reject", ['reason' => 'The price is above what we planned'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(1, $this->noticesOf($this->sara, 'freelance.offer.shortlisted'));
        $this->assertSame(1, $this->noticesOf($this->lina, 'freelance.offer.rejected'));

        // The rejected freelancer reads the reason; the shortlisted one has none.
        $this->asFreelancer($this->lina);
        $this->getJson("/api/freelance/v1/projects/{$this->project->uuid}/my-offer")->assertJsonPath('data.offer.status', 'rejected')->assertJsonPath('data.offer.status_reason', 'The price is above what we planned')->assertJsonPath('data.offer.can.edit', false);
        $this->asFreelancer($this->sara);
        $this->getJson("/api/freelance/v1/projects/{$this->project->uuid}/my-offer")->assertJsonPath('data.offer.status_reason', null);

        // Choosing: the shortlisted one is accepted, the freelancer is told, and no second one can be chosen.
        $this->asClient();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$sara}/choose")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(1, $this->noticesOf($this->sara, 'freelance.offer.accepted'));
        $this->assertSame(['offer.submitted', 'offer.shortlisted', 'offer.accepted'], $this->offerOf($this->sara)->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_only_the_allowed_moves_work_and_only_the_projects_client_can_make_them(): void
    {
        $this->asClient();
        $sara = $this->offerOf($this->sara)->uuid;
        $lina = $this->offerOf($this->lina)->uuid;

        // An offer that was never opened can't be chosen outright; a turned-down one can't come back.
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$sara}/choose")->assertStatus(409)->assertJsonPath('error_code', 'freelance_offer_invalid_state');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$sara}/reject")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$sara}/shortlist")->assertStatus(409);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$sara}/reject")->assertStatus(409);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$sara}/bogus")->assertNotFound();

        // One chosen offer per project.
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$lina}/choose")->assertOk();
        $this->assertSame(1, FreelanceOffer::query()->where('status', 'accepted')->count());

        // Another client, or a freelancer, can do none of it.
        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$lina}/reject")->assertNotFound();
        $this->asFreelancer($this->lina);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$lina}/reject")->assertUnauthorized();
    }

    public function test_a_freelancer_can_change_a_seen_offer_and_a_seen_withdrawn_offer_keeps_its_quota_place(): void
    {
        $this->asClient();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();

        $this->asFreelancer($this->sara);
        $offer = $this->offerOf($this->sara)->uuid;
        $this->putJson("/api/freelance/v1/offers/{$offer}", ['duration_days' => 14])->assertOk()->assertJsonPath('data.status', 'viewed');
        $this->postJson("/api/freelance/v1/offers/{$offer}/withdraw")->assertOk();
        $this->assertSame(1, $this->noticesOf($this->sara, 'freelance.offer.viewed'));
        $this->assertFalse((bool) $this->offerOf($this->sara)->events()->where('type', 'offer.withdrawn')->first()->payload['quota_returned']);
    }
}
