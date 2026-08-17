<?php

namespace App\Models;

use App\Modules\Admin\Models\PlatformRole;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Authorization\PlatformAbilities;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['tenant_id', 'name', 'email', 'phone', 'avatar_path', 'timezone', 'bio', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, MustVerifyEmail, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITED = 'invited';

    public const STATUS_DISABLED = 'disabled';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    /** @return BelongsTo<PlatformRole, $this> */
    public function platformRole(): BelongsTo
    {
        return $this->belongsTo(PlatformRole::class);
    }

    /**
     * What this administrator may reach in the admin surface.
     *
     * Platform staff without a role get nothing rather than everything: an
     * administrator whose role was never set should be inert, not omnipotent.
     *
     * @return list<string>
     */
    public function platformAbilities(): array
    {
        if (! $this->isSuperAdmin()) {
            return [];
        }

        // loadMissing rather than a bare ->platformRole: lazy loading is
        // disabled outside production and this runs from middleware on every
        // admin request, where nothing has eager loaded the relation yet.
        return $this->loadMissing('platformRole')
            ->getRelation('platformRole')
            ?->grantedAbilities() ?? [];
    }

    public function hasPlatformAbility(string $ability): bool
    {
        return in_array($ability, $this->platformAbilities(), true);
    }

    /** Whether this administrator can hand out platform access to others. */
    public function isPlatformOwner(): bool
    {
        return $this->hasPlatformAbility(PlatformAbilities::ADMINISTRATORS);
    }
}
