<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Discover\Models\DiscoverCity;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideMessage;
use Modules\Rides\Models\WaslLog;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Admin → Rides: switches per country / city, categories, prices & rules with what each scope
 * inherits, the rides with their whole story, the Wasl logs and queue, and the reasons & tags.
 */
class RidesAdminTest extends TestCase
{
    private Country $saudi;

    private DiscoverCity $jeddah;

    private RideCategory $economy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        $this->jeddah = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 21.4858, 'lng' => 39.1925, 'status' => true, 'sort_order' => 1]);
        $this->economy = RideCategory::query()->create(['slug' => 'economy', 'pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1]);
        RideFeatures::forget();
        RidePolicies::forget();
    }

    public function test_switches_turn_the_service_and_a_feature_on_for_a_country_and_off_for_a_city(): void
    {
        $this->asAdmin(['ride-feature-rules.view', 'ride-feature-rules.create', 'ride-feature-rules.update', 'ride-feature-rules.delete']);
        $this->getJson('/api/admin/v1/ride-feature-rules/options')->assertOk()->assertJsonFragment(['category.economy']);

        $this->postJson('/api/admin/v1/ride-feature-rules', ['feature' => 'rides', 'country_id' => $this->saudi->id, 'enabled' => true])->assertCreated();
        $this->postJson('/api/admin/v1/ride-feature-rules', ['feature' => 'cash', 'country_id' => $this->saudi->id, 'enabled' => true])->assertCreated();
        $rule = $this->postJson('/api/admin/v1/ride-feature-rules', ['feature' => 'cash', 'country_id' => $this->saudi->id, 'city_id' => $this->jeddah->id, 'enabled' => false, 'note' => 'trial'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/admin/v1/ride-feature-rules', ['feature' => 'flying', 'country_id' => $this->saudi->id, 'enabled' => true])->assertUnprocessable();

        $features = app(RideFeatures::class);
        $this->assertTrue($features->allows($this->saudi->id, null, 'cash'));
        $this->assertFalse($features->allows($this->saudi->id, $this->jeddah->id, 'cash'));

        $this->putJson("/api/admin/v1/ride-feature-rules/{$rule}", ['enabled' => true])->assertOk();
        $this->assertTrue($features->allows($this->saudi->id, $this->jeddah->id, 'cash'));
        $this->getJson('/api/admin/v1/ride-feature-rules')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_a_city_category_price_overrides_only_what_it_changes(): void
    {
        $this->asAdmin(['ride-policies.view', 'ride-policies.update']);
        $country = ['country_id' => $this->saudi->id];
        $this->putJson('/api/admin/v1/ride-policies', $country + ['data' => ['price.base_minor' => 600, 'price.per_km_minor' => 180]])->assertOk();

        $scope = $country + ['city_id' => $this->jeddah->id, 'category_id' => $this->economy->id];
        $saved = $this->putJson('/api/admin/v1/ride-policies', $scope + ['data' => ['price.per_km_minor' => '200']])->assertOk()->json('data');
        $this->assertSame(['price.per_km_minor' => 200], $saved['own']);
        $this->assertSame(180, $saved['inherited']['price.per_km_minor'], 'what the country gives');
        $this->assertSame(200, $saved['effective']['price.per_km_minor']);
        $this->assertSame(600, $saved['effective']['price.base_minor']);

        $this->putJson('/api/admin/v1/ride-policies', $scope + ['data' => ['price.per_km_minor' => -5]])->assertUnprocessable()->assertJsonPath('error_code', 'rides_invalid_policy');
        $this->getJson('/api/admin/v1/ride-policies/scopes')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_category_is_renamed_and_its_flags_kept(): void
    {
        $this->asAdmin(['ride-categories.view', 'ride-categories.update']);
        $this->putJson("/api/admin/v1/ride-categories/{$this->economy->id}", [
            'name' => ['ar' => 'اقتصادي', 'en' => 'Economy'], 'tagline' => ['ar' => 'رحلات يومية', 'en' => 'Everyday rides'],
            'color' => '#FA7552', 'capacity_min' => 1, 'capacity_max' => 4, 'status' => true,
            'flags' => ['cash_allowed' => false, 'open_destination_allowed' => true], 'eligibility' => ['min_year' => 2015],
        ])->assertOk()->assertJsonPath('data.names.ar', 'اقتصادي')->assertJsonPath('data.flags.cash_allowed', false)->assertJsonPath('data.eligibility.min_year', 2015);
        $this->assertFalse($this->economy->fresh()->flag('cash_allowed', true));
    }

    public function test_a_ride_shows_its_whole_story_and_the_wasl_queue_filters(): void
    {
        $this->asAdmin(['rides.view', 'rides.update', 'wasl-logs.view']);
        $rider = User::query()->create(['name' => 'Sara', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $ride = Ride::query()->create([
            'uuid' => (string) Str::uuid(), 'number' => 'DR-261014-0009', 'user_id' => $rider->id, 'category_id' => $this->economy->id,
            'country_id' => $this->saudi->id, 'currency_id' => $this->saudi->currency_id, 'status' => 'completed', 'booking_mode' => 'instant',
            'pricing_mode' => 'upfront', 'payment_method' => 'cash', 'payment_status' => 'cash_collected', 'confirmed_snapshot' => [],
            'origin_lat' => 21.48, 'origin_lng' => 39.19, 'origin_address' => 'Tahlia', 'destination_lat' => 21.5, 'destination_lng' => 39.2,
            'requested_at' => now(), 'completed_at' => now(), 'final_total_minor' => 2500, 'wasl_status' => 'failed', 'wasl_result_code' => 'CITY_UNMAPPED',
        ]);
        RideMessage::query()->create(['ride_id' => $ride->id, 'sender_type' => 'user', 'sender_id' => $rider->id, 'body' => 'hello', 'created_at' => now()]);
        WaslLog::query()->create(['subject_type' => 'ride', 'subject_id' => $ride->id, 'type' => 'trip', 'success' => false, 'result_code' => 'X', 'created_at' => now()]);

        $this->getJson('/api/admin/v1/rides?search=0009')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rider.name', 'Sara');
        $this->getJson('/api/admin/v1/rides?wasl_status=failed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/v1/rides?wasl_status=sent')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/v1/rides/{$ride->id}")->assertOk()
            ->assertJsonPath('data.messages.0.body', 'hello')
            ->assertJsonPath('data.wasl.result_code', 'CITY_UNMAPPED')
            ->assertJsonPath('data.wasl.logs.0.result_code', 'X');
        $this->getJson('/api/admin/v1/wasl-logs?type=trip&success=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'DR-261014-0009');
    }

    public function test_cancel_reasons_and_rating_tags_are_managed_in_both_languages(): void
    {
        $this->asAdmin(['ride-lists.view', 'ride-lists.create', 'ride-lists.update', 'ride-lists.delete']);
        $reason = $this->postJson('/api/admin/v1/ride-lists/cancel-reasons', ['label' => ['ar' => 'تأخر الكابتن', 'en' => 'Captain is late'], 'status' => true, 'actor' => 'rider'])
            ->assertCreated()->assertJsonPath('data.labels.en', 'Captain is late')->json('data.id');
        $this->postJson('/api/admin/v1/ride-lists/rating-tags', ['label' => ['ar' => 'سيارة نظيفة', 'en' => 'Clean car'], 'status' => true, 'target' => 'driver', 'sentiment' => 'positive'])->assertCreated();
        $this->putJson("/api/admin/v1/ride-lists/cancel-reasons/{$reason}", ['label' => ['ar' => 'تأخر', 'en' => 'Late'], 'status' => false, 'actor' => 'both'])->assertOk()->assertJsonPath('data.actor', 'both');
        $this->getJson('/api/admin/v1/ride-lists/rating-tags')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/admin/v1/ride-lists/cancel-reasons/{$reason}")->assertOk();
        $this->getJson('/api/admin/v1/ride-lists/other')->assertNotFound();
    }

    public function test_every_screen_needs_its_permission(): void
    {
        $this->asAdmin([]);
        foreach (['ride-feature-rules', 'ride-categories', 'ride-policies/options', 'rides', 'wasl-logs', 'ride-lists/cancel-reasons'] as $path) {
            $this->getJson("/api/admin/v1/{$path}")->assertForbidden();
        }
    }

    /** @param list<string> $permissions */
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
