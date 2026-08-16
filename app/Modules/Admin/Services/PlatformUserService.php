<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Auth\Support\MemorablePassword;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform-wide user directory, for support ("this person cannot sign in").
 *
 * User carries no BelongsToTenant trait, so no global scope has to be removed
 * here — a plain query already spans every tenant.
 */
class PlatformUserService
{
    public const TYPE_ADMIN = 'administrator';

    public const TYPE_TENANT = 'tenant';

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<User>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with('tenant:id,name,slug');

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn (Builder $t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['tenant_id'] ?? null) !== null) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        if (($filters['type'] ?? null) === self::TYPE_ADMIN) {
            $query->where('is_super_admin', true);
        } elseif (($filters['type'] ?? null) === self::TYPE_TENANT) {
            $query->where('is_super_admin', false);
        }

        if (array_key_exists('verified', $filters) && $filters['verified'] !== null) {
            $filters['verified']
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at');
        }

        [$column, $direction] = $this->sort((string) ($filters['sort'] ?? 'name'));

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    public function find(int $userId): User
    {
        return User::query()->with('tenant:id,name,slug')->findOrFail($userId);
    }

    /**
     * Support override: mark an address proven so the person can get in
     * without waiting on a code that never arrived.
     *
     * @return array{user: User, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function verifyEmail(int $userId): array
    {
        $user = User::query()->findOrFail($userId);

        if ($user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => 'This email address is already verified.',
            ]);
        }

        $before = $this->snapshot($user);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [
            'user' => $user->refresh()->load('tenant:id,name,slug'),
            'before' => $before,
            'after' => $this->snapshot($user),
        ];
    }

    /**
     * Issue a temporary password and mail it to the account holder.
     *
     * Only ever sent to a *verified* address — mailing credentials to an
     * unproven inbox would hand the account to whoever controls it. Existing
     * sessions and tokens are dropped so the old password cannot keep a live
     * session running.
     *
     * @return array{user: User, password: string}
     */
    public function resetPassword(int $userId, User $actor): array
    {
        return DB::transaction(function () use ($userId, $actor): array {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            if ($user->is($actor)) {
                throw ValidationException::withMessages([
                    'user' => 'Use your own profile settings to change your password.',
                ]);
            }

            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'email' => 'This address is not verified yet, so a password cannot be emailed to it. Verify the address first.',
                ]);
            }

            // Readable on purpose — see MemorablePassword for the trade-off.
            $password = MemorablePassword::generate();

            // The 'hashed' cast on User hashes this on save.
            $user->forceFill(['password' => $password])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->tokens()->delete();

            return ['user' => $user->refresh()->load('tenant:id,name,slug'), 'password' => $password];
        });
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'total' => User::query()->count(),
            'active' => User::query()->where('status', User::STATUS_ACTIVE)->count(),
            'invited' => User::query()->where('status', User::STATUS_INVITED)->count(),
            'unverified' => User::query()->whereNull('email_verified_at')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            'status' => $user->status,
            'is_email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    /** @return array{string, 'asc'|'desc'} */
    private function sort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowed = ['name', 'email', 'status', 'created_at'];

        return [in_array($column, $allowed, true) ? $column : 'name', $direction];
    }
}
