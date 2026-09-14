<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiExceptionRendererTest extends TestCase
{
    public function test_missing_api_route_returns_arabic_json(): void
    {
        $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'ar',
        ])->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'status' => 'error',
                'code' => 404,
                'message' => 'الصفحة أو الرابط غير موجود.',
            ]);
    }

    public function test_missing_api_route_returns_english_json(): void
    {
        $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'status' => 'error',
                'code' => 404,
                'message' => 'The requested page or URL was not found.',
            ]);
    }
}
