<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\GymStatus;
use App\Models\Gym;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GymFactory extends Factory
{
    protected $model = Gym::class;

    public function definition(): array
    {
        $name = fake()->company().' Fitness';
        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'base_currency' => fake()->randomElement(Currency::cases()),
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'status' => GymStatus::Active,
            'settings' => [],
        ];
    }
}
