<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldCountryMode;
use Modules\AI\Models\World\AiWorldLocation;
use Modules\AI\Models\World\AiWorldSetting;
use Modules\AI\Services\World\Contracts\LocationProviderInterface;
use Modules\AI\Services\World\Location\AiWorldLocationEngine;
use Modules\User\Models\User;
use Tests\TestCase;

/** DORR AI WORLD Phase 1: place search, fidelity tiers, caching, availability. */
class AiWorldLocationTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
        app()->instance('resolved_country', $this->country);
    }

    private function user(): User
    {
        $user = User::query()->create([
            'name' => 'World User', 'email' => 'world-'.uniqid().'@example.test',
            'password' => bcrypt('not-a-real-password'), 'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($user, ['*'], 'user_api');

        return $user;
    }

    /** A maps provider that counts its calls and returns whatever the test gives it. */
    private function fakeProvider(array $rows, bool $configured = true, bool $fails = false): object
    {
        $fake = new class($rows, $configured, $fails) implements LocationProviderInterface
        {
            public int $calls = 0;

            public function __construct(public array $rows, public bool $configured, public bool $fails) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function search(string $query, string $language = 'en', int $limit = 8): array
            {
                $this->calls++;

                if ($this->fails) {
                    throw new AiWorldException('location_provider_failed', 503);
                }

                return $this->rows;
            }
        };

        app()->instance(LocationProviderInterface::class, $fake);

        return $fake;
    }

    private function row(string $id = 'ChIJ-street-1', string $name = 'Nile Corniche', string $kind = 'street'): array
    {
        return [
            'provider_place_id' => $id, 'name' => $name, 'kind' => $kind, 'country_code' => 'EG', 'city' => 'Cairo',
            'lat' => 30.0444, 'lng' => 31.2357, 'formatted_address' => $name.', Cairo, Egypt',
        ];
    }

    public function test_the_landmark_catalog_answers_in_english_and_arabic_without_any_maps_key(): void
    {
        $this->fakeProvider([], configured: false);
        $engine = app(AiWorldLocationEngine::class);

        $english = $engine->search('eiffel');
        $arabic = $engine->search('برج ايفل');

        $this->assertSame('Eiffel Tower', $english->first()->name_en);
        $this->assertSame('Eiffel Tower', $arabic->first()->name_en);
        $this->assertSame(AiWorldLocation::TIER_REFERENCE_BASED, $english->first()->fidelity_tier);
        $this->assertGreaterThanOrEqual(40, AiWorldLocation::query()->where('is_curated', true)->count());
    }

    public function test_provider_places_are_cinematic_stored_and_never_searched_twice(): void
    {
        $fake = $this->fakeProvider([$this->row()]);
        $engine = app(AiWorldLocationEngine::class);

        $first = $engine->search('corniche street cairo', 'en');
        $second = $engine->search('corniche street cairo', 'en');

        $this->assertSame(1, $fake->calls);
        $this->assertSame('Nile Corniche', $first->first()->name_en);
        $this->assertSame(AiWorldLocation::TIER_CINEMATIC, $first->first()->fidelity_tier);
        $this->assertFalse($first->first()->is_curated);
        $this->assertSame(1, AiWorldLocation::query()->where('source', 'google')->count());
        $this->assertSame($first->first()->id, $second->first()->id);
    }

    public function test_interactive_3d_needs_both_the_data_and_the_feature_switch(): void
    {
        $this->fakeProvider([$this->row('ChIJ-3d', 'Tahrir Square', 'landmark')]);
        $location = app(AiWorldLocationEngine::class)->search('tahrir square')->first();
        $location->forceFill(['tiles_available' => true])->save();
        $classifier = app(\Modules\AI\Services\World\Location\AiWorldFidelityClassifier::class);

        config(['ai.world.tiles_enabled' => false]);
        $this->assertSame(AiWorldLocation::TIER_CINEMATIC, $classifier->classify($location->fresh())['tier']);

        config(['ai.world.tiles_enabled' => true]);
        $this->assertSame(AiWorldLocation::TIER_INTERACTIVE_3D, $classifier->classify($location->fresh())['tier']);

        $location->forceFill(['tiles_available' => false])->save();
        $this->assertSame(AiWorldLocation::TIER_CINEMATIC, $classifier->classify($location->fresh())['tier']);
    }

    public function test_a_failing_provider_still_returns_catalog_matches_but_errors_when_nothing_matches(): void
    {
        $this->fakeProvider([], fails: true);
        $engine = app(AiWorldLocationEngine::class);

        $this->assertSame('Colosseum', $engine->search('colosseum')->first()->name_en);

        try {
            $engine->search('zzqxunknownplace');
            $this->fail('expected the provider failure');
        } catch (AiWorldException $e) {
            $this->assertSame('location_provider_failed', $e->reason);
            $this->assertSame(503, $e->status);
        }
    }

    public function test_search_api_returns_the_fidelity_the_user_should_expect(): void
    {
        $this->fakeProvider([], configured: false);
        $this->user();

        $response = $this->getJson('/api/user/v1/ai-world/locations/search?q=pyramids', ['Accept-Language' => 'en']);

        $response->assertOk();
        $first = $response->json('data.0');
        $this->assertSame('Giza Pyramids', $first['name_en']);
        $this->assertSame('reference_based', $first['fidelity']['tier']);
        $this->assertNotSame('', $first['fidelity']['description']);
        $this->assertEqualsWithDelta(29.98, $first['lat'], 0.05);
    }

    public function test_search_api_rejects_a_too_short_query_and_anonymous_callers(): void
    {
        $this->fakeProvider([], configured: false);

        $this->getJson('/api/user/v1/ai-world/locations/search?q=pyramids')->assertUnauthorized();

        $this->user();
        $this->getJson('/api/user/v1/ai-world/locations/search?q=a')->assertStatus(422)->assertJsonPath('code', 422);
    }

    public function test_the_service_can_be_switched_off_and_modes_follow_the_country(): void
    {
        $this->fakeProvider([], configured: false);
        $this->user();

        $info = $this->getJson('/api/user/v1/ai-world/info')->assertOk();
        $this->assertTrue($info->json('data.enabled'));
        $this->assertTrue($info->json('data.modes.single'));
        $this->assertFalse($info->json('data.modes.ar_portal'));
        $this->assertSame([4, 6, 8], $info->json('data.limits.scene_seconds'));
        $this->assertCount(3, $info->json('data.fidelity_levels'));

        AiWorldCountryMode::query()->create(['country_id' => $this->country->id, 'mode' => 'multi', 'is_enabled' => false]);
        $this->assertFalse($this->getJson('/api/user/v1/ai-world/info')->json('data.modes.multi'));

        AiWorldSetting::current()->update(['is_enabled' => false]);
        $off = $this->getJson('/api/user/v1/ai-world/info');
        $this->assertFalse($off->json('data.enabled'));
        $this->assertFalse($off->json('data.modes.single'));
        $this->getJson('/api/user/v1/ai-world/locations/search?q=eiffel')->assertForbidden();
    }

    public function test_provider_app_gets_the_same_service(): void
    {
        $uris = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());

        foreach (['info', 'locations/search', 'locations/{id}'] as $path) {
            $this->assertTrue($uris->contains('api/provider/v1/ai-world/'.$path), $path);
            $this->assertTrue($uris->contains('api/user/v1/ai-world/'.$path), $path);
        }
    }

    public function test_google_places_provider_maps_the_response_and_never_leaks_the_key(): void
    {
        config(['ai.world.google_maps_key' => 'maps-test-not-real']);
        \Illuminate\Support\Facades\Http::fake(['places.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['places' => [[
            'id' => 'ChIJabc', 'displayName' => ['text' => 'Eiffel Tower'], 'formattedAddress' => 'Champ de Mars, Paris',
            'location' => ['latitude' => 48.8584, 'longitude' => 2.2945], 'types' => ['tourist_attraction'],
            'addressComponents' => [
                ['longText' => 'France', 'shortText' => 'FR', 'types' => ['country']],
                ['longText' => 'Paris', 'shortText' => 'Paris', 'types' => ['locality']],
            ],
        ], ['id' => '', 'location' => ['latitude' => 1, 'longitude' => 1]]]], 200)]);

        $rows = (new \Modules\AI\Services\World\Location\GooglePlacesProvider)->search('eiffel', 'en', 5);

        $this->assertCount(1, $rows);
        $this->assertSame(['ChIJabc', 'Eiffel Tower', 'landmark', 'FR', 'Paris'], [$rows[0]['provider_place_id'], $rows[0]['name'], $rows[0]['kind'], $rows[0]['country_code'], $rows[0]['city']]);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->header('X-Goog-Api-Key')[0] === 'maps-test-not-real' && $r['pageSize'] === 5 && ! str_contains($r->url(), 'maps-test-not-real'));
    }

    public function test_google_places_provider_turns_an_http_error_into_a_friendly_failure(): void
    {
        config(['ai.world.google_maps_key' => 'maps-test-not-real']);
        \Illuminate\Support\Facades\Http::fake(['places.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['error' => ['message' => 'key restricted: project 123']], 403)]);

        try {
            (new \Modules\AI\Services\World\Location\GooglePlacesProvider)->search('eiffel');
            $this->fail('expected a failure');
        } catch (AiWorldException $e) {
            $this->assertSame('location_provider_failed', $e->reason);
            $this->assertStringNotContainsString('project 123', $e->apiMessage());
        }
    }
}
