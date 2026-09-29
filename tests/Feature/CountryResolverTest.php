<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Services\General\CountryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Modules\User\Models\User;
use Tests\TestCase;

class CountryResolverTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_IP = '156.204.10.10';

    private CountryResolver $resolver;

    private Country $saudi;

    private Country $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(CountryResolver::class);

        // The IP helper reads the app's current request; a loopback address is never looked up.
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => self::PUBLIC_IP]));

        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        $flagSa = Flag::create(['code' => 'sa']);
        $flagEg = Flag::create(['code' => 'eg']);

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flagSa->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
        $this->egypt = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => false, 'flag_id' => $flagEg->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
    }

    public function test_explicit_header_wins_over_everything_else(): void
    {
        $user = User::create(['name' => 'U', 'phone' => '966500000001', 'country_id' => $this->saudi->id, 'status' => 'active']);
        Auth::guard('user_api')->setUser($user);

        $request = Request::create('/', 'GET', server: ['HTTP_X_COUNTRY' => 'EG']);

        $this->assertSame($this->egypt->id, $this->resolver->resolve($request)->id);
    }

    public function test_authenticated_profile_wins_over_ip_when_no_explicit_choice(): void
    {
        $user = User::create(['name' => 'U', 'phone' => '966500000002', 'country_id' => $this->egypt->id, 'status' => 'active']);
        Auth::guard('user_api')->setUser($user);

        Http::fake(['*ipwho.is*' => Http::response(['country_code' => 'SA'])]);

        $this->assertSame($this->egypt->id, $this->resolver->resolve(Request::create('/'))->id);
    }

    public function test_falls_back_to_ip_lookup_when_not_authenticated(): void
    {
        Http::fake(['*ipwho.is*' => Http::response(['country_code' => 'EG'])]);

        $this->assertSame($this->egypt->id, $this->resolver->resolve(Request::create('/'))->id);
    }

    public function test_falls_back_to_default_country_when_the_ip_provider_is_completely_unreachable(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('simulated outage')]);

        // Must not throw a 500 — resolves to is_default instead.
        $resolved = $this->resolver->resolve(Request::create('/'));

        $this->assertSame($this->saudi->id, $resolved->id);
        $this->assertTrue($resolved->is_default);
    }

    public function test_explicit_choice_for_an_unknown_or_inactive_code_is_ignored_not_fatal(): void
    {
        Http::fake(['*ipwho.is*' => Http::response(['country_code' => 'EG'])]);

        $request = Request::create('/', 'GET', server: ['HTTP_X_COUNTRY' => 'ZZ']);

        // Falls through to the next step (IP) rather than erroring on a bad header.
        $this->assertSame($this->egypt->id, $this->resolver->resolve($request)->id);
    }

    public function test_the_country_middleware_exposes_the_result_via_the_current_country_helper(): void
    {
        Route::middleware('country')->get('/__test/country', fn () => response()->json(['code' => currentCountry()?->code]));

        $response = $this->getJson('/__test/country', ['X-Country' => 'EG']);

        $response->assertOk()->assertJson(['code' => 'EG']);
    }

    public function test_second_provider_is_used_when_the_first_one_fails(): void
    {
        Http::fake([
            '*ipwho.is*' => Http::response([], 500),
            '*ip-api.com*' => Http::response(['status' => 'success', 'countryCode' => 'EG']),
        ]);

        $this->assertSame('EG', getCountryCodeByIp());
    }

    public function test_cloudflare_country_header_is_used_without_any_lookup(): void
    {
        Http::fake();
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => self::PUBLIC_IP, 'HTTP_CF_IPCOUNTRY' => 'EG']));

        $this->assertSame('EG', getCountryCodeByIp());
        Http::assertNothingSent();
    }

    public function test_a_loopback_address_gets_the_default_without_a_lookup(): void
    {
        Http::fake();
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']));

        $this->assertSame('SA', getCountryCodeByIp());
        Http::assertNothingSent();
    }

    public function test_a_detected_but_inactive_country_answers_the_default_until_it_is_switched_on(): void
    {
        $this->egypt->update(['status' => false]);
        Http::fake(['*ipwho.is*' => Http::response(['country_code' => 'EG'])]);

        $this->assertSame('SA', getCountryCodeByIp());

        // The detection is cached, but "active" is checked each time: switching Egypt on works at once.
        $this->egypt->update(['status' => true]);
        $this->assertSame('EG', getCountryCodeByIp());
        Http::assertSentCount(1);
    }
}
