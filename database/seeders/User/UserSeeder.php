<?php

namespace Database\Seeders\User;

use App\Enums\UserStatus;
use Illuminate\Database\Seeder;
use Modules\User\Models\User;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Demo User',
                'phone' => '512345678',
                'password' => 'password',
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );
    }
}
