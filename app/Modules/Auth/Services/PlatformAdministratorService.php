<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformAdministratorService
{
    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<User>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->query()->with('platformRole');

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        [$column, $direction] = $this->sort((string) ($filters['sort'] ?? 'name'));

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    public function find(int $administratorId): User
    {
        return $this->query()->with('platformRole')->findOrFail($administratorId);
    }

    /**
     * @param  array{name: string, email: string, phone?: ?string, timezone?: ?string, password: string}  $data
     * @return array{administrator: User, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $this->assertRoleIsAssignable((int) $data['platform_role_id']);

            $administrator = new User;
            $administrator->forceFill([
                'tenant_id' => null,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'password' => $data['password'],
                'is_super_admin' => true,
                'platform_role_id' => $data['platform_role_id'],
                'status' => User::STATUS_ACTIVE,
                // Trusted provisioning: an existing platform admin vouches for
                // this address, so the new admin skips email verification and
                // can sign in immediately. Email *changes* still re-verify.
                'email_verified_at' => now(),
            ])->save();

            return [
                'administrator' => $administrator->load('platformRole'),
                'before' => [],
                'after' => $this->snapshot($administrator),
            ];
        });
    }

    /**
     * @param  array{name?: string, email?: string, phone?: ?string, timezone?: ?string}  $data
     * @return array{administrator: User, before: array<string, mixed>, after: array<string, mixed>, email_changed: bool}
     */
    public function update(int $administratorId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($administratorId, $data, $actor): array {
            $administrator = $this->lockedAdministrator($administratorId);
            $before = $this->snapshot($administrator);
            $emailChanged = array_key_exists('email', $data)
                && strcasecmp($administrator->email, (string) $data['email']) !== 0;

            $roleId = array_key_exists('platform_role_id', $data) ? (int) $data['platform_role_id'] : null;
            $roleChanged = $roleId !== null && $roleId !== $administrator->platform_role_id;

            if ($roleChanged) {
                // Nobody edits their own access. Not a privilege-escalation
                // guard — you already need the Owner role to be here — but it
                // stops an owner demoting themselves out of the only account
                // that could put them back.
                if ($administrator->is($actor)) {
                    throw ValidationException::withMessages([
                        'platform_role_id' => 'You cannot change your own access. Ask another owner to do it.',
                    ]);
                }

                $this->assertRoleIsAssignable($roleId);

                if ($administrator->isPlatformOwner()) {
                    $this->assertAnotherOwnerRemains($administrator);
                }
            }

            $administrator->fill(Arr::except($data, ['platform_role_id']));

            if ($roleChanged) {
                $administrator->forceFill(['platform_role_id' => $roleId]);
            }

            if ($emailChanged) {
                $administrator->forceFill(['email_verified_at' => null]);
            }

            $administrator->save();
            $administrator = $administrator->refresh()->load('platformRole');

            return [
                'administrator' => $administrator,
                'before' => $before,
                'after' => $this->snapshot($administrator),
                'email_changed' => $emailChanged,
            ];
        });
    }

    /**
     * @return array{administrator: User, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function disable(int $administratorId, User $actor): array
    {
        return DB::transaction(function () use ($administratorId, $actor): array {
            $administrator = $this->lockedAdministrator($administratorId);

            if ($administrator->is($actor)) {
                throw ValidationException::withMessages([
                    'administrator' => 'You cannot disable your own administrator account.',
                ]);
            }

            if ($administrator->status !== User::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => 'Only an active administrator can be disabled.',
                ]);
            }

            $activeIds = $this->query()
                ->where('status', User::STATUS_ACTIVE)
                ->lockForUpdate()
                ->pluck('id');

            if ($activeIds->count() <= 1) {
                throw ValidationException::withMessages([
                    'administrator' => 'At least one active platform administrator must remain.',
                ]);
            }

            // Staff remaining is not enough: without an owner nobody can grant
            // access again, and the platform locks itself out for good.
            if ($administrator->isPlatformOwner()) {
                $this->assertAnotherOwnerRemains($administrator);
            }

            $before = $this->snapshot($administrator);
            $administrator->forceFill(['status' => User::STATUS_DISABLED])->save();

            DB::table('sessions')->where('user_id', $administrator->id)->delete();
            DB::table('password_reset_tokens')->where('email', $administrator->email)->delete();
            $administrator->tokens()->delete();

            $administrator = $administrator->refresh();

            return [
                'administrator' => $administrator,
                'before' => $before,
                'after' => $this->snapshot($administrator),
            ];
        });
    }

    /**
     * @return array{administrator: User, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function activate(int $administratorId): array
    {
        return DB::transaction(function () use ($administratorId): array {
            $administrator = $this->lockedAdministrator($administratorId);

            if ($administrator->status === User::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => 'This administrator is already active.',
                ]);
            }

            $before = $this->snapshot($administrator);
            $administrator->forceFill(['status' => User::STATUS_ACTIVE])->save();
            $administrator = $administrator->refresh();

            return [
                'administrator' => $administrator,
                'before' => $before,
                'after' => $this->snapshot($administrator),
            ];
        });
    }

    private function lockedAdministrator(int $administratorId): User
    {
        return $this->query()->lockForUpdate()->findOrFail($administratorId);
    }

    /** @return Builder<User> */
    private function query(): Builder
    {
        return User::query()
            ->whereNull('tenant_id')
            ->where('is_super_admin', true);
    }

    /** @return array<string, mixed> */
    private function snapshot(User $administrator): array
    {
        return [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'phone' => $administrator->phone,
            'timezone' => $administrator->timezone,
            'status' => $administrator->status,
            'is_email_verified' => $administrator->hasVerifiedEmail(),
            'platform_role' => $administrator->loadMissing('platformRole')
                ->getRelation('platformRole')?->name,
        ];
    }

    /**
     * Checks the role is real and still exists. Guards against a payload
     * pointing at a deleted or bogus id, which would otherwise leave an
     * administrator with no access at all.
     */
    private function assertRoleIsAssignable(int $roleId): void
    {
        if (! PlatformRole::query()->whereKey($roleId)->exists()) {
            throw ValidationException::withMessages([
                'platform_role_id' => 'That access level no longer exists.',
            ]);
        }
    }

    /**
     * Refuses to remove the last active owner.
     *
     * Owners are the only people who can hand out access, so losing the last
     * one is unrecoverable through the product — it would take someone going
     * into the database.
     */
    private function assertAnotherOwnerRemains(User $administrator): void
    {
        $ownerRoleIds = PlatformRole::query()->get()
            ->filter(fn (PlatformRole $role): bool => $role->isOwner())
            ->modelKeys();

        $anotherRemains = $this->query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereIn('platform_role_id', $ownerRoleIds)
            ->whereKeyNot($administrator->getKey())
            ->exists();

        if (! $anotherRemains) {
            throw ValidationException::withMessages([
                'platform_role_id' => 'This is the last owner. Give another administrator the Owner role first.',
            ]);
        }
    }

    /** @return array{string, 'asc'|'desc'} */
    private function sort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        return [$column, $direction];
    }
}
