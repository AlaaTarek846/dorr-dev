<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\User\Models\Address;
use Modules\User\Models\User;
use Tests\TestCase;

class UserAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_addresses_table_has_expected_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('addresses'));

        foreach (['id', 'user_id', 'type', 'title', 'building_number', 'floor', 'address_details', 'landmark', 'latitude', 'longitude', 'is_default', 'deleted_at', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('addresses', $column), "Missing column: {$column}");
        }

        $indexes = collect(Schema::getIndexes('addresses'))->map(fn ($index) => $index['columns'])->all();
        $this->assertContains(['user_id'], $indexes);
        $this->assertContains(['user_id', 'is_default'], $indexes);
    }

    public function test_user_has_many_addresses_and_default_scope_resolves(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $user->addresses()->create([
            'type' => 'work',
            'address_details' => 'طريق الملك فهد، العليا، الرياض',
            'is_default' => false,
        ]);
        $default = $user->addresses()->create([
            'type' => 'home',
            'title' => 'البيت',
            'address_details' => 'حي النرجس، الرياض',
            'latitude' => '24.7136000',
            'longitude' => '46.6753000',
            'is_default' => true,
        ]);

        $this->assertSame(2, $user->addresses()->count());
        $this->assertTrue($user->addresses()->default()->first()->is($default));
        $this->assertSame('البيت', $user->addresses()->default()->first()->title);
    }

    public function test_soft_deleted_addresses_are_excluded_from_default_scope(): void
    {
        $user = User::query()->create([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);

        $address = $user->addresses()->create([
            'type' => 'home',
            'address_details' => 'حي النرجس، الرياض',
            'is_default' => true,
        ]);
        $address->delete();

        $this->assertNull($user->addresses()->default()->first());
        $this->assertNotNull(Address::withTrashed()->find($address->id));
    }
}
