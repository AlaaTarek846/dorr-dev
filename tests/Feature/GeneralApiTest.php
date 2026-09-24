<?php

namespace Tests\Feature;

use Tests\TestCase;

class GeneralApiTest extends TestCase
{
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
}
