<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-004: the shared API contract — the config both apps read once on open, the `freelance.feature`
 * middleware every later route uses, and the two prefixes (freelancer / client) staying apart.
 */
class FreelanceApiContractTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = $this->country('SA', '+966');
        $this->egypt = $this->country('EG', '+20');
        $this->rule($this->saudi, 'freelance', true);
        $this->rule($this->saudi, 'bidding', true);

        // A route that only the `bidding` switch lets through — what every later task will do.
        Route::middleware(['api', 'auth:freelance_api', 'freelance.feature:bidding'])->get('/api/freelance/v1/_probe', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:user_api', 'freelance.feature:bidding'])->get('/api/mobile/v1/freelance/_probe', fn () => response()->json(['ok' => true]));
    }

    private function country(string $code, string $dial): Country
    {
        return Country::create([
            'code' => $code, 'dial_code' => $dial, 'phone_length' => 9, 'status' => true,
            'flag_id' => Flag::create(['code' => strtolower($code)])->id,
            'currency_id' => Currency::create(['code' => $code.'X', 'symbol' => $code, 'decimal_places' => 2])->id,
        ]);
    }

    private function rule(Country $country, string $feature, bool $on): void
    {
        FreelanceFeatureRule::query()->updateOrCreate(['feature' => $feature, 'country_id' => $country->id], ['enabled' => $on]);
        FreelanceFeatures::forget();
    }

    private function freelancer(Country $country): Freelancer
    {
        return Freelancer::query()->create(['phone' => '+9665'.random_int(10000000, 99999999), 'country_id' => $country->id, 'phone_verified_at' => now()]);
    }

    private function client(Country $country, ?Country $current = null): User
    {
        $user = User::query()->create([
            'name' => 'Sara', 'phone' => '+9665'.random_int(10000000, 99999999), 'country_id' => $country->id,
            'status' => 'active', 'phone_verified_at' => now(),
        ]);
        // Not mass-assignable: the sign-in flow sets it.
        $user->forceFill(['logged_in_country_id' => $current?->id])->save();

        return $user;
    }

    public function test_the_freelancer_reads_the_config_of_their_country(): void
    {
        Sanctum::actingAs($this->freelancer($this->saudi), [], 'freelance_api');

        $this->getJson('/api/freelance/v1/config')->assertOk()
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.role', 'freelancer')
            ->assertJsonPath('data.country_id', $this->saudi->id)
            ->assertJsonPath('data.service', true)
            ->assertJsonPath('data.features.bidding', true)
            ->assertJsonPath('data.features.projects', false)
            ->assertJsonPath('data.terms_version', (string) config('freelance.terms_version'))
            ->assertJsonStructure(['data' => ['min_app_version', 'limits' => ['upload_max_kb', 'avatar_max_kb']]]);
    }

    public function test_the_client_reads_the_config_of_the_country_they_are_in(): void
    {
        // Registered in Egypt (switched off) but signed in from Saudi Arabia: the current country counts.
        Sanctum::actingAs($this->client($this->egypt, $this->saudi), [], 'user_api');
        $this->getJson('/api/mobile/v1/freelance/config')->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.service', true);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client($this->egypt), [], 'user_api');
        $this->getJson('/api/mobile/v1/freelance/config')->assertOk()
            ->assertJsonPath('data.service', false)
            ->assertJsonPath('data.features.bidding', false);
    }

    public function test_the_config_needs_a_signed_in_account_of_the_right_kind(): void
    {
        $this->getJson('/api/freelance/v1/config')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/freelance/config')->assertUnauthorized();

        // A client's token is no key to the freelancer's prefix, nor the other way round.
        Sanctum::actingAs($this->client($this->saudi), [], 'user_api');
        $this->getJson('/api/freelance/v1/config')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->freelancer($this->saudi), [], 'freelance_api');
        $this->getJson('/api/mobile/v1/freelance/config')->assertUnauthorized();
    }

    public function test_a_switched_off_feature_turns_a_route_down_with_its_name(): void
    {
        Sanctum::actingAs($this->freelancer($this->saudi), [], 'freelance_api');
        $this->getJson('/api/freelance/v1/_probe')->assertOk();

        $this->rule($this->saudi, 'bidding', false);
        $this->getJson('/api/freelance/v1/_probe')->assertForbidden()
            ->assertJsonPath('error_code', 'freelance_feature_off')
            ->assertJsonPath('data.feature', 'bidding');

        // The whole service off beats a feature that is on.
        $this->rule($this->saudi, 'bidding', true);
        $this->rule($this->saudi, 'freelance', false);
        $this->getJson('/api/freelance/v1/_probe')->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
    }

    public function test_the_same_switch_guards_the_client_prefix(): void
    {
        Sanctum::actingAs($this->client($this->saudi), [], 'user_api');
        $this->getJson('/api/mobile/v1/freelance/_probe')->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client($this->egypt), [], 'user_api');
        $this->getJson('/api/mobile/v1/freelance/_probe')->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
    }

    public function test_the_envelope_and_the_language_follow_the_contract(): void
    {
        Cache::flush();
        Sanctum::actingAs($this->freelancer($this->saudi), [], 'freelance_api');
        $this->rule($this->saudi, 'freelance', false);

        $this->getJson('/api/freelance/v1/_probe', ['Accept-Language' => 'ar'])->assertForbidden()
            ->assertJsonStructure(['success', 'status', 'code', 'message', 'data', 'error_code'])
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'هذه الخاصية غير متاحة في دولتك حالياً.');
    }
}
