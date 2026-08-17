<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use App\Support\Authorization\PlatformAbilities;
use Database\Factories\PlatformRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named job inside FruitionHR — "Support agent", "Content editor" — and the
 * admin abilities that come with it.
 *
 * Platform-wide, so no BelongsToTenant: these roles describe FruitionHR's own
 * staff, not anyone's employees.
 *
 * @property list<string> $abilities
 */
#[Fillable(['name', 'slug', 'description', 'abilities'])]
class PlatformRole extends Model
{
    /** @use HasFactory<PlatformRoleFactory> */
    use HasFactory;

    protected static string $factory = PlatformRoleFactory::class;

    /** The one role that must always exist and always hold everything. */
    public const OWNER_SLUG = 'owner';

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function administrators(): HasMany
    {
        return $this->hasMany(User::class, 'platform_role_id');
    }

    /**
     * Abilities this role actually grants.
     *
     * Filtered through the catalogue on the way out, so an ability that has
     * been retired stops granting anything the moment it leaves
     * {@see PlatformAbilities} — no migration needed to chase it down.
     *
     * @return list<string>
     */
    public function grantedAbilities(): array
    {
        // The Owner role tracks the catalogue rather than a stored list, so a
        // newly added section is never accidentally locked away from everyone.
        if ($this->is_system && $this->slug === self::OWNER_SLUG) {
            return PlatformAbilities::all();
        }

        return PlatformAbilities::sanitise($this->abilities ?? []);
    }

    public function grants(string $ability): bool
    {
        return in_array($ability, $this->grantedAbilities(), true);
    }

    /** Whether holders of this role can hand out access to others. */
    public function isOwner(): bool
    {
        return $this->grants(PlatformAbilities::ADMINISTRATORS);
    }
}
