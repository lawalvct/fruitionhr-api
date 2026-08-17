<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
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
            'platform_role_id' => null,
            'status' => User::STATUS_ACTIVE,
            // Nullable profile columns, for the same reason: /me reads every
            // one of them, and a model built by create() only carries the
            // attributes that were actually inserted.
            'phone' => null,
            'timezone' => null,
            'bio' => null,
            'avatar_path' => null,
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

    /** A full owner: the platform administrator with the run of the place. */
    public function platformAdministrator(bool $verified = true): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => null,
            'is_super_admin' => true,
            'platform_role_id' => PlatformRole::query()
                ->where('slug', PlatformRole::OWNER_SLUG)
                ->value('id'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    /** Platform staff limited to whatever the given role grants. */
    public function platformStaff(PlatformRole $role, bool $verified = true): static
    {
        return $this->platformAdministrator($verified)
            ->state(fn (array $attributes) => ['platform_role_id' => $role->id]);
    }
}
