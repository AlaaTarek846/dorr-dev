<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Models\Address;
use Modules\User\Models\User;
use Tests\TestCase;

class UserAddressesApiTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'phone' => '+966501234567',
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ], $overrides));
    }

    private function bearerHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken];
    }

    private function addressPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'home',
            'title' => 'البيت',
            'building_number' => '12',
            'floor' => '3',
            'address_details' => 'حي النرجس، الرياض',
            'landmark' => 'بجوار المسجد',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
        ], $overrides);
    }

    public function test_index_lists_only_caller_addresses(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser(['phone' => '+966509876543']);

        $user->addresses()->create($this->addressPayload());
        $other->addresses()->create($this->addressPayload(['title' => 'Other']));

        $response = $this->getJson('/api/mobile/v1/addresses', $this->bearerHeaders($user))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('البيت', $response->json('data.0.title'));
    }

    public function test_index_search_filters_by_text(): void
    {
        $user = $this->verifiedUser();
        $user->addresses()->create($this->addressPayload(['title' => 'البيت']));
        $user->addresses()->create($this->addressPayload(['title' => 'العمل', 'type' => 'work']));

        $response = $this->getJson('/api/mobile/v1/addresses?search=البيت', $this->bearerHeaders($user))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('البيت', $response->json('data.0.title'));
    }

    public function test_show_returns_single_address(): void
    {
        $user = $this->verifiedUser();
        $address = $user->addresses()->create($this->addressPayload());

        $this->getJson("/api/mobile/v1/addresses/{$address->id}", $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.id', $address->id)
            ->assertJsonPath('data.type', 'home');
    }

    public function test_show_other_user_address_returns_not_found(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser(['phone' => '+966509876543']);
        $address = $other->addresses()->create($this->addressPayload());

        $this->getJson("/api/mobile/v1/addresses/{$address->id}", $this->bearerHeaders($user))
            ->assertNotFound();
    }

    public function test_store_creates_address_and_pins_default(): void
    {
        $user = $this->verifiedUser();
        $old = $user->addresses()->create($this->addressPayload(['is_default' => true]));

        $this->postJson('/api/mobile/v1/addresses', $this->addressPayload([
            'title' => 'الشاليه',
            'is_default' => true,
        ]), $this->bearerHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.title', 'الشاليه')
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, $user->addresses()->default()->count());
    }

    public function test_store_rejects_invalid_type_and_coordinates(): void
    {
        $user = $this->verifiedUser();

        $this->postJson('/api/mobile/v1/addresses', $this->addressPayload([
            'type' => 'villa',
            'latitude' => 200,
        ]), $this->bearerHeaders($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'latitude']);
    }

    public function test_update_modifies_address_with_put_and_patch(): void
    {
        $user = $this->verifiedUser();
        $address = $user->addresses()->create($this->addressPayload());

        $this->putJson("/api/mobile/v1/addresses/{$address->id}", [
            'type' => 'work',
            'title' => 'المكتب',
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.type', 'work')
            ->assertJsonPath('data.title', 'المكتب');

        $this->patchJson("/api/mobile/v1/addresses/{$address->id}", [
            'floor' => '5',
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.floor', '5');
    }

    public function test_update_other_user_address_returns_not_found(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser(['phone' => '+966509876543']);
        $address = $other->addresses()->create($this->addressPayload());

        $this->putJson("/api/mobile/v1/addresses/{$address->id}", [
            'title' => 'Hacked',
        ], $this->bearerHeaders($user))->assertNotFound();

        $this->assertSame('البيت', $address->fresh()->title);
    }

    public function test_destroy_soft_deletes_address(): void
    {
        $user = $this->verifiedUser();
        $address = $user->addresses()->create($this->addressPayload());

        $this->deleteJson("/api/mobile/v1/addresses/{$address->id}", [], $this->bearerHeaders($user))
            ->assertOk();

        $this->assertSame(0, $user->addresses()->count());
        $this->assertNotNull(Address::withTrashed()->find($address->id));
    }

    public function test_set_default_pins_and_unpins(): void
    {
        $user = $this->verifiedUser();
        $first = $user->addresses()->create($this->addressPayload(['is_default' => true]));
        $second = $user->addresses()->create($this->addressPayload(['title' => 'الشاليه']));

        $this->patchJson("/api/mobile/v1/addresses/{$second->id}/set-default", [
            'is_default' => true,
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, $user->addresses()->default()->count());

        $this->patchJson("/api/mobile/v1/addresses/{$second->id}/set-default", [
            'is_default' => false,
        ], $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.is_default', false);

        $this->assertSame(0, $user->addresses()->default()->count());
    }

    public function test_set_default_requires_boolean_flag(): void
    {
        $user = $this->verifiedUser();
        $address = $user->addresses()->create($this->addressPayload());

        $this->patchJson("/api/mobile/v1/addresses/{$address->id}/set-default", [], $this->bearerHeaders($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_default');
    }

    public function test_index_paginates_with_meta(): void
    {
        $user = $this->verifiedUser();

        for ($i = 1; $i <= 12; $i++) {
            $user->addresses()->create([
                'type' => 'home',
                'title' => "عنوان {$i}",
                'address_details' => 'الرياض',
            ]);
        }

        $first = $this->getJson('/api/mobile/v1/addresses?per_page=10', $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.has_more_pages', true)
            ->assertJsonPath('pagination.total', 12);

        $this->assertCount(10, $first->json('data'));

        $second = $this->getJson('/api/mobile/v1/addresses?per_page=10&page=2', $this->bearerHeaders($user))
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.has_more_pages', false);

        $this->assertCount(2, $second->json('data'));
    }
}
