<?php

namespace Database\Factories;

use App\Modules\Admin\Models\PlatformRole;
use App\Support\Authorization\PlatformAbilities;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PlatformRole> */
class PlatformRoleFactory extends Factory
{
    protected $model = PlatformRole::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Support agent', 'Content editor', 'Billing manager', 'Compliance', 'Onboarding',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999),
            'description' => fake()->sentence(),
            'abilities' => [PlatformAbilities::SUPPORT],
            'is_system' => false,
        ];
    }

    /** @param  list<string>  $abilities */
    public function granting(array $abilities): static
    {
        return $this->state(fn (): array => ['abilities' => $abilities]);
    }

    /** The system Owner role, as seeded by the migration. */
    public function owner(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Owner',
            'slug' => PlatformRole::OWNER_SLUG,
            'abilities' => PlatformAbilities::all(),
            'is_system' => true,
        ]);
    }
}
