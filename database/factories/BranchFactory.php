<?php

namespace Database\Factories;

use App\Modules\Company\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        $name = fake()->city().' Branch';

        return [
            'name' => $name,
            'code' => Str::upper(fake()->unique()->bothify('BR-###')),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'is_active' => true,
        ];
    }
}
