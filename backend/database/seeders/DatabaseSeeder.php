<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        User::query()->firstOrCreate(
            ['email' => 'admin@ironcore.test'],
            ['name' => 'IronCore Super Admin', 'password' => Hash::make('ChangeMe123!'), 'platform_role' => UserRole::SuperAdmin]
        );

        Gym::query()->firstOrCreate(
            ['slug' => 'forge-fitness'],
            ['name' => 'Forge Fitness', 'base_currency' => Currency::GBP, 'country_code' => 'GB', 'timezone' => 'Europe/London', 'status' => GymStatus::Active]
        );
    }
}
