<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Str;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideFeatureRule;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\Address;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * R-5, R-174: the shortcuts under "where to?" — Home and Work, favourite places and the destinations of
 * recent rides — kept on the account (the same addresses every DORR service uses), with nothing offered
 * where the switch is off.
 */
class RidesSavedPlacesTest extends TestCase
{
    private Country $saudi;

    private User $rider;

    private int $economyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        $this->economyId = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1])->id;
        foreach (['rides', 'saved_places'] as $feature) {
            RideFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        RideFeatures::forget();
        RidePolicies::forget();

        $this->rider = User::query()->create(['name' => 'Sara Ahmed', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    public function test_home_work_favourites_and_recent_destinations_in_one_call(): void
    {
        $this->address($this->rider, 'home', 'Al Malqa, Riyadh', 24.8000, 46.6000);
        $this->address($this->rider, 'work', 'Kingdom Tower', 24.7114, 46.6742);
        $this->address($this->rider, 'other', 'Riyadh Park', 24.7600, 46.6300, 'Mall');
        $this->address($this->rider, 'other', 'Gym', 24.7200, 46.6900, 'Gym');
        // Without a pinned point it can't be a shortcut; someone else's are never shown.
        $this->address($this->rider, 'other', 'Somewhere', null, null, 'No point');
        $this->address(User::query()->create(['name' => 'Other', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]), 'home', 'Not mine', 24.1, 46.1);

        // Rides: the oldest goes to the saved Home (not "recent"), then two to the same place, one cancelled (not a trip), and two more.
        $this->completedRide('Home again', 24.8000, 46.6000);
        $this->completedRide('The airport', 24.9600, 46.7000);
        $this->completedRide('Hospital', 24.6900, 46.7100);
        $this->completedRide('Hospital (gate)', 24.69004, 46.71004);
        $this->completedRide('Cafe', 24.7300, 46.6500);
        $this->completedRide('Never happened', 24.5, 46.5, 'canceled');

        $places = $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/places')->assertOk();
        $places->assertJsonPath('data.home.address', 'Al Malqa, Riyadh')->assertJsonPath('data.home.kind', 'home')
            ->assertJsonPath('data.work.address', 'Kingdom Tower')
            ->assertJsonPath('data.favorites.0.title', 'Gym')->assertJsonPath('data.favorites.1.title', 'Mall');
        $this->assertCount(2, $places->json('data.favorites'));
        $this->assertSame(['Cafe', 'Hospital (gate)', 'The airport'], array_column($places->json('data.recent'), 'address'));
        $this->assertSame('recent', $places->json('data.recent.0.kind'));

        // The limit is the policy's.
        app(RidePolicies::class)->save($this->saudi->id, null, null, null, ['places.recent_max' => 2, 'places.favorites_max' => 1]);
        RidePolicies::forget();
        $limited = $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/places')->assertOk();
        $this->assertCount(2, $limited->json('data.recent'));
        $this->assertCount(1, $limited->json('data.favorites'));
    }

    public function test_nothing_is_offered_without_the_switch_or_any_places(): void
    {
        $this->getJson('/api/mobile/v1/rides/places')->assertUnauthorized();
        $empty = $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/places')->assertOk();
        $this->assertSame(['home' => null, 'work' => null, 'favorites' => [], 'recent' => []], $empty->json('data'));

        $this->address($this->rider, 'home', 'Al Malqa', 24.8, 46.6);
        RideFeatureRule::query()->where('feature', 'saved_places')->update(['enabled' => false]);
        RideFeatures::forget();
        $this->actingAs($this->rider, 'user_api')->getJson('/api/mobile/v1/rides/places')->assertOk()->assertJsonPath('data.home', null);
    }

    private function address(User $user, string $type, string $details, ?float $lat, ?float $lng, ?string $title = null): Address
    {
        return Address::query()->create(['user_id' => $user->id, 'type' => $type, 'title' => $title, 'address_details' => $details, 'latitude' => $lat, 'longitude' => $lng]);
    }

    private function completedRide(string $address, float $lat, float $lng, string $status = 'completed'): Ride
    {
        static $n = 0;
        $n++;

        return Ride::query()->create([
            'uuid' => (string) Str::uuid(), 'number' => 'DR-TEST-'.$n, 'user_id' => $this->rider->id, 'category_id' => $this->economyId,
            'country_id' => $this->saudi->id, 'currency_id' => $this->saudi->currency_id, 'status' => $status, 'pricing_mode' => 'upfront',
            'confirmed_snapshot' => [], 'payment_method' => 'cash', 'payment_status' => 'pending',
            'origin_lat' => 24.7136, 'origin_lng' => 46.6753, 'destination_lat' => $lat, 'destination_lng' => $lng, 'destination_address' => $address,
        ]);
    }
}
