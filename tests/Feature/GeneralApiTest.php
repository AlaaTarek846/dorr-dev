<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_countries_dropdown_is_public(): void
    {
        $response = $this->getJson('/api/general/v1/countries/dropdown', [
            'X-Locale' => 'en',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_languages_dropdown_is_public(): void
    {
        $response = $this->getJson('/api/general/v1/languages/dropdown', [
            'X-Locale' => 'en',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_countries_dropdown_exposes_the_fields_the_admin_country_picker_needs(): void
    {
        $flag = Flag::create(['code' => 'eg']);
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'E£', 'status' => true]);

        Country::create([
            'code' => 'eg',
            'name' => 'Egypt',
            'dial_code' => '+20',
            'phone_length' => 10,
            'phone_starts_with' => '1',
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);

        $country = $this->getJson('/api/general/v1/countries/dropdown', [
            'X-Locale' => 'en',
        ])->assertOk()->json('data.0');

        $this->assertNotNull($country['id']);
        $this->assertSame('eg', $country['code']);
        $this->assertSame('+20', $country['dial_code']);
        $this->assertNotNull($country['name']);
        $this->assertSame('eg', $country['flag']['code']);
    }

    public function test_languages_dropdown_only_returns_active_translated_languages(): void
    {
        $flag = Flag::create(['code' => 'gb']);

        Language::create([
            'code' => 'en',
            'name' => 'English',
            'direction' => 'ltr',
            'flag_id' => $flag->id,
            'stores_translation' => true,
            'status' => true,
        ]);

        Language::create([
            'code' => 'fr',
            'name' => 'French',
            'direction' => 'ltr',
            'flag_id' => $flag->id,
            'stores_translation' => false,
            'status' => true,
        ]);

        $languages = $this->getJson('/api/general/v1/languages/dropdown', [
            'X-Locale' => 'en',
        ])->assertOk()->json('data');

        $this->assertCount(1, $languages);
        $this->assertSame('en', $languages[0]['code']);
        $this->assertNotNull($languages[0]['id']);
    }
}
