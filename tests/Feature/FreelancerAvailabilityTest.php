<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerAvailabilityService;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Verification\FreelancerGate;
use Tests\TestCase;

/** FL-106: a freelancer's pause, guide price, weekly hours and time off — scoped to them, validated, and the one rule that stops offers. */
class FreelancerAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Freelancer $me;

    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();
        $this->me = $this->freelancer('+966500000081');
    }

    private function freelancer(string $phone): Freelancer
    {
        $f = Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f, [], 'freelance_api');

        return $f;
    }

    private function service(): FreelancerAvailabilityService
    {
        return app(FreelancerAvailabilityService::class);
    }

    public function test_a_new_freelancer_is_open_with_no_price_hours_or_time_off(): void
    {
        $this->getJson('/api/freelance/v1/profile/availability')->assertOk()
            ->assertJsonPath('data.accepting_requests', true)->assertJsonPath('data.can_receive_offers', true)
            ->assertJsonPath('data.rate.type', 'none')->assertJsonPath('data.weekly_hours', [])->assertJsonPath('data.time_off', []);
        $this->getJson('/api/freelance/v1/auth/me')->assertOk()->assertJsonPath('data.availability.can_receive_offers', true);
    }

    public function test_the_guide_price_and_weekly_hours_are_saved_and_validated(): void
    {
        $this->putJson('/api/freelance/v1/profile/availability', [
            'rate_type' => 'hourly', 'rate_min_minor' => 5000, 'rate_max_minor' => 12000,
            'weekly_hours' => [['day' => 2, 'from' => '09:00', 'to' => '17:00'], ['day' => 0, 'from' => '10:00', 'to' => '14:00']],
        ])->assertOk()->assertJsonPath('data.rate.type', 'hourly')->assertJsonPath('data.rate.min_minor', 5000)->assertJsonPath('data.weekly_hours.0.day', 0)->assertJsonPath('data.weekly_hours.1.to', '17:00');

        // "none" clears the price.
        $this->putJson('/api/freelance/v1/profile/availability', ['rate_type' => 'none'])->assertOk()->assertJsonPath('data.rate.min_minor', null);

        $this->putJson('/api/freelance/v1/profile/availability', ['rate_type' => 'fixed'])->assertUnprocessable();
        $this->putJson('/api/freelance/v1/profile/availability', ['rate_type' => 'fixed', 'rate_min_minor' => 9000, 'rate_max_minor' => 100])->assertUnprocessable();
        $this->putJson('/api/freelance/v1/profile/availability', ['weekly_hours' => [['day' => 1, 'from' => '17:00', 'to' => '09:00']]])->assertUnprocessable();
        $this->putJson('/api/freelance/v1/profile/availability', ['weekly_hours' => [['day' => 1, 'from' => '09:00', 'to' => '10:00'], ['day' => 1, 'from' => '11:00', 'to' => '12:00']]])->assertUnprocessable();
        $this->putJson('/api/freelance/v1/profile/availability', ['weekly_hours' => [['day' => 9, 'from' => '09:00', 'to' => '10:00']]])->assertUnprocessable();
    }

    public function test_pausing_stops_offers_and_a_pause_with_an_end_day_lifts_by_itself(): void
    {
        $this->putJson('/api/freelance/v1/profile/availability', ['accepting_requests' => false, 'pause_reason' => 'Travelling'])->assertOk()
            ->assertJsonPath('data.accepting_requests', false)->assertJsonPath('data.can_receive_offers', false)->assertJsonPath('data.pause_reason', 'Travelling');

        $gate = app(FreelancerGate::class);
        try {
            $gate->assertCanOffer($this->me->refresh());
            $this->fail('A paused freelancer must not be able to offer.');
        } catch (FreelanceException $e) {
            $this->assertSame('unavailable', $e->errorCode);
        }

        // Open again: the pause details go.
        $this->putJson('/api/freelance/v1/profile/availability', ['accepting_requests' => true])->assertOk()->assertJsonPath('data.can_receive_offers', true)->assertJsonPath('data.pause_reason', null);
        $gate->assertCanOffer($this->me->refresh());

        // A pause with an end day is over after it, with nothing running to lift it.
        $this->putJson('/api/freelance/v1/profile/availability', ['accepting_requests' => false, 'paused_until' => now()->addDay()->toDateString()])->assertOk();
        $this->assertFalse($this->service()->canReceiveOffers($this->me->refresh()));
        $this->travel(3)->days();
        $this->assertTrue($this->service()->canReceiveOffers($this->me->refresh()));
        $this->travelBack();
        $this->putJson('/api/freelance/v1/profile/availability', ['accepting_requests' => false, 'paused_until' => now()->subDay()->toDateString()])->assertUnprocessable();
    }

    public function test_time_off_is_validated_listed_scoped_and_stops_offers_while_it_runs(): void
    {
        $today = now()->toDateString();
        $this->postJson('/api/freelance/v1/profile/time-off', ['starts_on' => $today, 'ends_on' => now()->addDays(3)->toDateString(), 'reason' => 'Holiday'])->assertCreated()->assertJsonPath('data.reason', 'Holiday');
        $this->getJson('/api/freelance/v1/profile/time-off')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/freelance/v1/profile/availability')->assertOk()->assertJsonPath('data.on_time_off', true)->assertJsonPath('data.can_receive_offers', false);

        try {
            app(FreelancerGate::class)->assertCanOffer($this->me->refresh());
            $this->fail('A freelancer on time off must not be able to offer.');
        } catch (FreelanceException $e) {
            $this->assertSame('unavailable', $e->errorCode);
        }

        // A period overlapping the first, an end before the start, and one starting in the past are refused.
        $this->postJson('/api/freelance/v1/profile/time-off', ['starts_on' => now()->addDays(2)->toDateString(), 'ends_on' => now()->addDays(5)->toDateString()])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_time_off_overlap');
        $this->postJson('/api/freelance/v1/profile/time-off', ['starts_on' => now()->addDays(9)->toDateString(), 'ends_on' => now()->addDays(8)->toDateString()])->assertUnprocessable();
        $this->postJson('/api/freelance/v1/profile/time-off', ['starts_on' => now()->subDay()->toDateString(), 'ends_on' => $today])->assertUnprocessable();

        // Nobody else can remove it; its owner can, and offers open again.
        $id = $this->getJson('/api/freelance/v1/profile/time-off')->json('data.0.id');
        $this->freelancer('+966500000082');
        $this->deleteJson("/api/freelance/v1/profile/time-off/{$id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me->refresh(), [], 'freelance_api');
        $this->deleteJson("/api/freelance/v1/profile/time-off/{$id}")->assertOk();
        $this->getJson('/api/freelance/v1/profile/availability')->assertOk()->assertJsonPath('data.can_receive_offers', true);
    }

    public function test_time_off_is_capped(): void
    {
        $max = (int) config('freelance.profile.max_time_off');
        foreach (range(1, $max) as $i) {
            $start = now()->addDays($i * 10);
            $this->postJson('/api/freelance/v1/profile/time-off', ['starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->addDay()->toDateString()])->assertCreated();
        }
        $start = now()->addDays(($max + 1) * 10);
        $this->postJson('/api/freelance/v1/profile/time-off', ['starts_on' => $start->toDateString(), 'ends_on' => $start->toDateString()])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_limit');
    }

    public function test_a_signed_out_caller_is_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/freelance/v1/profile/availability')->assertUnauthorized();
    }
}
