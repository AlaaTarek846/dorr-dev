<?php

namespace Database\Seeders\Provider;

use App\Enums\Gender;
use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Modules\Provider\Models\Provider;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $provider = Provider::updateOrCreate(
            ['email' => 'provider@example.com'],
            [
                'name' => 'Demo Provider',
                'phone' => '01000000001',
                'phone_code' => '+20',
                'password' => 'password',
                'status' => UserStatus::Active,
                'gender' => Gender::Male,
                'country_id' => Country::where('code', 'EG')->value('id'),
                'email_verified_at' => now(),
            ],
        );

        $categoryIds = ServiceCategory::query()
            ->whereIn('module_name', [
                'passenger_ride',
                'car_rental',
                'restaurants',
            ])
            ->where('status', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $provider->services()->whereNotIn('service_category_id', $categoryIds)->delete();

        foreach ($categoryIds as $categoryId) {
            $provider->services()->firstOrCreate(['service_category_id' => $categoryId]);
        }
    }
}
