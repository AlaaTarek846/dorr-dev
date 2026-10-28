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
 * FL-304: an offer is checked before it is sent (warnings the freelancer may ignore; contact details refused, in the offer as in the
 * chat), and the freelancer keeps texts to start an offer from.
 */
class FreelanceOfferTemplatesTest extends TestCase
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

    /** @return list<string> */
    private function codes(array $body): array
    {
        return collect($this->postJson(self::API."/projects/{$this->project->uuid}/offer-check", $body)->assertOk()->json('data.checks'))->pluck('code')->all();
    }

    public function test_the_check_warns_about_a_weak_offer_and_flags_contact_details(): void
    {
        $this->assertSame(['details_short', 'no_files', 'price_high', 'duration_long'], $this->codes(['price_minor' => 900000, 'duration_days' => 45, 'details' => 'I can do it well.', 'files' => 0]));
        $this->assertSame(['price_low'], $this->codes(['price_minor' => 100000, 'duration_days' => 20, 'details' => str_repeat('I will build it in three steps with a demo each week. ', 4), 'files' => 1]));
        $this->assertSame([], $this->codes(['price_minor' => 380000, 'duration_days' => 25, 'details' => str_repeat('I will build it in three steps with a demo each week. ', 4), 'files' => 2]));
        $checks = $this->postJson(self::API."/projects/{$this->project->uuid}/offer-check", ['details' => 'call me on 0501234567', 'files' => 1])->json('data.checks');
        $this->assertSame('error', $checks[0]['level']);
        $this->assertSame('contact', $checks[0]['code']);
    }

    public function test_an_offer_or_a_change_with_contact_details_is_refused(): void
    {
        $details = 'I built three booking sites like this; write me at sara@example.com to see them.';
        $this->postJson(self::API."/projects/{$this->project->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => $details])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_contact_blocked');
        $this->assertSame(0, FreelanceOffer::query()->count());

        $this->postJson(self::API."/projects/{$this->project->uuid}/offers", ['price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.'])->assertCreated();
        $offer = FreelanceOffer::query()->firstOrFail()->uuid;
        $this->putJson(self::API."/offers/{$offer}", ['details' => 'Same plan as before, and my portfolio is at www.sara-dev.com for you.'])->assertStatus(422);
    }

    public function test_templates_are_kept_changed_and_removed_only_by_their_owner(): void
    {
        $id = $this->postJson(self::API.'/offer-templates', ['title' => 'Websites', 'details' => 'I build fast, clean websites in three steps with a weekly demo.'])->assertCreated()->json('data.id');
        $this->putJson(self::API."/offer-templates/{$id}", ['title' => 'Web apps', 'details' => 'I build fast, clean web apps in three steps with a weekly demo.'])->assertOk()->assertJsonPath('data.title', 'Web apps');
        $this->getJson(self::API.'/offer-templates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Web apps');
        $this->postJson(self::API.'/offer-templates', ['title' => 'Bad', 'details' => 'Reach me on WhatsApp 0501234567 for anything at all.'])->assertStatus(422);

        $other = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000092', 'country_id' => $this->me->country_id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'freelance_api');
        $this->putJson(self::API."/offer-templates/{$id}", ['title' => 'Mine', 'details' => 'I build fast, clean web apps in three steps with a weekly demo.'])->assertNotFound();
        $this->deleteJson(self::API."/offer-templates/{$id}")->assertNotFound();
        $this->getJson(self::API.'/offer-templates')->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me, [], 'freelance_api');
        $this->deleteJson(self::API."/offer-templates/{$id}")->assertOk();
        $this->getJson(self::API.'/offer-templates')->assertJsonCount(0, 'data');
    }

    public function test_at_most_ten_templates(): void
    {
        foreach (range(1, 10) as $i) {
            $this->postJson(self::API.'/offer-templates', ['title' => "T{$i}", 'details' => 'I build fast, clean websites in three steps with a weekly demo.'])->assertCreated();
        }
        $this->postJson(self::API.'/offer-templates', ['title' => 'T11', 'details' => 'I build fast, clean websites in three steps with a weekly demo.'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_offer_templates_full');
    }
}
