<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Mirror the migration defaults explicitly. Model::shouldBeStrict()
            // is on outside production, so a factory user missing these throws
            // MissingAttributeException the moment anything reads them (e.g.
            // isSuperAdmin() in the EnsureSuperAdmin middleware).
            'tenant_id' => null,
            'is_super_admin' => false,
            'status' => User::STATUS_ACTIVE,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function platformAdministrator(bool $verified = true): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => null,
            'is_super_admin' => true,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => $verified ? now() : null,
        ]);
    }
}
