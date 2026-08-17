<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformRole;
use App\Support\Authorization\PlatformAbilities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating and editing the named jobs platform staff can be given.
 *
 * The Owner role is structural rather than editorial: it must keep every
 * ability and must keep existing, because it is the only role that can hand
 * out access. Everything here protects that.
 */
class PlatformRoleService
{
    /** @return Collection<int, PlatformRole> */
    public function all(): Collection
    {
        return PlatformRole::query()
            ->withCount('administrators')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, description?: ?string, abilities: array<int, string>}  $data
     */
    public function create(array $data): PlatformRole
    {
        return DB::transaction(function () use ($data): PlatformRole {
            $abilities = PlatformAbilities::sanitise($data['abilities']);
            $this->assertGrantsSomething($abilities);

            return PlatformRole::query()->create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'abilities' => $abilities,
                'is_system' => false,
            ]);
        });
    }

    /**
     * @param  array{name?: string, description?: ?string, abilities?: array<int, string>}  $data
     */
    public function update(int $roleId, array $data): PlatformRole
    {
        return DB::transaction(function () use ($roleId, $data): PlatformRole {
            $role = PlatformRole::query()->lockForUpdate()->findOrFail($roleId);

            if ($role->is_system) {
                throw ValidationException::withMessages([
                    'role' => "The {$role->name} role is built in and cannot be edited.",
                ]);
            }

            if (array_key_exists('abilities', $data)) {
                $abilities = PlatformAbilities::sanitise($data['abilities']);
                $this->assertGrantsSomething($abilities);
                $role->abilities = $abilities;
            }

            if (array_key_exists('name', $data) && $data['name'] !== $role->name) {
                $role->name = $data['name'];
                $role->slug = $this->uniqueSlug($data['name'], $role->id);
            }

            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }

            $role->save();

            return $role->refresh();
        });
    }

    public function delete(int $roleId): PlatformRole
    {
        return DB::transaction(function () use ($roleId): PlatformRole {
            $role = PlatformRole::query()->lockForUpdate()->findOrFail($roleId);

            if ($role->is_system) {
                throw ValidationException::withMessages([
                    'role' => "The {$role->name} role is built in and cannot be deleted.",
                ]);
            }

            // The database refuses this too (restrictOnDelete), but a foreign
            // key violation is a 500; the administrator deserves a sentence
            // telling them what to do about it.
            $holders = $role->administrators()->count();
            if ($holders > 0) {
                throw ValidationException::withMessages([
                    'role' => $holders === 1
                        ? 'One administrator still has this role. Move them to another one first.'
                        : "{$holders} administrators still have this role. Move them to another one first.",
                ]);
            }

            $role->delete();

            return $role;
        });
    }

    /**
     * A role granting nothing is a trap: it looks like access in the list and
     * behaves like a disabled account for whoever holds it.
     *
     * @param  list<string>  $abilities
     */
    private function assertGrantsSomething(array $abilities): void
    {
        if ($abilities === []) {
            throw ValidationException::withMessages([
                'abilities' => 'Choose at least one section this role can reach.',
            ]);
        }
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $suffix = 2;

        while (
            PlatformRole::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
