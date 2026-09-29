<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Sms\Models\SmsProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SmsProviderUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_update_works(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::create([
            'name' => 'A', 'email' => 'a@x.com', 'password' => 'secret123', 'status' => 'active',
        ]);
        foreach (['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete', 'test'] as $a) {
            Permission::findOrCreate("sms-providers.$a", 'admin_api');
        }
        $admin->givePermissionTo(['sms-providers.update']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $provider = SmsProvider::create([
            'name' => 'Primary Twilio', 'key' => 'twilio', 'is_active' => true, 'is_available' => true,
        ]);

        $response = $this->putJson('/api/admin/v1/sms-providers/'.$provider->id, [
            'name' => 'Renamed Twilio',
            'key' => 'twilio',
        ]);

        $this->assertSame(200, $response->status(), 'Provider update should return 200');
    }
}
