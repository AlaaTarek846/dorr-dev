<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerAvailability;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-301: a freelancer sends an offer on a published project — only when verified, taking work, the project still open, not offered
 * there already, the project not full and the monthly free quota not used up; the price never under the budget minimum. The commission
 * and the net are worked out from the policy and kept; the client is told and is the only other one who sees it.
 */
class FreelancerOffersTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1/projects';

    private Country $saudi;

    private FreelanceSpecialty $web;

    private User $client;

    private Freelancer $me;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'free_offer_quota', 'commission'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $this->web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->client = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->me = $this->freelancer('+966500000091');
    }

    private function freelancer(string $phone, string $status = 'verified'): Freelancer
    {
        $f = Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => $status]);
        $this->as($f);

        return $f;
    }

    private function as(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    private function project(array $o = []): FreelanceProject
    {
        return FreelanceProject::query()->create($o + [
            'client_id' => $this->client->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->web->id,
            'title' => 'A booking website', 'description' => 'A small site where patients pick a doctor and a time.',
            'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR', 'duration_days' => 30,
            'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    /** @return array<string, mixed> */
    private function offer(array $o = []): array
    {
        return $o + ['price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.'];
    }

    public function test_an_offer_is_sent_with_its_files_and_the_net_the_policy_gives(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['commission.type' => 'percent', 'commission.value' => 1000]);
        $p = $this->project();

        $this->getJson(self::API."/{$p->uuid}/offer-preview?price_minor=350000")->assertOk()
            ->assertJsonPath('data.blocked', null)->assertJsonPath('data.breakdown.commission_minor', 35000)->assertJsonPath('data.breakdown.net_minor', 315000)
            ->assertJsonPath('data.quota.limit', 10)->assertJsonPath('data.quota.left', 10);

        $uuid = $this->post(self::API."/{$p->uuid}/offers", $this->offer(['files' => [UploadedFile::fake()->create('plan.pdf', 30, 'application/pdf')]]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.net_minor', 315000)->assertJsonPath('data.currency', 'SAR')
            ->assertJsonCount(1, 'data.attachments')->json('data.uuid');

        // The policy changing later does not move the offer.
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['commission.value' => 2000]);
        $this->assertSame(315000, FreelanceOffer::query()->where('uuid', $uuid)->value('net_minor'));

        $this->getJson(self::API."/{$p->uuid}/my-offer")->assertOk()->assertJsonPath('data.offer.uuid', $uuid)->assertJsonPath('data.offer.commission_minor', 35000);
        $this->getJson(self::API."/{$p->uuid}")->assertOk()->assertJsonPath('data.offered', true);
        $this->getJson('/api/freelance/v1/offers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.project.title', 'A booking website');
        $media = FreelanceOffer::query()->where('uuid', $uuid)->first()->getMedia('attachments')->first()->id;
        $this->get("/api/freelance/v1/offers/{$uuid}/attachments/{$media}")->assertOk();

        // The client is told, and sees it with the freelancer who sent it.
        $this->assertSame(1, $this->client->notifications()->where('data->event', 'freelance.offer.received')->count());
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, [], 'user_api');
        $this->getJson("/api/mobile/v1/freelance/projects/{$p->uuid}/offers")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.freelancer.name', 'Sara')->assertJsonPath('data.0.price_minor', 350000)->assertJsonMissingPath('data.0.net_minor');
        $this->get("/api/mobile/v1/freelance/projects/{$p->uuid}/offers/{$uuid}/attachments/{$media}")->assertOk();
        $this->getJson("/api/mobile/v1/freelance/projects/{$p->uuid}")->assertOk()->assertJsonPath('data.offers.count', 1);
    }

    public function test_one_offer_per_project_and_the_bad_ones_are_refused(): void
    {
        $p = $this->project();
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer(['details' => 'too short']))->assertUnprocessable();
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer(['duration_days' => 0]))->assertUnprocessable();
        $this->post(self::API."/{$p->uuid}/offers", $this->offer(['files' => [UploadedFile::fake()->create('x.exe', 5)]]), ['Accept' => 'application/json'])->assertUnprocessable();

        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertCreated();
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertStatus(409)->assertJsonPath('error_code', 'freelance_offer_already_sent');
        $this->getJson(self::API."/{$p->uuid}/offer-preview")->assertJsonPath('data.blocked.code', 'freelance_offer_already_sent');
        $this->assertSame(1, FreelanceOffer::query()->count());
    }

    public function test_the_price_is_free_but_never_under_the_budget_minimum(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, $this->web->id, ['project.min_budget_minor' => 100000]);
        $p = $this->project();
        $this->getJson(self::API."/{$p->uuid}/offer-preview")->assertJsonPath('data.min_price_minor', 100000);
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer(['price_minor' => 90000]))->assertUnprocessable()->assertJsonPath('error_code', 'freelance_offer_below_min');
        // Above the project's own budget is fine: the client decides.
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer(['price_minor' => 900000]))->assertCreated();
    }

    public function test_who_may_offer_and_when(): void
    {
        $p = $this->project();

        // Not verified.
        $this->freelancer('+966500000092', 'profile_ready');
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertForbidden()->assertJsonPath('error_code', 'freelance_verification_required');
        $this->getJson(self::API."/{$p->uuid}/offer-preview")->assertOk()->assertJsonPath('data.blocked.code', 'freelance_verification_required');

        // Paused (FL-106).
        FreelancerAvailability::query()->create(['freelancer_id' => $this->me->id, 'accepting_requests' => false]);
        $this->as($this->me);
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertUnprocessable()->assertJsonPath('error_code', 'freelance_unavailable');
        FreelancerAvailability::query()->where('freelancer_id', $this->me->id)->update(['accepting_requests' => true]);
        $this->as($this->me); // a fresh account (each real request reads it anew)

        // The project's time is up (FL-204), even before the hourly command ran.
        $p->update(['offers_deadline_at' => now()->subMinute()]);
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_offers_closed');

        // A project the freelancer can't see is not found.
        $hidden = $this->project(['visibility' => 'private']);
        $this->postJson(self::API."/{$hidden->uuid}/offers", $this->offer())->assertNotFound();

        // The bidding switch off.
        $open = $this->project(['title' => 'Another']);
        FreelanceFeatureRule::query()->where('feature', 'bidding')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->postJson(self::API."/{$open->uuid}/offers", $this->offer())->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
    }

    public function test_the_project_fills_up_and_then_closes_to_offers(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['offers.max_per_project' => 2]);
        $p = $this->project();

        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertCreated();
        $this->freelancer('+966500000093');
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertCreated();

        $p->refresh();
        $this->assertSame('max_offers', $p->offers_close_reason);
        $this->freelancer('+966500000094');
        $this->postJson(self::API."/{$p->uuid}/offers", $this->offer())->assertUnprocessable()->assertJsonPath('error_code', 'freelance_project_offers_closed');
    }

    public function test_the_monthly_free_quota_is_counted_and_starts_again_each_month(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['offers.free_monthly_quota' => 2]);
        $projects = collect(range(1, 3))->map(fn ($i) => $this->project(['title' => "Project {$i}"]));

        $this->postJson(self::API."/{$projects[0]->uuid}/offers", $this->offer())->assertCreated();
        $this->postJson(self::API."/{$projects[1]->uuid}/offers", $this->offer())->assertCreated();
        $this->getJson(self::API."/{$projects[2]->uuid}/offer-preview")->assertJsonPath('data.quota.left', 0)->assertJsonPath('data.blocked.code', 'freelance_offer_quota_exhausted');
        $this->postJson(self::API."/{$projects[2]->uuid}/offers", $this->offer())->assertUnprocessable()->assertJsonPath('error_code', 'freelance_offer_quota_exhausted')->assertJsonPath('data.limit', 2);

        // Next month the quota is full again.
        $this->travel(1)->months();
        $projects[2]->update(['offers_deadline_at' => now()->addDays(10)]);
        $this->getJson(self::API."/{$projects[2]->uuid}/offer-preview")->assertJsonPath('data.quota.left', 2);
        $this->postJson(self::API."/{$projects[2]->uuid}/offers", $this->offer())->assertCreated();
    }

    public function test_without_the_quota_switch_there_is_no_monthly_limit_and_without_the_commission_switch_no_commission(): void
    {
        FreelanceFeatureRule::query()->whereIn('feature', ['free_offer_quota', 'commission'])->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $p = $this->project();
        $this->getJson(self::API."/{$p->uuid}/offer-preview?price_minor=350000")->assertJsonPath('data.quota.limit', null)->assertJsonPath('data.breakdown.commission_minor', 0)->assertJsonPath('data.breakdown.net_minor', 350000);
    }

    public function test_nobody_else_sees_the_offer_or_its_files(): void
    {
        $p = $this->project();
        $uuid = $this->post(self::API."/{$p->uuid}/offers", $this->offer(['files' => [UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')]]), ['Accept' => 'application/json'])->assertCreated()->json('data.uuid');
        $media = FreelanceOffer::query()->where('uuid', $uuid)->first()->getMedia('attachments')->first()->id;

        $this->freelancer('+966500000095');
        $this->get("/api/freelance/v1/offers/{$uuid}/attachments/{$media}", ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson(self::API."/{$p->uuid}/my-offer")->assertOk()->assertJsonPath('data.offer', null);

        $other = User::query()->create(['name' => 'Omar', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->getJson("/api/mobile/v1/freelance/projects/{$p->uuid}/offers")->assertNotFound();
    }

    // ------------------------------------------------------------------ FL-302: change and take back

    private function sent(FreelanceProject $p, array $o = []): string
    {
        return $this->postJson(self::API."/{$p->uuid}/offers", $this->offer($o))->assertCreated()->json('data.uuid');
    }

    public function test_an_open_offer_can_be_changed_and_what_it_said_is_kept(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['commission.type' => 'percent', 'commission.value' => 1000]);
        $p = $this->project();
        $uuid = $this->sent($p, ['price_minor' => 350000]);
        $this->getJson("/api/freelance/v1/projects/{$p->uuid}/my-offer")->assertJsonPath('data.offer.can.edit', true)->assertJsonPath('data.offer.revisions', 0);

        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['price_minor' => 300000, 'duration_days' => 14])->assertOk()
            ->assertJsonPath('data.price_minor', 300000)->assertJsonPath('data.duration_days', 14)->assertJsonPath('data.commission_minor', 30000)->assertJsonPath('data.net_minor', 270000)
            ->assertJsonPath('data.revisions', 1)->assertJsonPath('data.details', $this->offer()['details']);
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['details' => 'A new plan: a demo every week, tests included, and a handover session at the end.'])->assertOk()->assertJsonPath('data.revisions', 2);

        $this->getJson("/api/freelance/v1/offers/{$uuid}/revisions")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.price_minor', 350000)->assertJsonPath('data.0.net_minor', 315000)->assertJsonPath('data.1.price_minor', 300000)->assertJsonPath('data.1.details', $this->offer()['details']);
        $this->assertSame(['offer.submitted', 'offer.edited', 'offer.edited'], FreelanceOffer::query()->where('uuid', $uuid)->first()->events()->orderBy('id')->pluck('type')->all());
        $this->assertSame(2, $this->client->notifications()->where('data->event', 'freelance.offer.updated')->count());

        // The client sees the change (their copy says it was edited), never the freelancer's net.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, [], 'user_api');
        $this->getJson("/api/mobile/v1/freelance/projects/{$p->uuid}/offers")->assertJsonPath('data.0.price_minor', 300000)->assertJsonPath('data.0.edited', true)->assertJsonMissingPath('data.0.net_minor');
    }

    public function test_a_change_is_checked_like_a_new_offer_and_must_change_something(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, $this->web->id, ['project.min_budget_minor' => 100000]);
        $p = $this->project();
        $uuid = $this->sent($p);

        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['price_minor' => 50000])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_offer_below_min');
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['details' => 'too short'])->assertUnprocessable();
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['duration_days' => 0])->assertUnprocessable();
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['price_minor' => 350000])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_offer_unchanged');
        $this->assertSame(0, FreelanceOffer::query()->where('uuid', $uuid)->first()->revisions()->count());
    }

    public function test_it_can_be_changed_after_the_time_is_up_but_not_once_decided_or_by_someone_else(): void
    {
        $p = $this->project();
        $uuid = $this->sent($p);

        // The project's window closed: the offer already made can still be improved.
        $p->update(['offers_deadline_at' => now()->subHour()]);
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['duration_days' => 10])->assertOk();

        // Another freelancer can neither change nor withdraw nor read it.
        $mine = $this->me;
        $this->freelancer('+966500000096');
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['duration_days' => 5])->assertNotFound();
        $this->postJson("/api/freelance/v1/offers/{$uuid}/withdraw")->assertNotFound();
        $this->getJson("/api/freelance/v1/offers/{$uuid}/revisions")->assertNotFound();
        $this->as($mine);

        // Decided by the client: no more changes.
        FreelanceOffer::query()->where('uuid', $uuid)->update(['status' => 'accepted']);
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['duration_days' => 6])->assertStatus(409)->assertJsonPath('error_code', 'freelance_offer_not_editable');
        $this->postJson("/api/freelance/v1/offers/{$uuid}/withdraw")->assertStatus(409);
        $this->getJson("/api/freelance/v1/projects/{$p->uuid}/my-offer")->assertJsonPath('data.offer.can.edit', false)->assertJsonPath('data.offer.can.withdraw', false);

        // The project cancelled: nothing to change either.
        FreelanceOffer::query()->where('uuid', $uuid)->update(['status' => 'submitted']);
        $p->update(['status' => FreelanceProjectStatus::Cancelled]);
        $this->putJson("/api/freelance/v1/offers/{$uuid}", ['duration_days' => 7])->assertStatus(409);
    }

    public function test_taking_back_an_unseen_offer_gives_the_free_one_back_but_a_seen_one_keeps_it(): void
    {
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['offers.free_monthly_quota' => 2]);
        $a = $this->project(['title' => 'A']);
        $b = $this->project(['title' => 'B']);
        $c = $this->project(['title' => 'C']);
        $ua = $this->sent($a);
        $ub = $this->sent($b);
        $this->getJson("/api/freelance/v1/projects/{$c->uuid}/offer-preview")->assertJsonPath('data.quota.left', 0);

        // Unseen: the place comes back.
        $this->postJson("/api/freelance/v1/offers/{$ua}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn')->assertJsonPath('data.can.edit', false);
        $this->getJson("/api/freelance/v1/projects/{$c->uuid}/offer-preview")->assertJsonPath('data.quota.left', 1)->assertJsonPath('data.blocked', null);
        $this->assertTrue((bool) FreelanceOffer::query()->where('uuid', $ua)->first()->events()->where('type', 'offer.withdrawn')->first()->payload['quota_returned']);

        // Seen by the client: it stays counted.
        FreelanceOffer::query()->where('uuid', $ub)->update(['viewed_at' => now(), 'status' => 'viewed']);
        $this->postJson("/api/freelance/v1/offers/{$ub}/withdraw")->assertOk();
        $this->getJson("/api/freelance/v1/projects/{$c->uuid}/offer-preview")->assertJsonPath('data.quota.left', 1);

        // A withdrawn offer is not shown to the client and can't be sent again on that project.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, [], 'user_api');
        $this->getJson("/api/mobile/v1/freelance/projects/{$a->uuid}/offers")->assertJsonCount(0, 'data');
        $this->as($this->me);
        $this->postJson("/api/freelance/v1/projects/{$a->uuid}/offers", $this->offer())->assertStatus(409)->assertJsonPath('error_code', 'freelance_offer_already_sent');
        $this->postJson("/api/freelance/v1/offers/{$ua}/withdraw")->assertStatus(409);
    }
}
