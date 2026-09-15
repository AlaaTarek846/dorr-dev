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

    public function test_missing_api_route_returns_arabic_json_with_x_locale_header(): void
    {
        $this->withHeaders([
            'Accept' => 'application/json',
            'X-Locale' => 'ar',
        ])->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('message', 'الصفحة أو الرابط غير موجود.');
    }

    public function test_unauthenticated_admin_route_returns_json(): void
    {
        $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'ar',
        ])->get('/api/admin/v1/admins')
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'status' => 'error',
                'code' => 401,
                'message' => 'يجب تسجيل الدخول أولاً.',
            ]);
    }

    public function test_validation_error_returns_json_with_errors(): void
    {
        $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->postJson('/api/admin/v1/login', [])
            ->assertUnprocessable()
            ->assertJson([
                'success' => false,
                'status' => 'error',
                'code' => 422,
                'message' => 'The given data was invalid.',
            ])
            ->assertJsonStructure([
                'errors' => ['email', 'password'],
            ]);
    }
}
