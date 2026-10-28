<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\Discover\Models\DiscoverCity;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverLocationBatch;
use Modules\Driver\Models\DriverLocationLog;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Events\RideDriverMoved;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideCase;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Models\RideSetting;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Dispatch\SmartDispatch;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Services\Pricing\SmartPrice;
use Modules\Rides\Services\Safety\FraudSignals;
use Modules\Rides\Services\Scheduling\LateRisk;
use Modules\User\Models\Address;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Block 06 — DORR AI in rides: the rider's trip in their own words on whichever map is switched on,
 * «راحتي», the captain's next trip, DORR Driver AI and the demand on a plan, a booking's lateness
 * warning, fraud signs, the case triage, the quality report, smart dispatch and smart pricing.
 */
class RidesAiBlock06Test extends TestCase
{
    private const PICKUP = ['lat' => 24.7136, 'lng' => 46.6753, 'address' => 'Olaya'];

    private const DROPOFF = ['lat' => 24.7500, 'lng' => 46.7000, 'address' => 'King Fahd Rd'];

    private Country $saudi;

    private User $rider;

    private RideCategory $economy;

    private int $plate = 3000;

    /** What DORR AI answers next (JSON text). */
    private string $aiAnswer = '{}';

    /** @var list<string> */
    private array $aiPrompts = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(AiProviderRepository::class)->ensureDefaults();
        AiProvider::query()->where('key', 'openai')->firstOrFail()->update(['is_enabled' => true, 'is_default' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => function (HttpRequest $request) {
                $this->aiPrompts[] = (string) json_encode($request->data()['messages'] ?? [], JSON_UNESCAPED_UNICODE);

                return Http::response(['choices' => [['message' => ['content' => $this->aiAnswer]]]]);
            },
            'api.mapbox.com/geocoding/*' => Http::response(['features' => [
                ['text' => 'مطار الملك خالد الدولي', 'place_name' => 'مطار الملك خالد الدولي، الرياض', 'center' => [46.6988, 24.9576]],
            ]]),
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'OK', 'results' => [
                ['formatted_address' => 'King Khalid International Airport, Riyadh', 'geometry' => ['location' => ['lat' => 24.9576, 'lng' => 46.6988]]],
            ]]),
            '*' => Http::response([], 200),
        ]);
        Event::fake([RideChanged::class, RideDriverMoved::class, DriverChanged::class]);
        config(['rides.regulator_enforced' => false, 'broadcasting.default' => 'null']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4, 'flags' => ['cash_allowed' => true]]);
        $this->switches(['rides', 'category.economy', 'cash']);
        app(RidePolicies::class)->save($this->saudi->id, null, null, $this->economy->id, ['price.base_minor' => 600, 'price.per_km_minor' => 180, 'price.per_min_minor' => 30, 'price.min_fare_minor' => 1500]);
        RidePolicies::forget();
        CityResolver::forget();

        $this->rider = $this->user('Sara Ahmed', '+966500000001');
    }

    public function test_the_rider_says_the_trip_in_their_words_and_gets_places_on_whichever_map_is_on(): void
    {
        $this->switches(['ai_booking']);
        $say = fn (string $text) => $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/ai/understand', ['text' => $text] + self::PICKUP);

        // A DORR AI tool: nothing without a plan — the button is locked first, and the call says why.
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/ai/status?lat=24.7136&lng=46.6753')->assertOk()
            ->assertJsonPath('data.available', true)->assertJsonPath('data.allowed', false)->assertJsonPath('data.reason', 'no_plan');
        $say('ودّيني المطار')->assertStatus(402)->assertJsonPath('error_code', 'rides_ai_plan_no_plan')->assertJsonPath('data.reason', 'no_plan');

        $this->trialPlan();
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/ai/status?lat=24.7136&lng=46.6753')->assertJsonPath('data.allowed', true);

        // Mapbox switched on: the place comes from Mapbox, and the rest of the sentence fills the booking sheet.
        RideSetting::current()->update(['map_provider' => 'mapbox', 'mapbox_token' => 'pk.test']);
        $this->aiAnswer = '{"destination": "مطار الملك خالد", "category": "economy", "when": null, "passengers": 2, "payment": "cash", "note": "عند البوابة 3", "clarify": null}';
        $say('ودّيني مطار الملك خالد، اتنين، كاش، أنا عند البوابة 3')->assertOk()
            ->assertJsonPath('data.places.0.name', 'مطار الملك خالد الدولي')->assertJsonPath('data.places.0.source', 'map')
            ->assertJsonPath('data.understood.category', 'economy')->assertJsonPath('data.understood.passengers', 2)
            ->assertJsonPath('data.understood.payment', 'cash')->assertJsonPath('data.understood.note', 'عند البوابة 3')
            ->assertJsonPath('data.confirm_on_map', true)->assertJsonPath('data.clarify', null);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.mapbox.com/geocoding'));

        // Google switched on instead: the same words search Google.
        RideSetting::current()->update(['map_provider' => 'google', 'google_maps_key' => 'g-key']);
        $this->aiAnswer = '{"destination": "King Khalid Airport"}';
        $say('take me to the airport')->assertOk()->assertJsonPath('data.places.0.address', 'King Khalid International Airport, Riyadh');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'maps.googleapis.com/maps/api/geocode'));

        // "Home" is the rider's own saved address — never a guess; a phone number in the note is dropped.
        Address::query()->create(['user_id' => $this->rider->id, 'type' => 'home', 'title' => 'البيت', 'address_details' => 'حي الياسمين', 'latitude' => 24.82, 'longitude' => 46.64]);
        $this->aiAnswer = '{"destination": null, "saved": "home", "note": "كلمني 0551234567"}';
        $say('روّحني البيت')->assertOk()->assertJsonPath('data.places.0.source', 'saved')
            ->assertJsonPath('data.places.0.lat', 24.82)->assertJsonPath('data.understood.note', null);

        // Vague: one question back, no places.
        $this->aiAnswer = '{"destination": null, "clarify": "تقصد أنهي مول؟"}';
        $say('ودّيني المول')->assertOk()->assertJsonPath('data.clarify', 'تقصد أنهي مول؟')->assertJsonCount(0, 'data.places');

        // A switch like any feature.
        RideFeatureRule::query()->where('feature', 'ai_booking')->update(['enabled' => false]);
        RideFeatures::forget();
        $say('ودّيني المطار')->assertForbidden()->assertJsonPath('data.feature', 'ai_booking');
    }

    public function test_comfort_preferences_go_with_the_ride_and_the_captain_can_say_what_isnt_possible(): void
    {
        $this->switches(['comfort']);
        $this->actingAs($this->rider, 'user_api')->putJson('/api/mobile/v1/rides/comfort', ['ac' => 'freezing'])->assertStatus(422);
        $this->actingAs($this->rider, 'user_api')->putJson('/api/mobile/v1/rides/comfort', ['quiet' => true, 'ac' => 'cold', 'music' => 'none', 'notes' => ['help_with_bags']])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.prefs.ac', 'cold');

        $captain = $this->captain('Omar', '+966550000001', 24.7140, 46.6760);
        $uuid = $this->book($this->quote())->assertCreated()->assertJsonCount(4, 'data.comfort.items')->json('data.uuid');
        $offer = $this->asDriver($captain)->getJson('/api/driver/v1/offers')->assertOk()
            ->assertJsonPath('data.0.comfort.quiet', true)->assertJsonPath('data.0.comfort.ac', 'cold')->json('data.0');
        $this->asDriver($captain)->postJson("/api/driver/v1/offers/{$offer['uuid']}/accept", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        // The captain can't do the cold air conditioning: kept on the ride, the rider told.
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/comfort/unable", ['item' => 'window_open', 'reason' => 'safety'])->assertStatus(422)->assertJsonPath('error_code', 'rides_comfort_item_unknown');
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/comfort/unable", ['item' => 'ac', 'reason' => 'vehicle_issue'])->assertOk()
            ->assertJsonPath('data.comfort.unable.0.item', 'ac');
        $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertOk()
            ->assertJsonPath('data.comfort.unable.0.reason', 'vehicle_issue');
        $this->assertSame(1, RideEvent::query()->where('type', 'comfort_unable')->count());
        $this->cancel($uuid);

        // A ride booked where the switch is off carries nothing.
        RideFeatureRule::query()->where('feature', 'comfort')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->book($this->quote())->assertCreated()->assertJsonPath('data.comfort', null);
    }

    public function test_a_captain_finishing_a_ride_nearby_gets_the_next_trip_and_starts_it_after(): void
    {
        $this->switches(['next_trip']);
        $captain = $this->captain('Omar', '+966550000001', 24.7140, 46.6760);

        // The first ride: accepted, started, and now about 1 km before its end.
        $first = $this->book($this->quote())->assertCreated()->json('data.uuid');
        $this->acceptOnly($captain);
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$first/arrive", self::PICKUP)->assertOk();
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$first/start", self::PICKUP)->assertOk();
        DriverPresence::query()->whereKey($captain->id)->update(['lat' => 24.7420, 'lng' => 46.6950, 'last_seen_at' => now()]);

        // Another rider asks near where it ends: the finishing captain is offered it, as a next trip.
        $other = $this->user('Mona', '+966500000002');
        $second = $this->book($this->quote($other, ['lat' => 24.7510, 'lng' => 46.7010, 'address' => 'Near the end']), [], $other)->assertCreated()->json('data.uuid');
        $offer = $this->asDriver($captain)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.next_trip.after_ride_uuid', $first)->json('data.0');
        $this->assertNotNull($offer['earning_minor'], 'the net is shown before accepting');
        $this->asDriver($captain)->postJson("/api/driver/v1/offers/{$offer['uuid']}/accept", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $ride = Ride::query()->where('uuid', $second)->firstOrFail();
        $this->assertSame(Ride::query()->where('uuid', $first)->value('id'), $ride->after_ride_id);
        $this->assertSame($ride->id, RideActiveLock::query()->whereKey('next:'.$captain->id)->value('ride_id'));
        // The app stays on the ride being driven, with the next one on it; the rider hears the captain is finishing nearby.
        $this->asDriver($captain)->getJson('/api/driver/v1/rides/current')->assertOk()
            ->assertJsonPath('data.uuid', $first)->assertJsonPath('data.next_trip.uuid', $second);
        $this->actingAs($other, 'user_api')->getJson("/api/mobile/v1/rides/$second")->assertOk()->assertJsonPath('data.driver_finishing.finishing', true);
        // No arriving at it before the first one ends.
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$second/arrive", ['lat' => 24.7510, 'lng' => 46.7010])->assertStatus(422)->assertJsonPath('error_code', 'rides_finish_current_first');

        // The first ride ends: the next one becomes the captain's ride.
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$first/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame($ride->id, RideActiveLock::query()->whereKey('driver:'.$captain->id)->value('ride_id'));
        $this->assertNull(RideActiveLock::query()->find('next:'.$captain->id));
        $this->asDriver($captain)->getJson('/api/driver/v1/rides/current')->assertOk()->assertJsonPath('data.uuid', $second);
        $this->actingAs($other, 'user_api')->getJson("/api/mobile/v1/rides/$second")->assertOk()->assertJsonPath('data.driver_finishing', null);
    }

    public function test_a_captain_who_switched_next_trips_off_is_not_offered_one(): void
    {
        $this->switches(['next_trip']);
        $captain = $this->captain('Omar', '+966550000001', 24.7140, 46.6760);
        $this->asDriver($captain)->getJson('/api/driver/v1/preferences')->assertOk()->assertJsonPath('data.next_trips.available', true)->assertJsonPath('data.next_trips.enabled', true);
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['next_trips' => false])->assertOk()->assertJsonPath('data.next_trips.enabled', false);

        $first = $this->book($this->quote())->assertCreated()->json('data.uuid');
        $this->acceptOnly($captain);
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$first/arrive", self::PICKUP)->assertOk();
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$first/start", self::PICKUP)->assertOk();
        DriverPresence::query()->whereKey($captain->id)->update(['lat' => 24.7420, 'lng' => 46.6950, 'last_seen_at' => now()]);

        $other = $this->user('Mona', '+966500000002');
        $this->book($this->quote($other, ['lat' => 24.7510, 'lng' => 46.7010, 'address' => 'Near the end']), [], $other)->assertCreated();
        $this->asDriver($captain)->getJson('/api/driver/v1/offers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_driver_ai_and_the_forecast_need_a_plan_and_the_hot_zones_dont(): void
    {
        $this->switches(['driver_ai']);
        $captain = $this->captain('Omar', '+966550000001', 24.7140, 46.6760);
        $this->asDriver($captain)->putJson('/api/driver/v1/preferences', ['daily_goal_minor' => 20000])->assertOk()->assertJsonPath('data.daily_goal_minor', 20000);
        // A request nobody took nearby makes a hot zone.
        $this->cancel($this->book($this->quote())->assertCreated()->json('data.uuid'));

        $this->asDriver($captain)->getJson('/api/driver/v1/demand?lat=24.7140&lng=46.6760')->assertOk()
            ->assertJsonPath('data.hot_zones.0.requests', 1)->assertJsonPath('data.ai.allowed', false)->assertJsonPath('data.ai.reason', 'no_plan')
            ->assertJsonPath('data.forecast', null);
        $this->asDriver($captain)->getJson('/api/driver/v1/ai/assistant')->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.reason', 'no_plan');
        $this->asDriver($captain)->postJson('/api/driver/v1/ai/ask', ['question' => 'أروح فين؟'])->assertStatus(402)->assertJsonPath('error_code', 'rides_ai_plan_no_plan');
        // The captain can see and buy the same DORR AI plans.
        $this->asDriver($captain)->getJson('/api/driver/v1/ai-subscription/plans')->assertOk();

        $this->trialPlan();
        $cards = $this->asDriver($captain)->getJson('/api/driver/v1/ai/assistant?lat=24.7140&lng=46.6760')->assertOk()->assertJsonPath('data.allowed', true)->json('data.cards');
        $goal = collect($cards)->firstWhere('type', 'goal');
        $this->assertSame(20000, $goal['goal_minor']);
        $this->assertNotEmpty($goal['why']);
        $this->assertArrayHasKey('confidence', $goal);
        $this->asDriver($captain)->getJson('/api/driver/v1/demand?lat=24.7140&lng=46.6760')->assertOk()->assertJsonPath('data.ai.allowed', true)->assertJsonCount(3, 'data.forecast');

        $this->aiAnswer = '{"answer": "خليك في العليا، الطلب عليها عالي.", "confidence": 0.6}';
        $this->asDriver($captain)->postJson('/api/driver/v1/ai/ask', ['question' => 'أروح فين؟'])->assertOk()
            ->assertJsonPath('data.answer', 'خليك في العليا، الطلب عليها عالي.')->assertJsonPath('data.confidence', 0.6);
    }

    public function test_a_booking_whose_captain_may_be_late_warns_the_rider_once(): void
    {
        $this->switches(['scheduled']);
        $at = now()->addMinutes(80)->startOfMinute();
        $uuid = $this->book($this->quote(), ['scheduled_at' => $at->toIso8601String()])->assertCreated()->json('data.uuid');
        $captain = $this->captain('Far', '+966550000001', 24.95, 46.70);
        $this->asDriver($captain)->postJson("/api/driver/v1/scheduled/$uuid/reserve")->assertOk();

        // 40 minutes before, the reserved captain is ~27 km away — not making it at the city speed.
        $this->travel(40)->minutes();
        DriverPresence::query()->whereKey($captain->id)->update(['last_seen_at' => now()]);
        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertTrue(app(LateRisk::class)->check($ride));
        $this->assertFalse(app(LateRisk::class)->check($ride->refresh()), 'warned once');
        $this->assertSame(1, RideEvent::query()->where('type', 'late_risk')->count());
        $late = $this->actingAs($this->rider, 'user_api')->getJson("/api/mobile/v1/rides/$uuid")->assertOk()->json('data.late_risk');
        $this->assertSame('driver_far', $late['reason']);
        $this->assertGreaterThan(5, $late['minutes']);
    }

    public function test_fraud_signs_cases_read_by_dorr_ai_and_the_quality_report_are_for_the_team(): void
    {
        $this->switches(['cases']);
        $captain = $this->captain('Omar', '+966550000001', 24.7140, 46.6760);
        $uuid = $this->book($this->quote())->assertCreated()->json('data.uuid');
        $this->acceptOnly($captain);
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/arrive", self::PICKUP)->assertOk();
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/start", self::PICKUP)->assertOk();
        // A faked position during the trip, and a jump no car can make.
        $batch = DriverLocationBatch::query()->create(['driver_id' => $captain->id, 'batch_uuid' => (string) Str::uuid(), 'points_count' => 2, 'received_at' => now()]);
        DriverLocationLog::query()->create(['driver_id' => $captain->id, 'batch_id' => $batch->id, 'lat' => 24.72, 'lng' => 46.68, 'recorded_at' => now(), 'is_mock' => true]);
        DriverLocationLog::query()->create(['driver_id' => $captain->id, 'batch_id' => $batch->id, 'lat' => 25.20, 'lng' => 47.20, 'recorded_at' => now()->addSeconds(5), 'is_mock' => false]);
        $this->travel(10)->seconds();
        $this->asDriver($captain)->postJson("/api/driver/v1/rides/$uuid/end", ['lat' => self::DROPOFF['lat'], 'lng' => self::DROPOFF['lng']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        $ride = Ride::query()->where('uuid', $uuid)->firstOrFail();
        $codes = array_column((array) $ride->risk_flags, 'code');
        $this->assertContains('mock_location', $codes);
        $this->assertContains('gps_jump', $codes);
        $this->assertGreaterThanOrEqual(70, $ride->risk_score);

        // The rider complains: DORR AI reads it for the team once the rider has their answer.
        $this->aiAnswer = '{"category": "price", "priority": "high", "summary": "الراكب شايف السعر عالي.", "route": "payments", "points": ["المسافة المقطوعة أقل من المخططة"]}';
        $this->actingAs($this->rider, 'user_api')->postJson('/api/mobile/v1/rides/cases', ['kind' => 'complaint', 'ride_uuid' => $uuid, 'category' => 'price', 'summary' => 'The price was much higher than it should be.'])->assertCreated();
        $case = RideCase::query()->latest('id')->firstOrFail();
        $this->assertSame('payments', $case->ai_triage['route']);
        $this->assertSame('high', $case->ai_triage['priority']);
        $this->assertStringNotContainsString('Sara', implode(' ', $this->aiPrompts), 'no names to DORR AI');

        $this->asAdmin(['rides.view', 'ride-cases.view', 'ride-cases.update']);
        $this->getJson('/api/admin/v1/ride-cases/'.$case->id)->assertOk()->assertJsonPath('data.ai_triage.route', 'payments');
        $this->getJson('/api/admin/v1/rides-risk')->assertOk()->assertJsonPath('data.items.0.number', $ride->number)->assertJsonPath('data.items.0.flags.0.code', 'mock_location');
        $this->getJson('/api/admin/v1/rides/'.$ride->id)->assertOk()->assertJsonPath('data.ai.risk_score', $ride->risk_score);
        $this->getJson('/api/admin/v1/rides-quality?days=30')->assertOk()->assertJsonPath('data.completed', 1)->assertJsonPath('data.arrivals.count', 1);
        $this->aiAnswer = '{"summary": "بيانات قليلة.", "causes": ["عدد الرحلات قليل"], "actions": ["راجع بعد أسبوع"]}';
        $this->postJson('/api/admin/v1/rides-quality/explain', ['days' => 30])->assertOk()->assertJsonPath('data.causes.0', 'عدد الرحلات قليل');
    }

    public function test_smart_dispatch_weighs_acceptance_and_fairness_only_when_turned_on_and_smart_price_stays_in_its_room(): void
    {
        $near = $this->captain('Near', '+966550000001', 24.7140, 46.6760);
        $far = $this->captain('Far', '+966550000002', 24.7200, 46.6800);
        $this->captain('Third', '+966550000003', 24.7150, 46.6770);
        DriverRideProfile::query()->create(['driver_id' => $near->id, 'acceptance_bp' => 0, 'last_trip_at' => now()]);
        DriverRideProfile::query()->create(['driver_id' => $far->id, 'acceptance_bp' => 10000, 'last_trip_at' => now()->subHours(3)]);
        $pairs = new Collection([
            [DriverPresence::query()->find($near->id), 100.0],
            [DriverPresence::query()->find($far->id), 900.0],
        ]);

        $this->assertNull(SmartDispatch::costs($pairs, []), 'off: nearest first');
        $costs = SmartDispatch::costs($pairs, ['dispatch.smart' => true, 'dispatch.acceptance_min' => 3, 'dispatch.fairness_min' => 2]);
        $this->assertLessThan($costs[$near->id], $costs[$far->id], 'a farther captain who accepts and waited longer goes first');

        $this->assertSame(['suggested' => 2000, 'adjust_bp' => 0, 'why' => null], SmartPrice::suggest([], 2000, 24.7140, 46.6760));
        // Plenty of free captains and no requests around: down, by half the room at most.
        $quiet = SmartPrice::suggest(['pricing.smart_max_bp' => 2000, 'price.min_fare_minor' => 1500], 2000, 24.7140, 46.6760);
        $this->assertSame(-1000, $quiet['adjust_bp']);
        $this->assertSame(1800, $quiet['suggested']);
    }

    // ------------------------------------------------------------------ helpers

    /** @param  list<string>  $features */
    private function switches(array $features): void
    {
        foreach ($features as $feature) {
            RideFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $this->saudi->id, 'city_id' => null], ['enabled' => true]);
        }
        RideFeatures::forget();
    }

    private function trialPlan(): AiPlan
    {
        return AiPlan::query()->create(['name' => 'Trial', 'code' => 'trial', 'is_trial' => true, 'is_active' => true, 'sort_order' => 1, 'usage_minutes' => 600, 'cooldown_minutes' => 5]);
    }

    private function user(string $name, string $phone): User
    {
        return User::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    /**
     * @param  array<string, mixed>|null  $origin
     * @return array<string, mixed>
     */
    private function quote(?User $rider = null, ?array $origin = null): array
    {
        return $this->actingAs($rider ?? $this->rider, 'user_api')->postJson('/api/mobile/v1/rides/quotes', ['origin' => $origin ?? self::PICKUP, 'destination' => self::DROPOFF])->assertOk()->json('data.0');
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $extra
     */
    private function book(array $quote, array $extra = [], ?User $rider = null): TestResponse
    {
        return $this->actingAs($rider ?? $this->rider, 'user_api')->postJson('/api/mobile/v1/rides', ['quote_uuid' => $quote['uuid'], 'fingerprint' => $quote['fingerprint'], 'payment_method' => 'cash'] + $extra, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function cancel(string $uuid): void
    {
        $this->actingAs($this->rider, 'user_api')->postJson("/api/mobile/v1/rides/{$uuid}/cancel", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    private function acceptOnly(Driver $captain): void
    {
        $offer = $this->asDriver($captain)->getJson('/api/driver/v1/offers')->assertOk()->json('data.0');
        $this->asDriver($captain)->postJson("/api/driver/v1/offers/{$offer['uuid']}/accept", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    private function captain(string $name, string $phone, float $lat, float $lng): Driver
    {
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => $phone, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active,
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        $model = VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id, 'model_id' => $model->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) $this->plate++, 'plate_type' => 1,
            'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);
        DriverPresence::query()->create([
            'driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => $lat, 'lng' => $lng, 'gps_enabled' => true,
            'online_since' => now(), 'last_seen_at' => now(),
        ]);

        return $driver;
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }

    /** @param  list<string>  $permissions */
    private function asAdmin(array $permissions): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }
}
