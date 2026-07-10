<?php

namespace Database\Factories;

use App\Modules\Company\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Position> */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'title' => fake()->jobTitle(),
            'code' => Str::upper(fake()->unique()->bothify('POS-###')),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
